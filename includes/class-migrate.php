<?php
/**
 * class-migrate.php — one-off move of 1.x (Drift Website) data to 2.0 names.
 *
 * Runs once per site, early on plugins_loaded (auto-updates never fire the
 * activation hook) and again on activation. Renames rows in place, so values
 * and autoload flags are kept and synced posts stay owned by the sync:
 *
 *   options    drift_website_* → encore_website_*, drift_site_settings → encore_site_settings
 *   post meta  _drift_airtable_id, _drift_airtable_hash, _drift_entity, _drift_links_*,
 *              _drift_airtable_attachment_id, _drift_placeholder → _encore_*
 *   user meta  drift_website_agency_user → encore_website_agency_user
 *   secrets    re-sealed under the 2.0 key (Encore_Website_Crypto::reseal())
 *   cron       old hooks unscheduled; the daily check is rescheduled
 *   transients 1.x caches and locks deleted (they rebuild themselves)
 *
 * A new name that already holds data is never overwritten.
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Encore_Website_Migrate {

	/** Set once the move is done (or there was nothing to move). Autoloaded, so checking it is free. */
	const DONE_OPTION = 'encore_website_migrated';
	const LOCK_OPTION = 'encore_website_migrating';

	const OPTIONS = [
		'drift_website_settings'     => 'encore_website_settings',
		'drift_website_log'          => 'encore_website_log',
		'drift_website_api_usage'    => 'encore_website_api_usage',
		'drift_website_sync_status'  => 'encore_website_sync_status',
		'drift_website_last_publish' => 'encore_website_last_publish',
		'drift_website_media_map'    => 'encore_website_media_map',
		'drift_website_white_label'  => 'encore_website_white_label',
		'drift_website_hidden_menus' => 'encore_website_hidden_menus',
		'drift_site_settings'        => 'encore_site_settings',
	];

	const POST_META = [
		'_drift_airtable_id'            => '_encore_airtable_id',
		'_drift_airtable_hash'          => '_encore_airtable_hash',
		'_drift_entity'                 => '_encore_entity',
		'_drift_airtable_attachment_id' => '_encore_airtable_attachment_id',
		'_drift_placeholder'            => '_encore_placeholder',
	];

	const POST_META_PREFIXES = [
		'_drift_links_' => '_encore_links_',
	];

	const USER_META = [
		'drift_website_agency_user' => 'encore_website_agency_user',
	];

	const TRANSIENTS = [
		'drift_website_sync_lock',
		'drift_website_sync_records',
		'drift_website_airtable_pause',
		'drift_website_schema_check',
		'drift_website_link_throttle',
	];

	const CRON_HOOKS = [
		'drift_website_daily_check',
		'drift_website_run_sync',
		'drift_website_continue_sync',
	];

	public static function maybe_run(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		// add_option() fails if the row exists, so only one request migrates.
		// A lock older than 10 minutes is from a request that died; take over.
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK_OPTION ) < 10 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( self::LOCK_OPTION, time(), false );
		}

		try {
			$moved = self::run();
			update_option( self::DONE_OPTION, ENCORE_WEBSITE_VERSION, true );
			if ( $moved ) {
				Encore_Website_Log::info( 'Moved Drift Website 1.x settings and sync data to Encore Website names.', 'migrate' );
			}
		} catch ( Throwable $e ) {
			// Leave DONE_OPTION unset so the next request retries.
			error_log( 'Encore Website migration failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * @return bool Whether any 1.x data was found and moved.
	 */
	private static function run(): bool {
		global $wpdb;

		$moved = false;

		foreach ( self::OPTIONS as $old => $new ) {
			$moved = self::rename_option( $old, $new ) || $moved;
		}

		foreach ( self::POST_META as $old => $new ) {
			$moved = (bool) $wpdb->update( $wpdb->postmeta, [ 'meta_key' => $new ], [ 'meta_key' => $old ] ) || $moved; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		foreach ( self::POST_META_PREFIXES as $old => $new ) {
			$moved = (bool) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s",
					$new,
					strlen( $old ) + 1,
					$wpdb->esc_like( $old ) . '%'
				)
			) || $moved;
		}

		foreach ( self::USER_META as $old => $new ) {
			$moved = (bool) $wpdb->update( $wpdb->usermeta, [ 'meta_key' => $new ], [ 'meta_key' => $old ] ) || $moved; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		if ( $moved ) {
			wp_cache_flush(); // Meta caches hold the old keys.
		}

		self::reseal_secrets();

		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		$had_cron = false;
		foreach ( self::CRON_HOOKS as $hook ) {
			$had_cron = wp_unschedule_hook( $hook ) > 0 || $had_cron;
		}
		if ( $had_cron && '1' === (string) Encore_Website_Settings::get( 'daily_check', '1' ) ) {
			Encore_Website_Publish::schedule_daily_check();
		}

		return $moved || $had_cron;
	}

	/**
	 * Renames one option row, keeping its value and autoload flag. Skips when
	 * the new name already exists.
	 */
	private static function rename_option( string $old, string $new ): bool {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( (int) $exists ) {
			return false;
		}

		$renamed = (bool) $wpdb->update( $wpdb->options, [ 'option_name' => $new ], [ 'option_name' => $old ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $renamed ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( $old, 'options' );
			wp_cache_delete( $new, 'options' );
		}
		return $renamed;
	}

	/** Re-seals the token and publish secret under the 2.0 key. */
	private static function reseal_secrets(): void {
		$saved = get_option( Encore_Website_Settings::OPTION );
		if ( ! is_array( $saved ) ) {
			return;
		}

		$changed = false;
		foreach ( [ 'token', 'publish_secret' ] as $key ) {
			if ( empty( $saved[ $key ] ) ) {
				continue;
			}
			$resealed = Encore_Website_Crypto::reseal( (string) $saved[ $key ] );
			if ( $resealed !== $saved[ $key ] ) {
				$saved[ $key ] = $resealed;
				$changed       = true;
			}
		}

		if ( $changed ) {
			update_option( Encore_Website_Settings::OPTION, $saved, false );
		}
	}
}
