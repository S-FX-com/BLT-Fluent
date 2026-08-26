<?php
/**
 * Plugin Update Checker wiring and the daily cron event.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * Updates come from a private GitHub repo via Plugin Update Checker.
 *
 * PUC's own scheduler runs every N hours counted from registration, so it cannot
 * target a clock time. It is disabled here ($check_period = 0) and driven from a
 * WP-Cron event anchored to the site's local midnight instead. The "Check for
 * updates" link on the Plugins screen keeps working for manual checks.
 */
class Updater {

	/**
	 * Singleton instance.
	 *
	 * @var Updater|null
	 */
	private static $instance = null;

	/**
	 * The PUC instance, or null when unavailable.
	 *
	 * @var object|null
	 */
	private $checker = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build the checker and register the cron callback.
	 *
	 * Called from plugins_loaded -- never from an admin-only hook, or updates
	 * become invisible to WP-CLI and management tools and the cron callback
	 * finds no instance to drive.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( BLT_FLUENT_CRON_HOOK, array( $this, 'run_scheduled_check' ) );

		// Self-heal: an install that predates the cron event, or one whose
		// schedule was cleared by hand, gets it back without a reactivation.
		add_action( 'admin_init', array( self::class, 'schedule' ) );

		$this->checker = $this->build();
	}

	/**
	 * The PUC instance, or null when the library or config is missing.
	 *
	 * @return object|null
	 */
	public function checker() {
		return $this->checker;
	}

	/**
	 * Absolute path to the Plugin Update Checker bootstrap, if present.
	 *
	 * Both the plain drop-in layout and Composer's vendor layout are supported.
	 *
	 * @return string Empty string when the library is not installed.
	 */
	public static function library_path() {
		$candidates = array(
			BLT_FLUENT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php',
			BLT_FLUENT_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * The GitHub token used for the private repo, if configured.
	 *
	 * Define BLT_FLUENT_GH_TOKEN in wp-config.php -- a fine-grained,
	 * read-only PAT scoped to this one repository. Never commit it.
	 *
	 * @return string
	 */
	public static function token() {
		$token = '';

		if ( defined( 'BLT_FLUENT_GH_TOKEN' ) ) {
			$token = (string) constant( 'BLT_FLUENT_GH_TOKEN' );
		}

		if ( '' === $token ) {
			$env = getenv( 'BLT_FLUENT_GH_TOKEN' );
			$token = is_string( $env ) ? $env : '';
		}

		/**
		 * Filter the GitHub token used to read the update repository.
		 *
		 * @param string $token Token.
		 */
		return (string) apply_filters( 'blt_fluent/github_token', $token );
	}

	/**
	 * Instantiate Plugin Update Checker.
	 *
	 * @return object|null
	 */
	private function build() {
		$library = self::library_path();

		if ( '' === $library ) {
			Plugin::log( 'Updater inactive: plugin-update-checker is not installed' );

			return null;
		}

		require_once $library;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			Plugin::log( 'Updater inactive: PucFactory v5 not found' );

			return null;
		}

		/**
		 * Filter the repository the updater reads from.
		 *
		 * @param string $url Repository URL.
		 */
		$repository = (string) apply_filters( 'blt_fluent/github_url', BLT_FLUENT_GITHUB_URL );

		try {
			$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				$repository,
				BLT_FLUENT_FILE,
				'blt-fluent',
				0 // Disable PUC's own scheduler; our cron event drives it.
			);

			if ( method_exists( $checker, 'setBranch' ) ) {
				/**
				 * Filter the branch releases are read from.
				 *
				 * @param string $branch Branch name.
				 */
				$checker->setBranch( (string) apply_filters( 'blt_fluent/github_branch', 'main' ) );
			}

			$token = self::token();

			if ( '' !== $token && method_exists( $checker, 'setAuthentication' ) ) {
				$checker->setAuthentication( $token );
			}

			if ( method_exists( $checker, 'getVcsApi' ) ) {
				$api = $checker->getVcsApi();

				if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
					$api->enableReleaseAssets( '/\.zip($|[?&#])/i' );
				}
			}

			return $checker;
		} catch ( \Throwable $e ) {
			Plugin::log( 'Updater failed to initialise', $e->getMessage() );

			return null;
		}
	}

	/**
	 * Schedule the daily check at the site's local midnight.
	 *
	 * Idempotent: an existing schedule is left alone.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( wp_next_scheduled( BLT_FLUENT_CRON_HOOK ) ) {
			return;
		}

		$midnight = new \DateTime( 'tomorrow midnight', wp_timezone() );

		// getTimestamp() on a site-timezone DateTime yields the correct UTC
		// instant for local midnight, DST included.
		wp_schedule_event( $midnight->getTimestamp(), 'daily', BLT_FLUENT_CRON_HOOK );
	}

	/**
	 * Remove the cron event. Called on deactivation and uninstall.
	 *
	 * No options are touched here: deactivation must never cost an admin their
	 * field configuration.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( BLT_FLUENT_CRON_HOOK );
	}

	/**
	 * Next scheduled run as a UTC timestamp.
	 *
	 * @return int Zero when nothing is scheduled.
	 */
	public static function next_run() {
		$next = wp_next_scheduled( BLT_FLUENT_CRON_HOOK );

		return $next ? (int) $next : 0;
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run_scheduled_check() {
		$checker = $this->checker();

		if ( ! $checker || ! method_exists( $checker, 'checkForUpdates' ) ) {
			Plugin::log( 'Scheduled update check skipped: no checker instance' );

			return;
		}

		$checker->checkForUpdates();
		Plugin::log( 'Scheduled update check ran' );
	}

	/**
	 * Run a check immediately and describe the result.
	 *
	 * @return string Human readable outcome.
	 */
	public function check_now() {
		$checker = $this->checker();

		if ( ! $checker || ! method_exists( $checker, 'checkForUpdates' ) ) {
			return __( 'Update checker unavailable — is vendor/plugin-update-checker installed?', 'blt-fluent' );
		}

		$update = $checker->checkForUpdates();

		if ( $update && isset( $update->version ) ) {
			return sprintf(
				/* translators: %s: version number */
				__( 'Version %s is available.', 'blt-fluent' ),
				$update->version
			);
		}

		return __( 'No update available. You are on the latest version.', 'blt-fluent' );
	}
}
