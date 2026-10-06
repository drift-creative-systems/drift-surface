<?php
/**
 * Legacy loader for sites updated from Drift Website 1.x.
 *
 * Those sites have "drift-website/drift-website.php" in active_plugins, and an
 * update keeps the folder name, so without this file WordPress would find the
 * plugin missing and deactivate it. On first load this points the active
 * entry at encore-website.php in the same folder, then loads it.
 *
 * Deliberately has no plugin header: one plugin, one entry on the Plugins
 * screen. Safe to delete once no 1.x sites remain.
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$encore_website_old = plugin_basename( __FILE__ );
$encore_website_new = plugin_basename( __DIR__ . '/encore-website.php' );

$encore_website_active = (array) get_option( 'active_plugins', [] );
$encore_website_index  = array_search( $encore_website_old, $encore_website_active, true );
if ( false !== $encore_website_index ) {
	if ( in_array( $encore_website_new, $encore_website_active, true ) ) {
		unset( $encore_website_active[ $encore_website_index ] );
	} else {
		$encore_website_active[ $encore_website_index ] = $encore_website_new;
	}
	update_option( 'active_plugins', array_values( $encore_website_active ) );
}

if ( is_multisite() ) {
	$encore_website_network = (array) get_site_option( 'active_sitewide_plugins', [] );
	if ( isset( $encore_website_network[ $encore_website_old ] ) ) {
		$encore_website_network[ $encore_website_new ] = $encore_website_network[ $encore_website_old ];
		unset( $encore_website_network[ $encore_website_old ] );
		update_site_option( 'active_sitewide_plugins', $encore_website_network );
	}
	unset( $encore_website_network );
}

unset( $encore_website_old, $encore_website_new, $encore_website_active, $encore_website_index );

require_once __DIR__ . '/encore-website.php';
