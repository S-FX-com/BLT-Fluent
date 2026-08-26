<?php
/**
 * Configuration read/write.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the single wp_option this plugin stores.
 *
 * The option holds configuration only. No member data is ever written here --
 * that lives in FluentCRM.
 */
class Settings {

	/**
	 * Config schema version. Bump when the shape changes, and add a migration.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Per-request cache of the decoded option.
	 *
	 * @var array|null
	 */
	private $config = null;

	/**
	 * The configuration shipped on first activation.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'version'             => self::SCHEMA_VERSION,
			'delete_on_uninstall' => false,
			'debug'               => false,
			'skip_renewals'       => true,
			'prefill'             => true,
			'field_sets'          => array(
				'default' => array(
					'label'       => __( 'Default', 'blt-fluent' ),
					'title'       => __( 'Your profile', 'blt-fluent' ),
					'description' => '',
					'fields'      => array(),
				),
			),
			'product_map'         => array(),
		);
	}

	/**
	 * Seed defaults only when the option does not exist yet.
	 *
	 * add_option() is a no-op when the key is present, so an existing
	 * configuration cannot be clobbered even if this runs repeatedly.
	 *
	 * @return void
	 */
	public static function maybe_seed_defaults() {
		add_option( BLT_FLUENT_OPTION, self::defaults() );
	}

	/**
	 * The full configuration, with defaults filled in for missing keys.
	 *
	 * @return array
	 */
	public function get() {
		if ( null !== $this->config ) {
			return $this->config;
		}

		$stored = get_option( BLT_FLUENT_OPTION, array() );

		// Tolerate a JSON string, in case the option was written by hand or by
		// an older build that encoded it.
		if ( is_string( $stored ) ) {
			$decoded = json_decode( $stored, true );
			$stored  = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->config = $this->normalize( $stored );

		return $this->config;
	}

	/**
	 * Persist configuration.
	 *
	 * @param array $config Configuration to store.
	 * @return bool
	 */
	public function save( array $config ) {
		$config       = $this->normalize( $config );
		$this->config = $config;

		return update_option( BLT_FLUENT_OPTION, $config );
	}

	/**
	 * Apply schema migrations, if any are pending.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		$config  = $this->get();
		$version = isset( $config['version'] ) ? (int) $config['version'] : 0;

		if ( $version === self::SCHEMA_VERSION ) {
			return;
		}

		/**
		 * Future migrations belong here, guarded by the stored version, e.g.
		 *
		 *     if ( $version < 2 ) { ...reshape $config...; }
		 *
		 * normalize() already fills in newly added keys with their defaults,
		 * so purely additive changes need no migration step.
		 */
		$config['version'] = self::SCHEMA_VERSION;

		$this->save( $config );
	}

	/**
	 * Coerce stored configuration into the expected shape.
	 *
	 * @param array $config Raw configuration.
	 * @return array
	 */
	private function normalize( array $config ) {
		$defaults = self::defaults();
		$config   = array_merge( $defaults, $config );

		$config['version']             = (int) $config['version'];
		$config['delete_on_uninstall'] = ! empty( $config['delete_on_uninstall'] );
		$config['debug']               = ! empty( $config['debug'] );
		$config['skip_renewals']       = ! empty( $config['skip_renewals'] );
		$config['prefill']             = ! empty( $config['prefill'] );

		if ( ! is_array( $config['field_sets'] ) || empty( $config['field_sets'] ) ) {
			$config['field_sets'] = $defaults['field_sets'];
		}

		$field_sets = array();

		foreach ( $config['field_sets'] as $key => $set ) {
			$key = sanitize_key( $key );

			if ( '' === $key || ! is_array( $set ) ) {
				continue;
			}

			$fields = array();

			if ( ! empty( $set['fields'] ) && is_array( $set['fields'] ) ) {
				foreach ( $set['fields'] as $field ) {
					if ( ! is_array( $field ) || empty( $field['slug'] ) ) {
						continue;
					}

					$slug = sanitize_key( $field['slug'] );

					if ( '' === $slug || isset( $fields[ $slug ] ) ) {
						continue;
					}

					$fields[ $slug ] = array(
						'slug'        => $slug,
						'required'    => ! empty( $field['required'] ),
						'label'       => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
						'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
						'help'        => isset( $field['help'] ) ? sanitize_text_field( $field['help'] ) : '',
					);
				}
			}

			$field_sets[ $key ] = array(
				'label'       => isset( $set['label'] ) ? sanitize_text_field( $set['label'] ) : $key,
				'title'       => isset( $set['title'] ) ? sanitize_text_field( $set['title'] ) : '',
				'description' => isset( $set['description'] ) ? sanitize_text_field( $set['description'] ) : '',
				'fields'      => array_values( $fields ),
			);
		}

		if ( empty( $field_sets ) ) {
			$field_sets = $defaults['field_sets'];
		}

		$config['field_sets'] = $field_sets;

		$product_map = array();

		if ( is_array( $config['product_map'] ) ) {
			foreach ( $config['product_map'] as $product_key => $set_key ) {
				$product_key = self::normalize_product_key( $product_key );
				$set_key     = sanitize_key( $set_key );

				if ( '' === $product_key || ! isset( $field_sets[ $set_key ] ) ) {
					continue;
				}

				$product_map[ $product_key ] = $set_key;
			}
		}

		$config['product_map'] = $product_map;

		return $config;
	}

	/**
	 * Normalize a product map key.
	 *
	 * Accepts "123" (product) and "123:45" (product:variation).
	 *
	 * @param string|int $key Raw key.
	 * @return string Empty string when the key is unusable.
	 */
	public static function normalize_product_key( $key ) {
		$key = trim( (string) $key );

		if ( ! preg_match( '/^(\d+)(?::(\d+))?$/', $key, $matches ) ) {
			return '';
		}

		return isset( $matches[2] ) ? $matches[1] . ':' . $matches[2] : $matches[1];
	}

	/**
	 * All configured field sets, keyed by set key.
	 *
	 * @return array[]
	 */
	public function field_sets() {
		$config = $this->get();

		return $config['field_sets'];
	}

	/**
	 * A single field set.
	 *
	 * @param string $key Field set key.
	 * @return array|null
	 */
	public function field_set( $key ) {
		$sets = $this->field_sets();
		$key  = sanitize_key( $key );

		return isset( $sets[ $key ] ) ? $sets[ $key ] : null;
	}

	/**
	 * Product ID (optionally product:variation) to field set key.
	 *
	 * @return array<string,string>
	 */
	public function product_map() {
		$config = $this->get();

		return $config['product_map'];
	}

	/**
	 * The field set key configured for a product, if any.
	 *
	 * A variation-specific mapping wins over the product-wide one.
	 *
	 * @param int|string $product_id   Product ID.
	 * @param int|string $variation_id Optional variation ID.
	 * @return string Empty string when the product has no field set.
	 */
	public function field_set_key_for_product( $product_id, $variation_id = 0 ) {
		$map = $this->product_map();

		if ( $variation_id ) {
			$specific = self::normalize_product_key( $product_id . ':' . $variation_id );

			if ( '' !== $specific && isset( $map[ $specific ] ) ) {
				return $map[ $specific ];
			}
		}

		$key = self::normalize_product_key( $product_id );

		return ( '' !== $key && isset( $map[ $key ] ) ) ? $map[ $key ] : '';
	}

	/**
	 * Whether uninstalling should delete this plugin's configuration.
	 *
	 * @return bool
	 */
	public function delete_on_uninstall() {
		$config = $this->get();

		return ! empty( $config['delete_on_uninstall'] );
	}

	/**
	 * Whether the diagnostic log is enabled.
	 *
	 * @return bool
	 */
	public function debug_enabled() {
		$config = $this->get();

		return ! empty( $config['debug'] );
	}

	/**
	 * Whether renewal orders should skip the fields.
	 *
	 * @return bool
	 */
	public function skip_renewals() {
		$config = $this->get();

		return ! empty( $config['skip_renewals'] );
	}

	/**
	 * Whether existing contact values should pre-fill the form.
	 *
	 * @return bool
	 */
	public function prefill_enabled() {
		$config = $this->get();

		return ! empty( $config['prefill'] );
	}

	/**
	 * Drop the per-request cache. Mainly for tests.
	 *
	 * @return void
	 */
	public function flush() {
		$this->config = null;
	}
}
