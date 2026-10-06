<?php
/**
 * Plugin Name:       Encore Website
 * Plugin URI:        https://github.com/drift-creative-systems/encore-website
 * Description:       Airtable-powered site engine for Encore band and artist websites. One-way Airtable → WordPress sync driven by a product map, Publish webhook, white label, admin access control and the page setup wizard.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Drift Creative Systems
 * Author URI:        https://driftcreativesystems.co.uk/
 * License:           GPL-2.0-or-later (PHP files); proprietary (all other files). See LICENSE.
 * License URI:       https://github.com/drift-creative-systems/encore-website/blob/main/LICENSE
 * Text Domain:       encore-website
 *
 * Generic engine: Airtable → WordPress sync, forms, white label, admin
 * access and the setup wizard. Product specifics live in maps/.
 *
 * Formerly "Drift Website". Sites updated from 1.x keep the drift-website/
 * folder and load this file through drift-website.php; includes/compat.php
 * and includes/class-migrate.php carry the old names and data across.
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ENCORE_WEBSITE_VERSION' ) ) {
	return; // Already loaded (e.g. via the legacy drift-website.php loader).
}

define( 'ENCORE_WEBSITE_VERSION', '2.0.0' );
define( 'ENCORE_WEBSITE_FILE', __FILE__ );
define( 'ENCORE_WEBSITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENCORE_WEBSITE_URL', plugin_dir_url( __FILE__ ) );
define( 'ENCORE_WEBSITE_REPO', 'https://github.com/drift-creative-systems/encore-website' );

/**
 * A wp-config.php constant by its short name, e.g. 'AIRTABLE_TOKEN'. Reads
 * ENCORE_WEBSITE_{name}, then the 1.x DRIFT_WEBSITE_{name}, so existing
 * wp-config.php files keep working.
 *
 * @param string $name Constant name without the prefix.
 * @return string Empty when neither is defined.
 */
function encore_website_constant( string $name ): string {
	foreach ( [ 'ENCORE_WEBSITE_', 'DRIFT_WEBSITE_' ] as $prefix ) {
		if ( defined( $prefix . $name ) && constant( $prefix . $name ) ) {
			return (string) constant( $prefix . $name );
		}
	}
	return '';
}

/*
|--------------------------------------------------------------------------
| Self-updates from GitHub releases
|--------------------------------------------------------------------------
| Plugin Update Checker is bundled directly (lib/), not via Composer, so two
| plugins shipping it can never collide on Composer's autoloader class. PUC's
| own loader is safe to include from multiple plugins.
|
| Private repo? Define ENCORE_WEBSITE_GITHUB_TOKEN in wp-config.php with a
| fine-grained, read-only token for drift-creative-systems/encore-website.
*/
$encore_website_puc = ENCORE_WEBSITE_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $encore_website_puc ) ) {
	require_once $encore_website_puc;

	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$encore_website_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			ENCORE_WEBSITE_REPO,
			__FILE__,
			'encore-website',
			6 // Hours between update checks.
		);
		$encore_website_updater->setBranch( 'main' );
		$encore_website_updater->getVcsApi()->enableReleaseAssets();

		$encore_website_github_token = encore_website_constant( 'GITHUB_TOKEN' );
		if ( '' !== $encore_website_github_token ) {
			$encore_website_updater->setAuthentication( $encore_website_github_token );
		}
		unset( $encore_website_github_token );
	}
}
unset( $encore_website_puc );

require_once ENCORE_WEBSITE_DIR . 'includes/class-crypto.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-settings.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-log.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-airtable.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-media.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-map.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-content-types.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-sync-engine.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-publish.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-forms.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-admin-access.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-white-label.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-page-creator.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-theme-check.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-admin-page.php';
require_once ENCORE_WEBSITE_DIR . 'includes/class-migrate.php';
require_once ENCORE_WEBSITE_DIR . 'includes/functions.php';
require_once ENCORE_WEBSITE_DIR . 'includes/compat.php';

// Before anything reads options: carries 1.x (Drift Website) data across once.
add_action( 'plugins_loaded', [ 'Encore_Website_Migrate', 'maybe_run' ], 1 );
add_action( 'plugins_loaded', [ 'Encore_Website_Plugin', 'init' ] );

final class Encore_Website_Plugin {

	public static function init(): void {
		Encore_Website_Content_Types::init();
		Encore_Website_Sync_Engine::init();
		Encore_Website_Publish::init();
		Encore_Website_Forms::init();
		Encore_Website_Admin_Access::init();
		Encore_Website_White_Label::init();
		Encore_Website_Page_Creator::init();
		Encore_Website_Theme_Check::init();
		Encore_Website_Admin_Page::init();

		/**
		 * Fires once Encore Website has booted. Product themes hook in here.
		 */
		do_action( 'encore_website_loaded' );
	}

	public static function activate(): void {
		Encore_Website_Migrate::maybe_run();
		Encore_Website_Settings::ensure_publish_secret();
		Encore_Website_Content_Types::register();
		Encore_Website_Publish::schedule_daily_check();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		Encore_Website_Publish::unschedule();
		flush_rewrite_rules();
	}
}

register_activation_hook( ENCORE_WEBSITE_FILE, [ 'Encore_Website_Plugin', 'activate' ] );
register_deactivation_hook( ENCORE_WEBSITE_FILE, [ 'Encore_Website_Plugin', 'deactivate' ] );
