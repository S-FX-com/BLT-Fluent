<?php
/**
 * FluentCRM custom contact field reader and writer.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * The only place in the plugin that talks to FluentCRM.
 *
 * FluentCRM owns the field schema. This class reads the definitions, reads a
 * contact's current values for pre-fill, and writes submitted values back. It
 * never creates or alters a field definition.
 */
class CRM_Fields {

	/**
	 * Canonical field types this plugin knows how to render.
	 */
	const TYPE_TEXT        = 'text';
	const TYPE_TEXTAREA    = 'textarea';
	const TYPE_NUMBER      = 'number';
	const TYPE_SELECT      = 'select';
	const TYPE_MULTISELECT = 'multiselect';
	const TYPE_RADIO       = 'radio';
	const TYPE_CHECKBOX    = 'checkbox';
	const TYPE_DATE        = 'date';
	const TYPE_DATETIME    = 'datetime';

	/**
	 * Per-request cache of normalized definitions.
	 *
	 * @var array|null
	 */
	private $definitions = null;

	/**
	 * How the definitions were located, for the diagnostics tab.
	 *
	 * @var string
	 */
	private $source = '';

	/**
	 * Whether FluentCRM's contact API is callable.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'FluentCrmApi' );
	}

	/**
	 * All FluentCRM custom contact field definitions, keyed by slug.
	 *
	 * Shape per entry: slug, label, type (canonical), options (value => label),
	 * raw (the untouched FluentCRM definition).
	 *
	 * @return array[]
	 */
	public function definitions() {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		$raw = $this->read_raw_definitions();

		$definitions = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$slug = '';

			foreach ( array( 'slug', 'field_key', 'name', 'key' ) as $slug_key ) {
				if ( ! empty( $field[ $slug_key ] ) && is_string( $field[ $slug_key ] ) ) {
					$slug = $field[ $slug_key ];
					break;
				}
			}

			if ( '' === $slug ) {
				continue;
			}

			$label = '';

			foreach ( array( 'label', 'title', 'field_label' ) as $label_key ) {
				if ( ! empty( $field[ $label_key ] ) && is_string( $field[ $label_key ] ) ) {
					$label = $field[ $label_key ];
					break;
				}
			}

			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			$definitions[ $slug ] = array(
				'slug'    => $slug,
				'label'   => '' !== $label ? $label : $slug,
				'type'    => self::canonical_type( $type ),
				'raw_type' => $type,
				'options' => self::normalize_options( isset( $field['options'] ) ? $field['options'] : array() ),
				'raw'     => $field,
			);
		}

		/**
		 * Filter the normalized FluentCRM field definitions.
		 *
		 * @param array[] $definitions Definitions keyed by slug.
		 */
		$this->definitions = apply_filters( 'blt_fluent/crm_field_definitions', $definitions );

		return $this->definitions;
	}

	/**
	 * A single definition.
	 *
	 * @param string $slug Field slug.
	 * @return array|null
	 */
	public function definition( $slug ) {
		$definitions = $this->definitions();

		return isset( $definitions[ $slug ] ) ? $definitions[ $slug ] : null;
	}

	/**
	 * Where the definitions came from, for diagnostics.
	 *
	 * @return string
	 */
	public function source() {
		if ( null === $this->definitions ) {
			$this->definitions();
		}

		return $this->source;
	}

	/**
	 * Read raw definitions from FluentCRM, trying each known access path.
	 *
	 * @return array
	 */
	private function read_raw_definitions() {
		$this->source = 'none';

		if ( function_exists( 'fluentcrm_get_custom_contact_fields' ) ) {
			$fields = fluentcrm_get_custom_contact_fields();

			if ( ! empty( $fields ) && is_array( $fields ) ) {
				$this->source = 'fluentcrm_get_custom_contact_fields()';
				return array_values( $fields );
			}
		}

		if ( class_exists( '\FluentCrm\App\Services\CustomContactField' ) ) {
			try {
				$service = new \FluentCrm\App\Services\CustomContactField();

				if ( method_exists( $service, 'getGlobalFields' ) ) {
					$result = $service->getGlobalFields();
					$fields = ( is_array( $result ) && isset( $result['fields'] ) ) ? $result['fields'] : $result;

					if ( ! empty( $fields ) && is_array( $fields ) ) {
						$this->source = 'CustomContactField::getGlobalFields()';
						return array_values( $fields );
					}
				}
			} catch ( \Throwable $e ) {
				Plugin::log( 'CustomContactField read failed', $e->getMessage() );
			}
		}

		if ( function_exists( 'fluentcrm_get_option' ) ) {
			$fields = fluentcrm_get_option( 'contact_custom_fields', array() );

			if ( ! empty( $fields ) && is_array( $fields ) ) {
				$this->source = 'fluentcrm_get_option(contact_custom_fields)';
				return array_values( $fields );
			}
		}

		return array();
	}

	/**
	 * Map a FluentCRM field type onto a canonical type.
	 *
	 * @param string $type FluentCRM type string.
	 * @return string
	 */
	public static function canonical_type( $type ) {
		$type = strtolower( trim( (string) $type ) );

		$map = array(
			'text'            => self::TYPE_TEXT,
			'input'           => self::TYPE_TEXT,
			'single-line-text' => self::TYPE_TEXT,
			'textarea'        => self::TYPE_TEXTAREA,
			'multi-line-text' => self::TYPE_TEXTAREA,
			'number'          => self::TYPE_NUMBER,
			'numeric'         => self::TYPE_NUMBER,
			'select-one'      => self::TYPE_SELECT,
			'select'          => self::TYPE_SELECT,
			'dropdown'        => self::TYPE_SELECT,
			'select-multi'    => self::TYPE_MULTISELECT,
			'multi-select'    => self::TYPE_MULTISELECT,
			'radio'           => self::TYPE_RADIO,
			'checkbox'        => self::TYPE_CHECKBOX,
			'date'            => self::TYPE_DATE,
			'date_time'       => self::TYPE_DATETIME,
			'datetime'        => self::TYPE_DATETIME,
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : self::TYPE_TEXT;
	}

	/**
	 * Whether a canonical type holds more than one value.
	 *
	 * @param string $type Canonical type.
	 * @return bool
	 */
	public static function is_multi_value( $type ) {
		return in_array( $type, array( self::TYPE_CHECKBOX, self::TYPE_MULTISELECT ), true );
	}

	/**
	 * Normalize a FluentCRM options array to value => label.
	 *
	 * FluentCRM stores options as a plain list of strings in most versions and
	 * as label/value pairs in others.
	 *
	 * @param mixed $options Raw options.
	 * @return array<string,string>
	 */
	public static function normalize_options( $options ) {
		if ( empty( $options ) || ! is_array( $options ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $options as $key => $option ) {
			if ( is_array( $option ) ) {
				$value = '';

				foreach ( array( 'value', 'id', 'key' ) as $value_key ) {
					if ( isset( $option[ $value_key ] ) && is_scalar( $option[ $value_key ] ) ) {
						$value = (string) $option[ $value_key ];
						break;
					}
				}

				$label = '';

				foreach ( array( 'label', 'title', 'name' ) as $label_key ) {
					if ( isset( $option[ $label_key ] ) && is_scalar( $option[ $label_key ] ) ) {
						$label = (string) $option[ $label_key ];
						break;
					}
				}

				if ( '' === $value && '' === $label ) {
					continue;
				}

				$value = '' !== $value ? $value : $label;

				$normalized[ $value ] = '' !== $label ? $label : $value;
				continue;
			}

			if ( ! is_scalar( $option ) ) {
				continue;
			}

			$option = (string) $option;

			// A string-keyed list is already value => label.
			$value = is_string( $key ) ? $key : $option;

			$normalized[ $value ] = $option;
		}

		return $normalized;
	}

	/**
	 * The FluentCRM contact for an email address.
	 *
	 * @param string $email Email address.
	 * @return object|null
	 */
	public function get_contact( $email ) {
		$email = is_email( $email ) ? $email : '';

		if ( '' === $email || ! $this->available() ) {
			return null;
		}

		try {
			$contact = FluentCrmApi( 'contacts' )->getContact( $email );

			return $contact ? $contact : null;
		} catch ( \Throwable $e ) {
			Plugin::log( 'getContact failed', $e->getMessage() );

			return null;
		}
	}

	/**
	 * A contact's current custom field values, keyed by slug.
	 *
	 * @param string $email Email address.
	 * @return array
	 */
	public function contact_values( $email ) {
		$contact = $this->get_contact( $email );

		if ( ! $contact ) {
			return array();
		}

		try {
			if ( method_exists( $contact, 'custom_fields' ) ) {
				$values = $contact->custom_fields();

				if ( is_array( $values ) ) {
					return $values;
				}
			}

			if ( isset( $contact->custom_values ) && is_array( $contact->custom_values ) ) {
				return $contact->custom_values;
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'custom_fields read failed', $e->getMessage() );
		}

		return array();
	}

	/**
	 * Write custom field values to a contact.
	 *
	 * @param string $email  Contact email.
	 * @param array  $values slug => value, already sanitized and allowlisted.
	 * @param array  $extra  Extra contact attributes (first_name, etc.).
	 * @return bool True when FluentCRM accepted the write.
	 */
	public function save_values( $email, array $values, array $extra = array() ) {
		$email = is_email( $email ) ? $email : '';

		if ( '' === $email ) {
			Plugin::log( 'CRM write skipped: no usable email address' );

			return false;
		}

		if ( empty( $values ) ) {
			return false;
		}

		if ( ! $this->available() ) {
			Plugin::log( 'CRM write skipped: FluentCrmApi() unavailable' );

			return false;
		}

		$data = array_merge(
			$extra,
			array(
				'email'         => $email,
				'custom_values' => $values,
			)
		);

		/**
		 * Filter the payload handed to FluentCRM's contacts API.
		 *
		 * @param array  $data   createOrUpdate payload.
		 * @param string $email  Contact email.
		 * @param array  $values Custom field values.
		 */
		$data = apply_filters( 'blt_fluent/crm_contact_payload', $data, $email, $values );

		try {
			$contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );

			if ( ! $contact ) {
				Plugin::log( 'createOrUpdate returned nothing', array( 'email' => $email ) );

				return false;
			}

			Plugin::log( 'CRM write ok', array( 'email' => $email, 'slugs' => array_keys( $values ) ) );

			/**
			 * Fires after values have been written to a FluentCRM contact.
			 *
			 * @param object $contact FluentCRM subscriber.
			 * @param array  $values  Written values.
			 */
			do_action( 'blt_fluent/values_written', $contact, $values );

			return true;
		} catch ( \Throwable $e ) {
			Plugin::log( 'createOrUpdate failed', $e->getMessage() );

			return false;
		}
	}
}
