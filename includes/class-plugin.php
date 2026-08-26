<?php
/**
 * Container and boot sequence.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's services together.
 */
final class Plugin {

	/**
	 * Transient holding the rolling diagnostic log.
	 */
	const LOG_TRANSIENT = 'blt_fluent_log';

	/**
	 * Maximum number of retained log entries.
	 */
	const LOG_LIMIT = 40;

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * FluentCRM field reader/writer.
	 *
	 * @var CRM_Fields
	 */
	private $crm_fields;

	/**
	 * Checkout integration.
	 *
	 * @var Checkout
	 */
	private $checkout;

	/**
	 * Admin screen.
	 *
	 * @var Admin|null
	 */
	private $admin = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register everything. Idempotent.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'blt-fluent', false, dirname( BLT_FLUENT_BASENAME ) . '/languages' );

		$this->settings   = new Settings();
		$this->crm_fields = new CRM_Fields();

		$this->settings->maybe_migrate();

		// The updater is booted outside any admin-only hook so that WP-CLI and
		// management tools see the same update information the dashboard does.
		Updater::instance()->boot();

		$this->checkout = new Checkout( $this->settings, $this->crm_fields );
		$this->checkout->boot();

		if ( is_admin() ) {
			$this->admin = new Admin( $this->settings, $this->crm_fields );
			$this->admin->boot();
		}

		do_action( 'blt_fluent/booted', $this );
	}

	/**
	 * Settings service.
	 *
	 * @return Settings
	 */
	public function settings() {
		if ( ! $this->settings ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}

	/**
	 * FluentCRM field service.
	 *
	 * @return CRM_Fields
	 */
	public function crm_fields() {
		if ( ! $this->crm_fields ) {
			$this->crm_fields = new CRM_Fields();
		}

		return $this->crm_fields;
	}

	/**
	 * Checkout integration.
	 *
	 * @return Checkout|null
	 */
	public function checkout() {
		return $this->checkout;
	}

	/**
	 * Record a diagnostic line.
	 *
	 * Writes to the PHP error log when WP_DEBUG_LOG is on, and to a short
	 * rolling buffer shown on the plugin's Diagnostics tab when the admin has
	 * enabled logging. The buffer is what makes live verification of the
	 * FluentCart hook payloads practical on a site without file access.
	 *
	 * @param string $message Human readable message.
	 * @param mixed  $context Optional context, JSON-encoded for display.
	 * @return void
	 */
	public static function log( $message, $context = null ) {
		$line = '[BLT Fluent] ' . $message;

		if ( null !== $context ) {
			$encoded = wp_json_encode( $context );
			$line   .= ' ' . ( false === $encoded ? '(uncodable context)' : $encoded );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( ! self::instance()->settings()->debug_enabled() ) {
			return;
		}

		$log = get_transient( self::LOG_TRANSIENT );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'    => time(),
			'message' => (string) $message,
			'context' => null === $context ? '' : (string) wp_json_encode( $context ),
		);

		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, -self::LOG_LIMIT );
		}

		set_transient( self::LOG_TRANSIENT, $log, WEEK_IN_SECONDS );
	}

	/**
	 * The retained diagnostic log, newest last.
	 *
	 * @return array[]
	 */
	public static function log_entries() {
		$log = get_transient( self::LOG_TRANSIENT );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Empty the diagnostic log.
	 *
	 * @return void
	 */
	public static function clear_log() {
		delete_transient( self::LOG_TRANSIENT );
	}
}
