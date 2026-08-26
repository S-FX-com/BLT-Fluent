<?php
/**
 * Dependency detection and notices.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * Detects FluentCart and FluentCRM.
 *
 * Detection is by class/function first and constant second: constant names are
 * the least stable part of either plugin's public surface.
 */
class Dependencies {

	/**
	 * Names of the required plugins that are not currently available.
	 *
	 * @return string[]
	 */
	public static function missing() {
		$missing = array();

		if ( ! self::fluentcart_active() ) {
			$missing[] = 'FluentCart';
		}

		if ( ! self::fluentcrm_active() ) {
			$missing[] = 'FluentCRM';
		}

		return $missing;
	}

	/**
	 * Whether every dependency is satisfied.
	 *
	 * @return bool
	 */
	public static function satisfied() {
		return array() === self::missing();
	}

	/**
	 * Whether FluentCart is loaded.
	 *
	 * @return bool
	 */
	public static function fluentcart_active() {
		return class_exists( '\FluentCart\App\Models\Order' )
			|| defined( 'FLUENTCART_PLUGIN_VERSION' )
			|| defined( 'FLUENT_CART_PLUGIN_VERSION' )
			|| defined( 'FLUENTCART_VERSION' );
	}

	/**
	 * Whether FluentCRM is loaded.
	 *
	 * @return bool
	 */
	public static function fluentcrm_active() {
		return function_exists( 'FluentCrmApi' )
			|| defined( 'FLUENTCRM' )
			|| defined( 'FLUENTCRM_PLUGIN_VERSION' );
	}

	/**
	 * Best-effort version string for FluentCart.
	 *
	 * @return string Empty string when unknown.
	 */
	public static function fluentcart_version() {
		foreach ( array( 'FLUENTCART_PLUGIN_VERSION', 'FLUENT_CART_PLUGIN_VERSION', 'FLUENTCART_VERSION' ) as $constant ) {
			if ( defined( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return '';
	}

	/**
	 * Best-effort version string for FluentCRM.
	 *
	 * @return string Empty string when unknown.
	 */
	public static function fluentcrm_version() {
		foreach ( array( 'FLUENTCRM_PLUGIN_VERSION', 'FLUENTCRM_VERSION' ) as $constant ) {
			if ( defined( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return '';
	}

	/**
	 * Print the runtime dependency notice.
	 *
	 * Nothing is deactivated here: silently self-deactivating would take the
	 * admin's field configuration out of play for a dependency that may be
	 * disabled for two minutes of troubleshooting.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = self::missing();

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			esc_html__( 'BLT Fluent is inactive.', 'blt-fluent' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names */
					__( 'It requires the following active plugins: %s', 'blt-fluent' ),
					implode( ', ', $missing )
				)
			),
			esc_html__( 'Your field configuration has been preserved and will be used again as soon as the missing plugins are active.', 'blt-fluent' )
		);
	}
}
