<?php
/**
 * Removes Drift: Surface's own options, transients, cron events and user meta.
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

foreach ( [ 'settings', 'log', 'sync_status', 'last_publish', 'media_map', 'white_label', 'hidden_menus' ] as $drift_surface_option ) {
	delete_option( 'drift_surface_' . $drift_surface_option );
}

foreach ( [ 'sync_lock', 'sync_records', 'schema_check' ] as $drift_surface_transient ) {
	delete_transient( 'drift_surface_' . $drift_surface_transient );
}

foreach ( [ 'daily_check', 'run_sync', 'continue_sync' ] as $drift_surface_hook ) {
	wp_unschedule_hook( 'drift_surface_' . $drift_surface_hook );
}

delete_metadata( 'user', 0, 'drift_surface_agency_user', '', true );

// Bookkeeping left by pre-release builds.
delete_option( 'drift_surface_migrated' );
delete_option( 'drift_surface_migrating' );
