<?php
/**
 * Plugin Name:       Drift: Surface
 * Plugin URI:        https://github.com/drift-creative-systems/drift-surface
 * Update URI:        https://github.com/drift-creative-systems/drift-surface
 * Description:       Drift: Surface — the site engine for artist websites. Syncs content one way from a Drift: Surface Hub or Airtable into WordPress via a product map, with a Publish webhook, white label, admin access control and the page setup wizard.
 * Version:           3.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Drift Creative Systems
 * Author URI:        https://driftcreativesystems.co.uk/
 * License:           GPL-2.0-or-later (PHP files); proprietary (all other files). See LICENSE.
 * License URI:       https://github.com/drift-creative-systems/drift-surface/blob/main/LICENSE
 * Text Domain:       drift-surface
 *
 * Generic engine: Airtable → WordPress sync, forms, white label, admin
 * access and the setup wizard. Product specifics live in maps/.
 *
 * Formerly "Encore Website" (2.x) and "Drift Website" (1.x). includes/compat.php
 * keeps the 2.x encore_website_* names working for the Encore theme, and
 * includes/class-migrate.php moves 2.x stored data to the 3.0 names once.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'DRIFT_SURFACE_VERSION' ) ) {
	return; // Already loaded (e.g. a second copy in another folder).
}

define( 'DRIFT_SURFACE_VERSION', '3.0.0' );
define( 'DRIFT_SURFACE_FILE', __FILE__ );
define( 'DRIFT_SURFACE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DRIFT_SURFACE_URL', plugin_dir_url( __FILE__ ) );
define( 'DRIFT_SURFACE_REPO', 'https://github.com/drift-creative-systems/drift-surface' );

/**
 * A wp-config.php constant by its short name, e.g. 'AIRTABLE_TOKEN'. Reads
 * DRIFT_SURFACE_{name}, then the 2.x ENCORE_WEBSITE_{name}, so existing
 * wp-config.php files keep working.
 *
 * @param string $name Constant name without the prefix.
 * @return string Empty when neither is defined.
 */
function drift_surface_constant( string $name ): string {
	foreach ( [ 'DRIFT_SURFACE_', 'ENCORE_WEBSITE_' ] as $prefix ) {
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
| Private repo? Define DRIFT_SURFACE_GITHUB_TOKEN in wp-config.php with a
| fine-grained, read-only token for drift-creative-systems/drift-surface.
*/
$drift_surface_puc = DRIFT_SURFACE_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $drift_surface_puc ) ) {
	require_once $drift_surface_puc;

	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$drift_surface_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			DRIFT_SURFACE_REPO,
			__FILE__,
			'drift-surface',
			6 // Hours between update checks.
		);
		$drift_surface_updater->setBranch( 'main' );
		$drift_surface_updater->getVcsApi()->enableReleaseAssets();

		$drift_surface_github_token = drift_surface_constant( 'GITHUB_TOKEN' );
		if ( '' !== $drift_surface_github_token ) {
			$drift_surface_updater->setAuthentication( $drift_surface_github_token );
		}
		unset( $drift_surface_github_token );
	}
}
unset( $drift_surface_puc );

require_once DRIFT_SURFACE_DIR . 'includes/class-crypto.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-settings.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-log.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-airtable.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-media.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-map.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-content-types.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-sync-engine.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-publish.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-forms.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-admin-access.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-white-label.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-page-creator.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-theme-check.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-admin-page.php';
require_once DRIFT_SURFACE_DIR . 'includes/class-migrate.php';
require_once DRIFT_SURFACE_DIR . 'includes/functions.php';
require_once DRIFT_SURFACE_DIR . 'includes/compat.php';

// Before anything reads options: carries 2.x (Encore Website) data across once.
add_action( 'plugins_loaded', [ 'Drift_Surface_Migrate', 'maybe_run' ], 1 );
add_action( 'plugins_loaded', [ 'Drift_Surface_Plugin', 'init' ] );

final class Drift_Surface_Plugin {

	public static function init(): void {
		Drift_Surface_Content_Types::init();
		Drift_Surface_Sync_Engine::init();
		Drift_Surface_Publish::init();
		Drift_Surface_Forms::init();
		Drift_Surface_Admin_Access::init();
		Drift_Surface_White_Label::init();
		Drift_Surface_Page_Creator::init();
		Drift_Surface_Theme_Check::init();
		Drift_Surface_Admin_Page::init();

		/**
		 * Fires once Drift: Surface has booted. Product themes hook in here.
		 */
		do_action( 'drift_surface_loaded' );
	}

	public static function activate(): void {
		Drift_Surface_Migrate::maybe_run();
		Drift_Surface_Settings::ensure_publish_secret();
		Drift_Surface_Content_Types::register();
		Drift_Surface_Publish::schedule_daily_check();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		Drift_Surface_Publish::unschedule();
		flush_rewrite_rules();
	}
}

register_activation_hook( DRIFT_SURFACE_FILE, [ 'Drift_Surface_Plugin', 'activate' ] );
register_deactivation_hook( DRIFT_SURFACE_FILE, [ 'Drift_Surface_Plugin', 'deactivate' ] );
