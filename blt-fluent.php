<?php
/**
 * Plugin Name:       BLT Fluent
 * Plugin URI:        https://github.com/s-fx-com/blt-fluent
 * Description:       Collect FluentCRM custom contact fields during FluentCart checkout and write them straight to the contact record. FluentCRM stays the single source of truth.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  fluent-cart, fluent-crm
 * Author:            S-FX.com
 * Author URI:        https://s-fx.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-fluent
 * Domain Path:       /languages
 *
 * @package BLT_Fluent
 */

defined( 'ABSPATH' ) || exit;

define( 'BLT_FLUENT_VERSION', '0.2.0' );
define( 'BLT_FLUENT_FILE', __FILE__ );
define( 'BLT_FLUENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_FLUENT_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_FLUENT_BASENAME', plugin_basename( __FILE__ ) );
define( 'BLT_FLUENT_OPTION', 'blt_fluent_config' );
define( 'BLT_FLUENT_CRON_HOOK', 'blt_fluent_daily_update_check' );

if ( ! defined( 'BLT_FLUENT_GITHUB_URL' ) ) {
	define( 'BLT_FLUENT_GITHUB_URL', 'https://github.com/s-fx-com/blt-fluent/' );
}

require_once BLT_FLUENT_DIR . 'includes/class-dependencies.php';
require_once BLT_FLUENT_DIR . 'includes/class-settings.php';
require_once BLT_FLUENT_DIR . 'includes/class-crm-fields.php';
require_once BLT_FLUENT_DIR . 'includes/class-cart-context.php';
require_once BLT_FLUENT_DIR . 'includes/class-checkout.php';
require_once BLT_FLUENT_DIR . 'includes/class-companies.php';
require_once BLT_FLUENT_DIR . 'includes/class-company-shortcode.php';
require_once BLT_FLUENT_DIR . 'includes/class-updater.php';
require_once BLT_FLUENT_DIR . 'includes/class-admin.php';
require_once BLT_FLUENT_DIR . 'includes/class-plugin.php';

/**
 * Names of required plugins that are not currently available.
 *
 * Checks classes and functions rather than plugin file paths, so it stays
 * correct regardless of how the dependency was installed or renamed.
 *
 * @return string[]
 */
function blt_fluent_missing_dependencies() {
	return \BLT_Fluent\Dependencies::missing();
}

/**
 * Admin notice shown when a dependency is missing at runtime.
 *
 * @return void
 */
function blt_fluent_dependency_notice() {
	\BLT_Fluent\Dependencies::notice();
}

/**
 * The default configuration written on first activation.
 *
 * @return array
 */
function blt_fluent_default_config() {
	return \BLT_Fluent\Settings::defaults();
}

/**
 * Seed defaults only when no configuration exists yet.
 *
 * Uses add_option(), which is a no-op when the key is already present. The
 * guard is therefore structural, not dependent on the conditional.
 *
 * @return void
 */
function blt_fluent_maybe_seed_defaults() {
	\BLT_Fluent\Settings::maybe_seed_defaults();
}

/**
 * Boot the plugin. Only called once dependencies are confirmed present.
 *
 * @return void
 */
function blt_fluent_boot() {
	\BLT_Fluent\Plugin::instance()->boot();
}

/**
 * The Plugin Update Checker instance, or null when the updater is unavailable.
 *
 * @return \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\PluginUpdateChecker|object|null
 */
function blt_fluent_get_updater() {
	return \BLT_Fluent\Updater::instance()->checker();
}

register_activation_hook(
	__FILE__,
	function () {
		$missing = blt_fluent_missing_dependencies();

		if ( ! empty( $missing ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: comma-separated plugin names */
						__( 'BLT Fluent requires the following active plugins: %s', 'blt-fluent' ),
						implode( ', ', $missing )
					)
				),
				esc_html__( 'Plugin dependency check failed', 'blt-fluent' ),
				array( 'back_link' => true )
			);
		}

		// Configuration is seeded only when absent; existing config is never touched.
		blt_fluent_maybe_seed_defaults();
		\BLT_Fluent\Updater::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		// Unschedule cron and nothing else. Deactivation is always non-destructive:
		// the field configuration in wp_options survives untouched. See spec section 6.2.
		\BLT_Fluent\Updater::unschedule();
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! empty( blt_fluent_missing_dependencies() ) ) {
			add_action( 'admin_notices', 'blt_fluent_dependency_notice' );

			// Register nothing else. Config in wp_options is preserved. The one
			// exception is the company shortcode, which is registered as a no-op
			// so a member-facing page shows nothing rather than raw shortcode text.
			\BLT_Fluent\Company_Shortcode::register_fallback();

			return;
		}

		blt_fluent_boot();
	},
	20 // After FluentCart and FluentCRM have loaded.
);
