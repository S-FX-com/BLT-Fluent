<?php
/**
 * Checkout render, validation and persistence.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * The data path: render fields at checkout, validate them on submit, then write
 * them to the FluentCRM contact (and to order meta as an audit trail).
 */
class Checkout {

	/**
	 * POST key holding the submitted values.
	 */
	const FIELD_NAME = 'blt_fluent_fields';

	/**
	 * POST key naming the rendered field set.
	 */
	const SET_NAME = 'blt_fluent_field_set';

	/**
	 * Order meta key for the audit trail.
	 */
	const ORDER_META_KEY = '_blt_fluent_fields';

	/**
	 * Whether the field block has already been rendered this request.
	 *
	 * Sites that register both render hooks (full page and modal) would
	 * otherwise get the block twice.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * FluentCRM field service.
	 *
	 * @var CRM_Fields
	 */
	private $crm;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings service.
	 * @param CRM_Fields $crm      FluentCRM field service.
	 */
	public function __construct( Settings $settings, CRM_Fields $crm ) {
		$this->settings = $settings;
		$this->crm      = $crm;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		/**
		 * Filter the FluentCart action the fields are rendered on.
		 *
		 * `fluent_cart/before_payment_methods` is the default because it fires in
		 * the standard, modal and block renderers. `fluent_cart/checkout/b2b_extra_fields`
		 * fires in the full-page renderer only and will silently do nothing in a
		 * modal checkout.
		 *
		 * @param string $hook Action name.
		 */
		$render_hook = apply_filters( 'blt_fluent/render_hook', 'fluent_cart/before_payment_methods' );

		add_action( $render_hook, array( $this, 'render' ), 10, 1 );

		add_filter( 'fluent_cart/checkout/validate_data', array( $this, 'validate' ), 10, 3 );
		add_action( 'fluent_cart/checkout/prepare_other_data', array( $this, 'persist' ), 10, 3 );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// NOTE: fluent_cart/checkout/customer_data_saved was removed in FluentCart
		// 1.4.0 and must not be used -- a callback on it never runs, silently.
	}

	/**
	 * Register (but do not enqueue) front-end assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'blt-fluent-checkout',
			BLT_FLUENT_URL . 'assets/checkout.css',
			array(),
			BLT_FLUENT_VERSION
		);

		wp_register_script(
			'blt-fluent-checkout',
			BLT_FLUENT_URL . 'assets/checkout.js',
			array(),
			BLT_FLUENT_VERSION,
			true
		);
	}

	/**
	 * Render the configured fields.
	 *
	 * @param mixed $payload Whatever FluentCart passes to the render hook.
	 * @return void
	 */
	public function render( $payload = null ) {
		if ( $this->rendered ) {
			return;
		}

		$context = new Cart_Context( $payload );

		if ( $this->settings->skip_renewals() && $context->is_renewal() ) {
			Plugin::log( 'Render skipped: renewal order', array( 'order_type' => $context->order_type() ) );

			return;
		}

		$set_key = $this->resolve_set_key( $context );

		if ( '' === $set_key ) {
			return;
		}

		$fields = $this->prepared_fields( $set_key );

		if ( empty( $fields ) ) {
			return;
		}

		$set    = $this->settings->field_set( $set_key );
		$values = array();

		if ( $this->settings->prefill_enabled() ) {
			$email = $context->email();

			if ( '' !== $email ) {
				$values = $this->crm->contact_values( $email );
			}
		}

		// Anything already submitted wins over the stored value, so a failed
		// validation round does not wipe what the customer typed.
		$submitted = $this->raw_submitted_values();

		$this->rendered = true;

		wp_enqueue_style( 'blt-fluent-checkout' );
		wp_enqueue_script( 'blt-fluent-checkout' );

		echo '<div class="blt-fluent-fields" data-blt-fluent-set="' . esc_attr( $set_key ) . '">';

		printf(
			'<input type="hidden" name="%1$s" value="%2$s" />',
			esc_attr( self::SET_NAME ),
			esc_attr( $set_key )
		);

		if ( ! empty( $set['title'] ) ) {
			echo '<h3 class="blt-fluent-fields__title">' . esc_html( $set['title'] ) . '</h3>';
		}

		if ( ! empty( $set['description'] ) ) {
			echo '<p class="blt-fluent-fields__description">' . esc_html( $set['description'] ) . '</p>';
		}

		foreach ( $fields as $field ) {
			$slug  = $field['slug'];
			$value = isset( $submitted[ $slug ] ) ? $submitted[ $slug ] : ( isset( $values[ $slug ] ) ? $values[ $slug ] : '' );

			$this->render_field( $field, $value );
		}

		echo '</div>';

		/**
		 * Fires after the field block has been rendered.
		 *
		 * @param array        $fields  Prepared fields.
		 * @param string       $set_key Field set key.
		 * @param Cart_Context $context Cart context.
		 */
		do_action( 'blt_fluent/after_render', $fields, $set_key, $context );
	}

	/**
	 * Validate submitted values.
	 *
	 * FluentCart's filter hands over an array of errors keyed by field name;
	 * returning it unchanged (or empty) lets checkout proceed.
	 *
	 * @param mixed $errors Errors collected so far.
	 * @return mixed
	 */
	public function validate( $errors = array() ) {
		$request_data = array();

		foreach ( array_slice( func_get_args(), 1 ) as $arg ) {
			if ( is_array( $arg ) ) {
				$request_data = $arg;
				break;
			}
		}

		if ( ! is_array( $errors ) ) {
			// Unexpected shape: never trade a working checkout for our validation.
			Plugin::log( 'validate_data: first argument was not an array; skipping validation', gettype( $errors ) );

			return $errors;
		}

		if ( ! $this->looks_like_errors( $errors ) ) {
			Plugin::log( 'validate_data: first argument looks like checkout data, not errors; skipping validation', array_keys( $errors ) );

			return $errors;
		}

		$set_key = $this->applicable_set_key( $request_data );

		if ( '' === $set_key ) {
			return $errors;
		}

		$fields = $this->prepared_fields( $set_key );

		if ( empty( $fields ) ) {
			return $errors;
		}

		// Validation reads the raw submission, not the sanitized values:
		// sanitizing first would turn "twelve" in a numeric field into an empty
		// string, and the customer would lose their input with no explanation.
		$raw = $this->raw_submitted_values( $request_data );

		foreach ( $fields as $field ) {
			$slug    = $field['slug'];
			$value   = array_key_exists( $slug, $raw ) ? $raw[ $slug ] : '';
			$message = $this->validation_error( $field, $value );

			if ( '' === $message ) {
				continue;
			}

			$errors[ $this->error_key( $slug ) ] = $message;
		}

		return $errors;
	}

	/**
	 * The validation message for one field, or an empty string when it passes.
	 *
	 * @param array $field Prepared field.
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function validation_error( array $field, $value ) {
		$multi = CRM_Fields::is_multi_value( $field['type'] );

		if ( $multi ) {
			if ( ! is_array( $value ) ) {
				$value = ( is_scalar( $value ) && '' !== (string) $value ) ? array( $value ) : array();
			}

			$value = array_values(
				array_filter(
					array_map(
						function ( $item ) {
							return is_scalar( $item ) ? trim( (string) $item ) : '';
						},
						$value
					),
					function ( $item ) {
						return '' !== $item;
					}
				)
			);

			$empty = array() === $value;
		} else {
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			$empty = '' === $value;
		}

		if ( $field['required'] && $empty ) {
			return sprintf(
				/* translators: %s: field label */
				__( '%s is required.', 'blt-fluent' ),
				$field['label']
			);
		}

		if ( $empty ) {
			return '';
		}

		switch ( $field['type'] ) {
			case CRM_Fields::TYPE_NUMBER:
				if ( ! is_numeric( $value ) ) {
					return sprintf(
						/* translators: %s: field label */
						__( '%s must be a number.', 'blt-fluent' ),
						$field['label']
					);
				}
				break;

			case CRM_Fields::TYPE_DATE:
				if ( ! self::is_valid_date( $value, 'Y-m-d' ) ) {
					return sprintf(
						/* translators: %s: field label */
						__( '%s must be a valid date.', 'blt-fluent' ),
						$field['label']
					);
				}
				break;

			case CRM_Fields::TYPE_DATETIME:
				if ( ! self::is_valid_date( $value, 'Y-m-d\TH:i' ) && ! self::is_valid_date( $value, 'Y-m-d H:i:s' ) ) {
					return sprintf(
						/* translators: %s: field label */
						__( '%s must be a valid date.', 'blt-fluent' ),
						$field['label']
					);
				}
				break;

			case CRM_Fields::TYPE_SELECT:
			case CRM_Fields::TYPE_RADIO:
			case CRM_Fields::TYPE_CHECKBOX:
			case CRM_Fields::TYPE_MULTISELECT:
				$allowed = array_map( 'strval', array_keys( $field['options'] ) );

				if ( empty( $allowed ) ) {
					break;
				}

				$submitted = $multi ? array_map( 'strval', (array) $value ) : array( (string) $value );

				foreach ( $submitted as $item ) {
					if ( ! in_array( $item, $allowed, true ) ) {
						return sprintf(
							/* translators: %s: field label */
							__( 'Please choose a valid option for %s.', 'blt-fluent' ),
							$field['label']
						);
					}
				}
				break;
		}

		return '';
	}

	/**
	 * Write submitted values to FluentCRM and to order meta.
	 *
	 * FluentCart passes the order, the raw request data and the validated data;
	 * their positions are read defensively so a signature change degrades to a
	 * $_POST read rather than a fatal.
	 *
	 * @return void
	 */
	public function persist() {
		$args         = func_get_args();
		$order        = null;
		$request_data = array();

		foreach ( $args as $arg ) {
			if ( is_object( $arg ) && null === $order ) {
				$order = $arg;
				continue;
			}

			if ( is_array( $arg ) && empty( $request_data ) ) {
				$request_data = $arg;
			}
		}

		$set_key = $this->applicable_set_key( $request_data );

		if ( '' === $set_key ) {
			return;
		}

		$fields = $this->prepared_fields( $set_key );

		if ( empty( $fields ) ) {
			return;
		}

		$values = $this->collect_values( $fields, $request_data );
		$values = array_filter(
			$values,
			function ( $value ) {
				return is_array( $value ) ? array() !== $value : '' !== (string) $value;
			}
		);

		if ( empty( $values ) ) {
			Plugin::log( 'Nothing to persist: no non-empty values submitted' );

			return;
		}

		$email = $this->resolve_email( $order, $request_data );

		if ( '' === $email ) {
			Plugin::log( 'Persist aborted: could not determine an email address', array( 'set' => $set_key ) );

			return;
		}

		$written = $this->crm->save_values( $email, $values );

		$this->write_order_meta( $order, $values, $email, $written );

		/**
		 * Fires after a checkout submission has been captured.
		 *
		 * @param string $email   Contact email.
		 * @param array  $values  Values written, keyed by slug.
		 * @param object|null $order FluentCart order, when available.
		 * @param bool   $written Whether the FluentCRM write succeeded.
		 */
		do_action( 'blt_fluent/captured', $email, $values, $order, $written );
	}

	/**
	 * The field set that applies to the current cart, if any.
	 *
	 * @param Cart_Context $context Cart context.
	 * @return string Field set key, or empty string.
	 */
	private function resolve_set_key( Cart_Context $context ) {
		$pairs = $context->pairs();

		foreach ( $pairs as $pair ) {
			$set_key = $this->settings->field_set_key_for_product( $pair['product_id'], $pair['variation_id'] );

			if ( '' !== $set_key ) {
				return $set_key;
			}
		}

		if ( empty( $pairs ) ) {
			Plugin::log( 'No cart products detected', array( 'strategy' => $context->strategy() ) );
		}

		/**
		 * Filter the field set chosen for the current cart.
		 *
		 * @param string       $set_key Field set key, empty when no product matched.
		 * @param Cart_Context $context Cart context.
		 */
		$set_key = (string) apply_filters( 'blt_fluent/field_set_key', '', $context );

		return $this->settings->field_set( $set_key ) ? $set_key : '';
	}

	/**
	 * The field set that applies to a submission.
	 *
	 * The rendered form carries a hidden field set key, which keeps validation
	 * and persistence independent of cart detection at those stages. Only keys
	 * that exist in the stored configuration are accepted, so the value cannot
	 * introduce fields an administrator has not configured. When the cart is
	 * detectable it wins, so a tampered key cannot be used to dodge a required
	 * field.
	 *
	 * @param array $request_data Request data provided by FluentCart, if any.
	 * @return string
	 */
	private function applicable_set_key( array $request_data = array() ) {
		$context = new Cart_Context( $request_data );

		// A renewal never renders the fields, so it must never be validated for
		// them either -- otherwise a required field blocks a renewal checkout.
		if ( $this->settings->skip_renewals() && $context->is_renewal() ) {
			return '';
		}

		$from_cart = $this->resolve_set_key( $context );

		if ( '' !== $from_cart ) {
			return $from_cart;
		}

		$posted = '';

		foreach ( $this->request_payloads( $request_data ) as $payload ) {
			$found = Cart_Context::deep_find( $payload, array( self::SET_NAME ) );

			if ( is_string( $found ) && '' !== $found ) {
				$posted = $found;
				break;
			}
		}

		$posted = sanitize_key( $posted );

		return $this->settings->field_set( $posted ) ? $posted : '';
	}

	/**
	 * Merge configuration with FluentCRM definitions for one field set.
	 *
	 * Slugs with no matching FluentCRM definition -- a field deleted in
	 * FluentCRM after being configured here -- are skipped, never guessed at.
	 *
	 * @param string $set_key Field set key.
	 * @return array[]
	 */
	public function prepared_fields( $set_key ) {
		$set = $this->settings->field_set( $set_key );

		if ( ! $set || empty( $set['fields'] ) ) {
			return array();
		}

		$prepared = array();

		foreach ( $set['fields'] as $field ) {
			$definition = $this->crm->definition( $field['slug'] );

			if ( ! $definition ) {
				Plugin::log( 'Skipping orphan field (no FluentCRM definition)', $field['slug'] );
				continue;
			}

			$prepared[] = array(
				'slug'        => $definition['slug'],
				'type'        => $definition['type'],
				'options'     => $definition['options'],
				'label'       => '' !== $field['label'] ? $field['label'] : $definition['label'],
				'placeholder' => $field['placeholder'],
				'help'        => $field['help'],
				'required'    => ! empty( $field['required'] ),
			);
		}

		/**
		 * Filter the prepared fields for a field set.
		 *
		 * @param array[] $prepared Prepared fields.
		 * @param string  $set_key  Field set key.
		 */
		return apply_filters( 'blt_fluent/prepared_fields', $prepared, $set_key );
	}

	/**
	 * Render a single field.
	 *
	 * @param array $field Prepared field.
	 * @param mixed $value Current value.
	 * @return void
	 */
	private function render_field( array $field, $value ) {
		$slug  = $field['slug'];
		$id    = 'blt-fluent-' . $slug;
		$name  = self::FIELD_NAME . '[' . $slug . ']';
		$multi = CRM_Fields::is_multi_value( $field['type'] );

		if ( $multi ) {
			$value = is_array( $value ) ? $value : ( '' === (string) $value ? array() : array_map( 'trim', explode( ',', (string) $value ) ) );
		} elseif ( is_array( $value ) ) {
			$value = reset( $value );
		}

		printf(
			'<div class="blt-fluent-field blt-fluent-field--%1$s" data-blt-fluent-field="%2$s">',
			esc_attr( $field['type'] ),
			esc_attr( $slug )
		);

		printf(
			'<label class="blt-fluent-field__label" for="%1$s">%2$s%3$s</label>',
			esc_attr( $id ),
			esc_html( $field['label'] ),
			$field['required'] ? ' <span class="blt-fluent-field__required" aria-hidden="true">*</span>' : ''
		);

		$required_attr = $field['required'] ? ' required="required"' : '';

		switch ( $field['type'] ) {
			case CRM_Fields::TYPE_TEXTAREA:
				printf(
					'<textarea class="blt-fluent-field__input" id="%1$s" name="%2$s" rows="4" placeholder="%3$s"%4$s>%5$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $field['placeholder'] ),
					$required_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal attribute.
					esc_textarea( (string) $value )
				);
				break;

			case CRM_Fields::TYPE_SELECT:
			case CRM_Fields::TYPE_MULTISELECT:
				printf(
					'<select class="blt-fluent-field__input" id="%1$s" name="%2$s"%3$s%4$s>',
					esc_attr( $id ),
					esc_attr( $multi ? $name . '[]' : $name ),
					$multi ? ' multiple="multiple"' : '',
					$required_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal attribute.
				);

				if ( ! $multi ) {
					printf(
						'<option value="">%s</option>',
						esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( '— Select —', 'blt-fluent' ) )
					);
				}

				foreach ( $field['options'] as $option_value => $option_label ) {
					$selected = $multi
						? in_array( (string) $option_value, array_map( 'strval', (array) $value ), true )
						: (string) $option_value === (string) $value;

					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $option_value ),
						$selected ? ' selected="selected"' : '',
						esc_html( $option_label )
					);
				}

				echo '</select>';
				break;

			case CRM_Fields::TYPE_RADIO:
				echo '<div class="blt-fluent-field__choices" role="radiogroup">';

				foreach ( $field['options'] as $option_value => $option_label ) {
					printf(
						'<label class="blt-fluent-field__choice"><input type="radio" name="%1$s" value="%2$s"%3$s /> <span>%4$s</span></label>',
						esc_attr( $name ),
						esc_attr( $option_value ),
						( (string) $option_value === (string) $value ) ? ' checked="checked"' : '',
						esc_html( $option_label )
					);
				}

				echo '</div>';
				break;

			case CRM_Fields::TYPE_CHECKBOX:
				echo '<div class="blt-fluent-field__choices">';

				$current = array_map( 'strval', (array) $value );

				foreach ( $field['options'] as $option_value => $option_label ) {
					printf(
						'<label class="blt-fluent-field__choice"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> <span>%4$s</span></label>',
						esc_attr( $name ),
						esc_attr( $option_value ),
						in_array( (string) $option_value, $current, true ) ? ' checked="checked"' : '',
						esc_html( $option_label )
					);
				}

				echo '</div>';
				break;

			case CRM_Fields::TYPE_NUMBER:
			case CRM_Fields::TYPE_DATE:
			case CRM_Fields::TYPE_DATETIME:
			case CRM_Fields::TYPE_TEXT:
			default:
				$input_types = array(
					CRM_Fields::TYPE_NUMBER   => 'number',
					CRM_Fields::TYPE_DATE     => 'date',
					CRM_Fields::TYPE_DATETIME => 'datetime-local',
				);

				$input_type = isset( $input_types[ $field['type'] ] ) ? $input_types[ $field['type'] ] : 'text';

				printf(
					'<input class="blt-fluent-field__input" type="%1$s" id="%2$s" name="%3$s" value="%4$s" placeholder="%5$s"%6$s%7$s />',
					esc_attr( $input_type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $field['placeholder'] ),
					'number' === $input_type ? ' step="any"' : '',
					$required_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal attribute.
				);
				break;
		}

		if ( '' !== $field['help'] ) {
			echo '<p class="blt-fluent-field__help">' . esc_html( $field['help'] ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Submitted values, sanitized and restricted to the configured fields.
	 *
	 * @param array[] $fields       Prepared fields.
	 * @param array   $request_data Request data from FluentCart, if any.
	 * @return array slug => value
	 */
	private function collect_values( array $fields, array $request_data = array() ) {
		$raw    = $this->raw_submitted_values( $request_data );
		$values = array();

		foreach ( $fields as $field ) {
			$slug = $field['slug'];

			if ( ! array_key_exists( $slug, $raw ) ) {
				continue;
			}

			$values[ $slug ] = $this->sanitize_value( $field, $raw[ $slug ] );
		}

		return $values;
	}

	/**
	 * Raw submitted values straight off $_POST, unslashed but not yet sanitized.
	 *
	 * Sanitization happens per field in sanitize_value(), which needs to know the
	 * field type. Nothing reaches FluentCRM without passing through there.
	 *
	 * @return array
	 */
	private function raw_submitted_values( array $request_data = array() ) {
		foreach ( $this->request_payloads( $request_data ) as $payload ) {
			$found = Cart_Context::deep_find_array( $payload, self::FIELD_NAME );

			if ( ! empty( $found ) ) {
				return $found;
			}
		}

		return array();
	}

	/**
	 * Every place the submitted values might be, in order of preference.
	 *
	 * FluentCart's checkout is JavaScript driven, so the values can arrive as
	 * ordinary form fields or inside a JSON request body -- and either way they
	 * may be nested under a wrapper key rather than sitting at the top level.
	 * All three are searched instead of assuming one.
	 *
	 * @param array $request_data Request data handed over by FluentCart.
	 * @return array[]
	 */
	private function request_payloads( array $request_data = array() ) {
		$payloads = array();

		if ( ! empty( $request_data ) ) {
			$payloads[] = $request_data;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- FluentCart owns the checkout nonce; every value is allowlisted and sanitized in sanitize_value().
		if ( ! empty( $_POST ) ) {
			$payloads[] = wp_unslash( $_POST );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$json = self::json_body();

		if ( ! empty( $json ) ) {
			$payloads[] = $json;
		}

		return $payloads;
	}

	/**
	 * The decoded JSON request body, when the request carries one.
	 *
	 * @return array
	 */
	private static function json_body() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();

		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) ) : '';

		if ( false === strpos( $content_type, 'json' ) ) {
			return $cache;
		}

		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the request body.

		if ( ! is_string( $raw ) || '' === $raw ) {
			return $cache;
		}

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			$cache = $decoded;
		}

		return $cache;
	}

	/**
	 * Sanitize one submitted value according to its FluentCRM field type.
	 *
	 * @param array $field Prepared field.
	 * @param mixed $value Raw value.
	 * @return string|array
	 */
	private function sanitize_value( array $field, $value ) {
		$multi = CRM_Fields::is_multi_value( $field['type'] );

		if ( $multi ) {
			$value  = is_array( $value ) ? $value : array( $value );
			$clean  = array();
			$allowed = array_map( 'strval', array_keys( $field['options'] ) );

			foreach ( $value as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}

				$item = sanitize_text_field( (string) $item );

				if ( '' === $item ) {
					continue;
				}

				// Choice fields accept only values FluentCRM defines.
				if ( ! empty( $allowed ) && ! in_array( $item, $allowed, true ) ) {
					continue;
				}

				$clean[] = $item;
			}

			/**
			 * Filter a multi-value field before it is written to FluentCRM.
			 *
			 * FluentCRM stores multi-value custom fields as an array. If a given
			 * install turns out to expect a comma-separated string instead, use
			 * this filter to reshape it -- and the same shape will then round-trip
			 * through the profile-edit form.
			 *
			 * @param array $clean Cleaned values.
			 * @param array $field Prepared field.
			 */
			return apply_filters( 'blt_fluent/multi_value', array_values( array_unique( $clean ) ), $field );
		}

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		switch ( $field['type'] ) {
			case CRM_Fields::TYPE_TEXTAREA:
				return sanitize_textarea_field( $value );

			case CRM_Fields::TYPE_NUMBER:
				$value = sanitize_text_field( $value );

				return is_numeric( $value ) ? $value : '';

			case CRM_Fields::TYPE_SELECT:
			case CRM_Fields::TYPE_RADIO:
				$value   = sanitize_text_field( $value );
				$allowed = array_map( 'strval', array_keys( $field['options'] ) );

				if ( '' === $value || empty( $allowed ) ) {
					return $value;
				}

				return in_array( $value, $allowed, true ) ? $value : '';

			case CRM_Fields::TYPE_DATE:
				$value = sanitize_text_field( $value );

				return self::is_valid_date( $value, 'Y-m-d' ) ? $value : '';

			case CRM_Fields::TYPE_DATETIME:
				$value = sanitize_text_field( $value );

				if ( self::is_valid_date( $value, 'Y-m-d\TH:i' ) ) {
					return str_replace( 'T', ' ', $value ) . ':00';
				}

				return self::is_valid_date( $value, 'Y-m-d H:i:s' ) ? $value : '';

			case CRM_Fields::TYPE_TEXT:
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Whether a string parses as a date in the given format.
	 *
	 * @param mixed  $value  Value to test.
	 * @param string $format Expected format.
	 * @return bool
	 */
	public static function is_valid_date( $value, $format = 'Y-m-d' ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		$date = \DateTime::createFromFormat( $format, $value );

		return $date && $date->format( $format ) === $value;
	}

	/**
	 * The error array key used for a field.
	 *
	 * Defaults to the input name so FluentCart can attach the message inline.
	 *
	 * @param string $slug Field slug.
	 * @return string
	 */
	private function error_key( $slug ) {
		/**
		 * Filter the key an error message is reported under.
		 *
		 * @param string $key  Error key.
		 * @param string $slug Field slug.
		 */
		return (string) apply_filters(
			'blt_fluent/error_key',
			self::FIELD_NAME . '[' . $slug . ']',
			$slug
		);
	}

	/**
	 * Whether an array passed to the validation filter is an error bag.
	 *
	 * @param array $candidate Array to inspect.
	 * @return bool
	 */
	private function looks_like_errors( array $candidate ) {
		if ( empty( $candidate ) ) {
			return true;
		}

		// An error bag maps a field name to a message, or to a list of messages.
		// Checkout data carries numbers, booleans, objects and nested structures.
		foreach ( $candidate as $value ) {
			if ( is_string( $value ) ) {
				continue;
			}

			if ( ! is_array( $value ) ) {
				return false;
			}

			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					return false;
				}
			}
		}

		// Keys that name a structure rather than a form field. Field names that
		// FluentCart might legitimately report an error against -- billing_email,
		// payment_method -- are deliberately not in this list.
		$structural_keys = array( 'cart', 'items', 'line_items', 'checkout_data', 'order_type', 'payment_method_data', 'billing_address', 'shipping_address' );

		foreach ( $structural_keys as $key ) {
			if ( array_key_exists( $key, $candidate ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Work out which email address the values belong to.
	 *
	 * @param object|null $order        FluentCart order, when available.
	 * @param array       $request_data Request data.
	 * @return string
	 */
	private function resolve_email( $order, array $request_data ) {
		$candidates = array();

		if ( $order ) {
			foreach ( array( 'billing_email', 'customer_email', 'email' ) as $property ) {
				if ( isset( $order->{$property} ) && is_string( $order->{$property} ) ) {
					$candidates[] = $order->{$property};
				}
			}

			if ( isset( $order->customer ) ) {
				$customer = $order->customer;

				foreach ( array( 'email', 'billing_email', 'user_email' ) as $property ) {
					if ( is_object( $customer ) && isset( $customer->{$property} ) && is_string( $customer->{$property} ) ) {
						$candidates[] = $customer->{$property};
					}
				}
			}

			$deep = Cart_Context::deep_find( $order, array( 'billing_email', 'customer_email', 'email' ) );

			if ( is_string( $deep ) ) {
				$candidates[] = $deep;
			}
		}

		$from_request = Cart_Context::deep_find( $request_data, array( 'billing_email', 'customer_email', 'email' ) );

		if ( is_string( $from_request ) ) {
			$candidates[] = $from_request;
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			if ( $user && $user->user_email ) {
				$candidates[] = $user->user_email;
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( is_email( $candidate ) ) {
				return sanitize_email( $candidate );
			}
		}

		/**
		 * Filter the email address the values are attached to.
		 *
		 * @param string      $email        Resolved email, empty when none found.
		 * @param object|null $order        FluentCart order.
		 * @param array       $request_data Request data.
		 */
		return (string) apply_filters( 'blt_fluent/contact_email', '', $order, $request_data );
	}

	/**
	 * Store the submission on the order as an audit trail.
	 *
	 * This is deliberately separate from the CRM write: it records what was
	 * submitted at signup, independent of any later profile edit.
	 *
	 * @param object|null $order       FluentCart order.
	 * @param array       $values      Submitted values.
	 * @param string      $email       Contact email.
	 * @param bool        $crm_written Whether the CRM write succeeded.
	 * @return bool
	 */
	private function write_order_meta( $order, array $values, $email, $crm_written ) {
		if ( ! $order ) {
			return false;
		}

		$payload = array(
			'captured_at' => gmdate( 'c' ),
			'email'       => $email,
			'crm_written' => (bool) $crm_written,
			'values'      => $values,
		);

		/**
		 * Filter the order meta payload, or short-circuit the write by returning
		 * an empty value.
		 *
		 * @param array       $payload Audit payload.
		 * @param object|null $order   FluentCart order.
		 */
		$payload = apply_filters( 'blt_fluent/order_meta_payload', $payload, $order );

		if ( empty( $payload ) ) {
			return false;
		}

		$encoded = wp_json_encode( $payload );

		try {
			foreach ( array( 'updateMeta', 'update_meta', 'setMeta' ) as $method ) {
				if ( method_exists( $order, $method ) ) {
					$order->{$method}( self::ORDER_META_KEY, $encoded );

					return true;
				}
			}

			$order_id = 0;

			foreach ( array( 'id', 'ID', 'order_id' ) as $property ) {
				if ( ! empty( $order->{$property} ) ) {
					$order_id = (int) $order->{$property};
					break;
				}
			}

			if ( $order_id && class_exists( '\FluentCart\App\Models\OrderMeta' ) ) {
				\FluentCart\App\Models\OrderMeta::updateOrCreate(
					array(
						'order_id' => $order_id,
						'meta_key' => self::ORDER_META_KEY,
					),
					array( 'meta_value' => $encoded )
				);

				return true;
			}

			Plugin::log( 'Order meta not written: no known write path on the order object', $order_id );
		} catch ( \Throwable $e ) {
			Plugin::log( 'Order meta write failed', $e->getMessage() );
		}

		return false;
	}
}
