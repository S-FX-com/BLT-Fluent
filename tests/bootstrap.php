<?php
/**
 * Minimal WordPress shim so the plugin's pure logic can be exercised from the CLI.
 *
 * This is not a WordPress test suite: it stubs just enough of the API for the
 * sanitisation, normalisation and field-preparation code to run without a
 * WordPress install. Anything that talks to FluentCart or FluentCRM is tested by
 * substituting a stub class, never by faking those plugins.
 *
 * @package BLT_Fluent
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['blt_test_options']    = array();
$GLOBALS['blt_test_transients'] = array();

function get_option( $key, $default_value = false ) {
	return array_key_exists( $key, $GLOBALS['blt_test_options'] ) ? $GLOBALS['blt_test_options'][ $key ] : $default_value;
}

function update_option( $key, $value ) {
	$GLOBALS['blt_test_options'][ $key ] = $value;

	return true;
}

function add_option( $key, $value ) {
	if ( array_key_exists( $key, $GLOBALS['blt_test_options'] ) ) {
		return false;
	}

	return update_option( $key, $value );
}

function delete_option( $key ) {
	unset( $GLOBALS['blt_test_options'][ $key ] );

	return true;
}

function get_transient( $key ) {
	return isset( $GLOBALS['blt_test_transients'][ $key ] ) ? $GLOBALS['blt_test_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['blt_test_transients'][ $key ] = $value;

	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['blt_test_transients'][ $key ] );

	return true;
}

$GLOBALS['blt_test_filters']    = array();
$GLOBALS['blt_test_shortcodes'] = array();
$GLOBALS['blt_test_actions']    = array();
$GLOBALS['blt_test_user']       = null;
$GLOBALS['blt_test_caps']       = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['blt_test_filters'][ $hook ][ $priority ][] = $callback;

	return true;
}

function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 2 );

	if ( empty( $GLOBALS['blt_test_filters'][ $hook ] ) ) {
		return $value;
	}

	$by_priority = $GLOBALS['blt_test_filters'][ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
	}

	return $value;
}

function remove_all_filters( $hook ) {
	unset( $GLOBALS['blt_test_filters'][ $hook ] );
}

function add_action( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['blt_test_actions'][ $hook ][] = $callback;

	return true;
}

function do_action( $hook ) {
	$args = array_slice( func_get_args(), 1 );

	if ( empty( $GLOBALS['blt_test_actions'][ $hook ] ) ) {
		return;
	}

	foreach ( $GLOBALS['blt_test_actions'][ $hook ] as $callback ) {
		if ( $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

function add_shortcode( $tag, $callback ) {
	$GLOBALS['blt_test_shortcodes'][ $tag ] = $callback;
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default_value ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default_value;
	}

	return $out;
}

function register_rest_route( $namespace, $route, $args = array() ) {
	return true;
}

function rest_ensure_response( $data ) {
	return $data;
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

function wp_create_nonce( $action = '' ) {
	return 'test-nonce';
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_rand( $min = 0, $max = 0 ) {
	return $max > $min ? $min : 1234;
}

function wp_localize_script() {}
function wp_register_style() {}
function wp_register_script() {}
function wp_enqueue_style() {}
function wp_enqueue_script() {}

function esc_html_e( $text ) {
	echo esc_html( $text );
}

function is_user_logged_in() {
	return ! empty( $GLOBALS['blt_test_user'] );
}

function wp_get_current_user() {
	return $GLOBALS['blt_test_user'];
}

function get_current_user_id() {
	$user = $GLOBALS['blt_test_user'];

	return $user ? (int) $user->ID : 0;
}

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['blt_test_caps'], true );
}

/**
 * Sign a test user in (or out, with null).
 *
 * @param string|null $email User email.
 * @param int         $id    User ID.
 * @return void
 */
function blt_test_login( $email = null, $id = 1 ) {
	if ( null === $email ) {
		$GLOBALS['blt_test_user'] = null;
		return;
	}

	$user             = new stdClass();
	$user->ID         = $id;
	$user->user_email = $email;

	$GLOBALS['blt_test_user'] = $user;
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Error data.
		 *
		 * @var array
		 */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param array  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * HTTP status, when set.
		 *
		 * @return int
		 */
		public function get_error_status() {
			return isset( $this->data['status'] ) ? (int) $this->data['status'] : 0;
		}
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stand-in.
	 */
	class WP_REST_Request {

		/**
		 * Request parameters.
		 *
		 * @var array
		 */
		private $params;

		/**
		 * Constructor.
		 *
		 * @param array $params Parameters.
		 */
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		/**
		 * One parameter.
		 *
		 * @param string $key Parameter name.
		 * @return mixed
		 */
		public function get_param( $key ) {
			return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
		}
	}
}

function wp_unslash( $value ) {
	return $value;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function sanitize_text_field( $value ) {
	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t]+/', ' ', $value );

	return trim( preg_replace( '/\s+/', ' ', $value ) );
}

function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_email( $value ) {
	return trim( (string) $value );
}

function is_email( $value ) {
	return is_string( $value ) && (bool) filter_var( $value, FILTER_VALIDATE_EMAIL );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_textarea( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_url( $value ) {
	return (string) $value;
}

function esc_js( $value ) {
	return addslashes( (string) $value );
}

function __( $text ) {
	return $text;
}

function esc_html__( $text ) {
	return $text;
}

function _e( $text ) {
	echo $text;
}

function gmdate_c() {
	return gmdate( 'c' );
}

define( 'BLT_FLUENT_VERSION', '0.1.0-test' );
define( 'BLT_FLUENT_FILE', dirname( __DIR__ ) . '/blt-fluent.php' );
define( 'BLT_FLUENT_DIR', dirname( __DIR__ ) . '/' );
define( 'BLT_FLUENT_URL', 'https://example.test/wp-content/plugins/blt-fluent/' );
define( 'BLT_FLUENT_BASENAME', 'blt-fluent/blt-fluent.php' );
define( 'BLT_FLUENT_OPTION', 'blt_fluent_config' );
define( 'BLT_FLUENT_CRON_HOOK', 'blt_fluent_daily_update_check' );
define( 'BLT_FLUENT_GITHUB_URL', 'https://github.com/s-fx-com/blt-fluent/' );

require_once BLT_FLUENT_DIR . 'includes/class-dependencies.php';
require_once BLT_FLUENT_DIR . 'includes/class-settings.php';
require_once BLT_FLUENT_DIR . 'includes/class-crm-fields.php';
require_once BLT_FLUENT_DIR . 'includes/class-cart-context.php';
require_once BLT_FLUENT_DIR . 'includes/class-checkout.php';
require_once BLT_FLUENT_DIR . 'includes/class-companies.php';
require_once BLT_FLUENT_DIR . 'includes/class-company-shortcode.php';
require_once BLT_FLUENT_DIR . 'includes/class-updater.php';
require_once BLT_FLUENT_DIR . 'includes/class-plugin.php';

$GLOBALS['blt_test_passed'] = 0;
$GLOBALS['blt_test_failed'] = array();

/**
 * Assert two values match.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
function check( $name, $expected, $actual ) {
	if ( $expected === $actual ) {
		++$GLOBALS['blt_test_passed'];
		return;
	}

	$GLOBALS['blt_test_failed'][] = sprintf(
		"%s\n    expected: %s\n    actual:   %s",
		$name,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

/**
 * Call a private or protected method.
 *
 * @param object $object Instance.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function call_private( $object, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );

	return $reflection->invokeArgs( $object, $args );
}

/**
 * Print the result summary and exit with a suitable status code.
 *
 * @return void
 */
function blt_test_report() {
	$failed = $GLOBALS['blt_test_failed'];

	echo sprintf( "%d passed, %d failed\n", $GLOBALS['blt_test_passed'], count( $failed ) );

	foreach ( $failed as $failure ) {
		echo 'FAIL: ' . $failure . "\n";
	}

	exit( empty( $failed ) ? 0 : 1 );
}
