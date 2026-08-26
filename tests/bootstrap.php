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

function apply_filters( $hook, $value ) {
	return $value;
}

function do_action() {}
function add_action() {}
function add_filter() {}
function wp_register_style() {}
function wp_register_script() {}
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function is_user_logged_in() {
	return false;
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
require_once BLT_FLUENT_DIR . 'includes/class-updater.php';
require_once BLT_FLUENT_DIR . 'includes/class-plugin.php';
