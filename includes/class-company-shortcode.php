<?php
/**
 * Front-end company selection shortcode and its REST endpoints.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * [blt_fluent_company] -- lets a signed-in member change the company on their
 * own FluentCRM contact record.
 *
 * Typing searches FluentCRM's existing companies; picking one and saving writes
 * it back to the contact. A name that matches nothing offers to create the
 * company, so the member is nudged towards an existing record first and only
 * adds a new one deliberately.
 *
 * A member may only ever change their own contact. The contact is resolved from
 * the signed-in user's email server-side; no contact identifier is accepted from
 * the request.
 */
class Company_Shortcode {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'blt-fluent/v1';

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'blt_fluent_company';

	/**
	 * Transient prefix for the per-user creation throttle.
	 */
	const THROTTLE_PREFIX = 'blt_fluent_company_creates_';

	/**
	 * FluentCRM contact service.
	 *
	 * @var CRM_Fields
	 */
	private $crm;

	/**
	 * FluentCRM companies service.
	 *
	 * @var Companies
	 */
	private $companies;

	/**
	 * Whether the shared script config has been localized.
	 *
	 * @var bool
	 */
	private $localized = false;

	/**
	 * Instance counter, for unique element ids.
	 *
	 * @var int
	 */
	private $instance = 0;

	/**
	 * Constructor.
	 *
	 * @param CRM_Fields $crm       Contact service.
	 * @param Companies  $companies Companies service.
	 */
	public function __construct( CRM_Fields $crm, Companies $companies ) {
		$this->crm       = $crm;
		$this->companies = $companies;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_shortcode( self::tag(), array( $this, 'render' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * The shortcode tag in use.
	 *
	 * @return string
	 */
	public static function tag() {
		/**
		 * Filter the company selection shortcode tag.
		 *
		 * @param string $tag Shortcode tag.
		 */
		return (string) apply_filters( 'blt_fluent/company_shortcode_tag', self::SHORTCODE );
	}

	/**
	 * Register a shortcode that renders nothing.
	 *
	 * Used when FluentCRM is unavailable: without it WordPress would print the
	 * raw "[blt_fluent_company]" text on a member-facing page.
	 *
	 * @return void
	 */
	public static function register_fallback() {
		add_shortcode(
			self::tag(),
			function () {
				if ( current_user_can( 'manage_options' ) ) {
					return '<p class="blt-company blt-company--unavailable">'
						. esc_html__( 'BLT Fluent: company selection is unavailable because FluentCRM is not active. Only administrators see this message.', 'blt-fluent' )
						. '</p>';
				}

				return '';
			}
		);
	}

	/**
	 * Register front-end assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'blt-fluent-company',
			BLT_FLUENT_URL . 'assets/company.css',
			array(),
			BLT_FLUENT_VERSION
		);

		wp_register_script(
			'blt-fluent-company',
			BLT_FLUENT_URL . 'assets/company.js',
			array(),
			BLT_FLUENT_VERSION,
			true
		);
	}

	/**
	 * Whether members may create companies that do not exist yet.
	 *
	 * The shortcode's allow_create attribute controls the interface; this filter
	 * is the enforcement point, because an attribute cannot be trusted to
	 * survive the round trip to the REST endpoint.
	 *
	 * @return bool
	 */
	public function creation_allowed() {
		/**
		 * Filter whether members may create new companies.
		 *
		 * @param bool $allowed Whether creation is permitted.
		 */
		return (bool) apply_filters( 'blt_fluent/company_allow_create', true );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'label'        => __( 'Company', 'blt-fluent' ),
				'placeholder'  => __( 'Start typing your company name…', 'blt-fluent' ),
				'button'       => __( 'Save', 'blt-fluent' ),
				'help'         => '',
				'allow_create' => 'yes',
				'min_chars'    => 2,
			),
			is_array( $atts ) ? $atts : array(),
			self::tag()
		);

		if ( ! is_user_logged_in() ) {
			return $this->message(
				/**
				 * Filter the message shown to signed-out visitors.
				 *
				 * @param string $message Message text.
				 */
				(string) apply_filters(
					'blt_fluent/company_logged_out_message',
					__( 'Please sign in to change your company.', 'blt-fluent' )
				)
			);
		}

		if ( ! $this->companies->available() ) {
			Plugin::log( 'Company shortcode: FluentCRM Companies module not found' );

			return $this->message( __( 'Company selection is not available right now.', 'blt-fluent' ) );
		}

		$contact = $this->current_contact();

		if ( ! $contact ) {
			return $this->message(
				(string) apply_filters(
					'blt_fluent/company_no_contact_message',
					__( 'We could not find your contact record, so your company cannot be changed here yet.', 'blt-fluent' )
				)
			);
		}

		$current      = $this->companies->contact_company( $contact );
		$allow_create = $this->creation_allowed() && ! in_array( strtolower( (string) $atts['allow_create'] ), array( 'no', 'false', '0' ), true );
		$min_chars    = max( 1, min( 10, (int) $atts['min_chars'] ) );

		++$this->instance;
		$base = 'blt-company-' . $this->instance;

		// wp_enqueue_scripts has usually registered these already, but a shortcode
		// rendered outside a normal page request (a REST-driven page builder, say)
		// would otherwise enqueue a handle that does not exist.
		if ( function_exists( 'wp_script_is' ) && ! wp_script_is( 'blt-fluent-company', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'blt-fluent-company' );
		wp_enqueue_script( 'blt-fluent-company' );
		$this->localize();

		$config = array(
			'minChars'    => $min_chars,
			'allowCreate' => $allow_create,
			'currentId'   => $current ? (int) $current['id'] : 0,
		);

		ob_start();
		?>
		<div class="blt-company" id="<?php echo esc_attr( $base ); ?>" data-blt-company="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
			<p class="blt-company__current">
				<span class="blt-company__current-label"><?php esc_html_e( 'Current company:', 'blt-fluent' ); ?></span>
				<strong class="blt-company__current-name" data-blt-company-current>
					<?php echo esc_html( $current ? $current['name'] : __( 'Not set', 'blt-fluent' ) ); ?>
				</strong>
			</p>

			<label class="blt-company__label" for="<?php echo esc_attr( $base . '-input' ); ?>">
				<?php echo esc_html( $atts['label'] ); ?>
			</label>

			<div class="blt-company__combo">
				<input
					type="text"
					class="blt-company__input"
					id="<?php echo esc_attr( $base . '-input' ); ?>"
					role="combobox"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $base . '-list' ); ?>"
					aria-autocomplete="list"
					autocomplete="off"
					spellcheck="false"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					value="<?php echo esc_attr( $current ? $current['name'] : '' ); ?>"
				/>
				<ul class="blt-company__list" id="<?php echo esc_attr( $base . '-list' ); ?>" role="listbox" hidden></ul>
			</div>

			<?php if ( '' !== $atts['help'] ) : ?>
				<p class="blt-company__help"><?php echo esc_html( $atts['help'] ); ?></p>
			<?php endif; ?>

			<div class="blt-company__actions">
				<button type="button" class="blt-company__save" disabled><?php echo esc_html( $atts['button'] ); ?></button>
				<span class="blt-company__status" role="status" aria-live="polite"></span>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Wrap a plain message in the block's markup.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private function message( $text ) {
		return '<p class="blt-company blt-company--notice">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Hand the REST root, nonce and strings to the script, once per request.
	 *
	 * @return void
	 */
	private function localize() {
		if ( $this->localized ) {
			return;
		}

		$this->localized = true;

		wp_localize_script(
			'blt-fluent-company',
			'bltFluentCompany',
			array(
				'root'  => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'searching'  => __( 'Searching…', 'blt-fluent' ),
					'noResults'  => __( 'No matching companies found.', 'blt-fluent' ),
					/* translators: %s: the company name the member typed */
					'addNew'     => __( '+ Add a new company: “%s”', 'blt-fluent' ),
					'saving'     => __( 'Saving…', 'blt-fluent' ),
					'saved'      => __( 'Your company has been updated.', 'blt-fluent' ),
					'savedNew'   => __( 'Company created and saved to your profile.', 'blt-fluent' ),
					'error'      => __( 'Sorry, that could not be saved. Please try again.', 'blt-fluent' ),
					'searchFail' => __( 'Search is unavailable right now.', 'blt-fluent' ),
					'notSet'     => __( 'Not set', 'blt-fluent' ),
				),
			)
		);
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/companies',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_search' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'q' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/company',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'company_id'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'company_name' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Both routes act on the caller's own contact, so being signed in is the
	 * whole requirement. REST cookie authentication additionally demands the
	 * wp_rest nonce, which the script sends.
	 *
	 * @return bool
	 */
	public function permission() {
		return is_user_logged_in();
	}

	/**
	 * GET /companies?q=...
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_search( $request ) {
		$term = trim( (string) $request->get_param( 'q' ) );

		if ( '' === $term ) {
			return rest_ensure_response( array( 'results' => array() ) );
		}

		// A very long term is a mistake or a probe; neither deserves a query.
		$term = function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 100 ) : substr( $term, 0, 100 );

		return rest_ensure_response(
			array(
				'results' => array_values( $this->companies->search( $term ) ),
			)
		);
	}

	/**
	 * POST /company
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_save( $request ) {
		$contact = $this->current_contact();

		if ( ! $contact ) {
			return new \WP_Error(
				'blt_fluent_no_contact',
				__( 'We could not find your contact record.', 'blt-fluent' ),
				array( 'status' => 404 )
			);
		}

		$company_id = absint( $request->get_param( 'company_id' ) );
		$name       = Companies::clean_name( $request->get_param( 'company_name' ) );
		$created    = false;

		if ( $company_id ) {
			$company = $this->companies->get( $company_id );

			if ( ! $company ) {
				return new \WP_Error(
					'blt_fluent_unknown_company',
					__( 'That company no longer exists.', 'blt-fluent' ),
					array( 'status' => 404 )
				);
			}
		} elseif ( '' !== $name ) {
			// Always reuse an existing record before making another one.
			$company = $this->companies->find_by_name( $name );

			if ( ! $company ) {
				if ( ! $this->creation_allowed() ) {
					return new \WP_Error(
						'blt_fluent_create_disabled',
						__( 'Please choose one of the listed companies.', 'blt-fluent' ),
						array( 'status' => 403 )
					);
				}

				if ( $this->create_throttled() ) {
					return new \WP_Error(
						'blt_fluent_too_many_companies',
						__( 'You have added several companies just now. Please try again later.', 'blt-fluent' ),
						array( 'status' => 429 )
					);
				}

				$company = $this->companies->create( $name );

				if ( ! $company ) {
					return new \WP_Error(
						'blt_fluent_create_failed',
						__( 'The company could not be created.', 'blt-fluent' ),
						array( 'status' => 500 )
					);
				}

				$created = true;
				$this->record_create();
			}
		} else {
			return new \WP_Error(
				'blt_fluent_no_company',
				__( 'Please choose or enter a company.', 'blt-fluent' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->companies->assign( $contact, $company['id'] ) ) {
			return new \WP_Error(
				'blt_fluent_assign_failed',
				__( 'Your company could not be saved.', 'blt-fluent' ),
				array( 'status' => 500 )
			);
		}

		Plugin::log(
			'Company saved for contact',
			array(
				'company'  => $company['id'],
				'created'  => $created,
				'strategy' => $this->companies->strategy(),
			)
		);

		/**
		 * Fires after a member's company has been saved.
		 *
		 * @param object $contact FluentCRM subscriber.
		 * @param array  $company Normalized company row.
		 * @param bool   $created Whether the company was created by this request.
		 */
		do_action( 'blt_fluent/company_saved', $contact, $company, $created );

		return rest_ensure_response(
			array(
				'company' => $company,
				'created' => $created,
			)
		);
	}

	/**
	 * The FluentCRM contact for the signed-in user.
	 *
	 * @return object|null
	 */
	private function current_contact() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user  = wp_get_current_user();
		$email = ( $user && ! empty( $user->user_email ) ) ? $user->user_email : '';

		/**
		 * Filter the email used to look up the member's contact record.
		 *
		 * @param string $email Email address.
		 * @param object $user  Current WP user.
		 */
		$email = (string) apply_filters( 'blt_fluent/company_contact_email', $email, $user );

		if ( '' === $email ) {
			return null;
		}

		return $this->crm->get_contact( $email );
	}

	/**
	 * How many companies one member may create per hour.
	 *
	 * @return int
	 */
	private function create_limit() {
		/**
		 * Filter the hourly company creation limit per member.
		 *
		 * @param int $limit Number of companies.
		 */
		return max( 1, (int) apply_filters( 'blt_fluent/company_create_limit', 5 ) );
	}

	/**
	 * Whether the current member has hit the creation limit.
	 *
	 * @return bool
	 */
	private function create_throttled() {
		$count = (int) get_transient( self::THROTTLE_PREFIX . get_current_user_id() );

		return $count >= $this->create_limit();
	}

	/**
	 * Record that the current member created a company.
	 *
	 * @return void
	 */
	private function record_create() {
		$key   = self::THROTTLE_PREFIX . get_current_user_id();
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}
}
