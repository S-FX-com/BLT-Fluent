<?php
/**
 * Uninstall routine.
 *
 * Runs on delete only -- never on deactivation. Deletes this plugin's own
 * configuration, and only when an administrator opted in beforehand.
 *
 * Never touched, under any setting: FluentCRM contacts, custom contact field
 * definitions or stored values; FluentCart orders, order meta, products or
 * customers; anything written by the profile-edit form. This plugin is a
 * collection mechanism, not a data owner.
 *
 * @package BLT_Fluent
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this plugin's data for the current site, if opted in.
 *
 * @return void
 */
function blt_fluent_uninstall_site() {
	$config = get_option( 'blt_fluent_config' );

	// Tolerate a JSON-encoded option.
	if ( is_string( $config ) ) {
		$decoded = json_decode( $config, true );
		$config  = is_array( $decoded ) ? $decoded : array();
	}

	// The default is to preserve. Only a deliberate opt-in triggers cleanup.
	if ( empty( $config['delete_on_uninstall'] ) ) {
		return;
	}

	delete_option( 'blt_fluent_config' );
	delete_transient( 'blt_fluent_log' );
	wp_clear_scheduled_hook( 'blt_fluent_daily_update_check' );
}

// uninstall.php runs once, not per site, so multisite needs the loop.
if ( is_multisite() ) {
	$blt_fluent_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $blt_fluent_sites as $blt_fluent_site_id ) {
		switch_to_blog( (int) $blt_fluent_site_id );
		blt_fluent_uninstall_site();
		restore_current_blog();
	}

	unset( $blt_fluent_sites, $blt_fluent_site_id );
} else {
	blt_fluent_uninstall_site();
}
