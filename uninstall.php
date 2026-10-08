<?php
/**
 * Removes Drift: Surface's own options and transients, under both the 3.0
 * names and the 2.x (Encore Website) names in case the migration never ran.
 *
 * Synced content (posts, images, the site settings option) is deliberately
 * left in place: uninstalling the engine shouldn't empty a live website.
 * Delete those by hand if the site is being retired.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'drift_surface_', 'encore_website_' ] as $prefix ) {
	foreach ( [ 'settings', 'log', 'api_usage', 'sync_status', 'last_publish', 'media_map', 'white_label', 'hidden_menus' ] as $option ) {
		delete_option( $prefix . $option );
	}

	foreach ( [ 'sync_lock', 'sync_records', 'airtable_pause', 'schema_check', 'link_throttle' ] as $transient ) {
		delete_transient( $prefix . $transient );
	}

	foreach ( [ 'daily_check', 'run_sync', 'continue_sync' ] as $hook ) {
		wp_unschedule_hook( $prefix . $hook );
	}

	delete_metadata( 'user', 0, $prefix . 'agency_user', '', true );
}

delete_option( 'drift_surface_migrated' );
delete_option( 'drift_surface_migrating' );
delete_option( 'encore_website_migrated' );
delete_option( 'encore_website_migrating' );
