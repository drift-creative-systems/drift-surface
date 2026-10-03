<?php
/**
 * Plugin Name:       Drift Website
 * Plugin URI:        https://github.com/drift-creative-systems/drift-website
 * Description:       Airtable-powered site engine for Drift App Suite products (Encore and friends). One-way Airtable → WordPress sync driven by a per-product map, Publish webhook, white label, admin access control and the page setup wizard.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Drift Creative Systems
 * Author URI:        https://github.com/drift-creative-systems
 * License:           GPL-2.0-or-later
 * Text Domain:       drift-website
 *
 * Built from Bonsai's own vision-website plugin (sync patterns, white label,
 * admin access, setup wizard). The Airtable connection layer that used to be
 * borrowed from a third-party plugin is rebuilt here from scratch — see
 * CLAUDE.md "Provenance".
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DRIFT_WEBSITE_VERSION', '1.0.0' );
define( 'DRIFT_WEBSITE_FILE', __FILE__ );
define( 'DRIFT_WEBSITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DRIFT_WEBSITE_URL', plugin_dir_url( __FILE__ ) );
define( 'DRIFT_WEBSITE_REPO', 'https://github.com/drift-creative-systems/drift-website' );

/*
|--------------------------------------------------------------------------
| Self-updates from GitHub releases
|--------------------------------------------------------------------------
| Plugin Update Checker is bundled directly (lib/), not via Composer, so two
| plugins shipping it can never collide on Composer's autoloader class. PUC's
| own loader is safe to include from multiple plugins.
|
| Private repo? Define DRIFT_WEBSITE_GITHUB_TOKEN in wp-config.php with a
| fine-grained, read-only token for drift-creative-systems/drift-website.
*/
$drift_website_puc = DRIFT_WEBSITE_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $drift_website_puc ) ) {
	require_once $drift_website_puc;

	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$drift_website_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			DRIFT_WEBSITE_REPO,
			__FILE__,
			'drift-website',
			6 // Hours between update checks.
		);
		$drift_website_updater->setBranch( 'main' );
		$drift_website_updater->getVcsApi()->enableReleaseAssets();

		if ( defined( 'DRIFT_WEBSITE_GITHUB_TOKEN' ) && DRIFT_WEBSITE_GITHUB_TOKEN ) {
			$drift_website_updater->setAuthentication( DRIFT_WEBSITE_GITHUB_TOKEN );
		}
	}
}
unset( $drift_website_puc );

require_once DRIFT_WEBSITE_DIR . 'includes/class-crypto.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-settings.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-log.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-airtable.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-media.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-map.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-content-types.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-sync-engine.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-publish.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-forms.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-admin-access.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-white-label.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-page-creator.php';
require_once DRIFT_WEBSITE_DIR . 'includes/class-admin-page.php';
require_once DRIFT_WEBSITE_DIR . 'includes/functions.php';

add_action( 'plugins_loaded', [ 'Drift_Website_Plugin', 'init' ] );

final class Drift_Website_Plugin {

	public static function init(): void {
		Drift_Website_Content_Types::init();
		Drift_Website_Sync_Engine::init();
		Drift_Website_Publish::init();
		Drift_Website_Forms::init();
		Drift_Website_Admin_Access::init();
		Drift_Website_White_Label::init();
		Drift_Website_Page_Creator::init();
		Drift_Website_Admin_Page::init();

		/**
		 * Fires once Drift Website has booted. Product themes hook in here.
		 */
		do_action( 'drift_website_loaded' );
	}

	public static function activate(): void {
		Drift_Website_Settings::ensure_publish_secret();
		Drift_Website_Content_Types::register();
		Drift_Website_Publish::schedule_daily_check();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		Drift_Website_Publish::unschedule();
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, [ 'Drift_Website_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Drift_Website_Plugin', 'deactivate' ] );
