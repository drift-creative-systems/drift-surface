<?php
/**
 * Removes Drift Website's own options and transients.
 *
 * Synced content (posts, images, the site settings option) is deliberately
 * left in place: uninstalling the engine shouldn't empty a live website.
 * Delete those by hand if the site is being retired.
 *
 * @package Drift_Website
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [
	'drift_website_settings',
	'drift_website_log',
	'drift_website_api_usage',
	'drift_website_sync_status',
	'drift_website_last_publish',
	'drift_website_media_map',
	'drift_website_white_label',
	'drift_website_hidden_menus',
] as $option ) {
	delete_option( $option );
}

foreach ( [
	'drift_website_sync_lock',
	'drift_website_sync_records',
	'drift_website_airtable_pause',
	'drift_website_schema_check',
] as $transient ) {
	delete_transient( $transient );
}

foreach ( [ 'drift_website_daily_check', 'drift_website_run_sync', 'drift_website_continue_sync' ] as $hook ) {
	wp_unschedule_hook( $hook );
}

delete_metadata( 'user', 0, 'drift_website_agency_user', '', true );
