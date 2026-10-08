<?php
/**
 * class-migrate.php — one-off move of 2.x (Encore Website) data to 3.0 names.
 *
 * Runs once per site, early on plugins_loaded (auto-updates never fire the
 * activation hook) and again on activation. Renames rows in place, so values
 * and autoload flags are kept and synced posts stay owned by the sync:
 *
 *   options    encore_website_* → drift_surface_*
 *   settings   product "encore" → "surface" (the map was renamed)
 *   post meta  _encore_airtable_id, _encore_airtable_hash, _encore_entity, _encore_links_*,
 *              _encore_airtable_attachment_id, _encore_placeholder → _drift_surface_*
 *   user meta  encore_website_agency_user → drift_surface_agency_user
 *   secrets    re-sealed under the 3.0 key (Drift_Surface_Crypto::reseal())
 *   cron       old hooks unscheduled; the daily check is rescheduled
 *   transients 2.x caches and locks deleted (they rebuild themselves)
 *
 * Map-owned data (encore_* post types and taxonomies, encore_site_settings,
 * field meta) is the Encore theme's contract and isn't touched.
 *
 * A new name that already holds data is never overwritten.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Migrate {

	/** Set once the move is done (or there was nothing to move). Autoloaded, so checking it is free. */
	const DONE_OPTION = 'drift_surface_migrated';
	const LOCK_OPTION = 'drift_surface_migrating';

	const OPTIONS = [
		'encore_website_settings'     => 'drift_surface_settings',
		'encore_website_log'          => 'drift_surface_log',
		'encore_website_api_usage'    => 'drift_surface_api_usage',
		'encore_website_sync_status'  => 'drift_surface_sync_status',
		'encore_website_last_publish' => 'drift_surface_last_publish',
		'encore_website_media_map'    => 'drift_surface_media_map',
		'encore_website_white_label'  => 'drift_surface_white_label',
		'encore_website_hidden_menus' => 'drift_surface_hidden_menus',
	];

	const POST_META = [
		'_encore_airtable_id'            => '_drift_surface_airtable_id',
		'_encore_airtable_hash'          => '_drift_surface_airtable_hash',
		'_encore_entity'                 => '_drift_surface_entity',
		'_encore_airtable_attachment_id' => '_drift_surface_airtable_attachment_id',
		'_encore_placeholder'            => '_drift_surface_placeholder',
	];

	const POST_META_PREFIXES = [
		'_encore_links_' => '_drift_surface_links_',
	];

	const USER_META = [
		'encore_website_agency_user' => 'drift_surface_agency_user',
	];

	const TRANSIENTS = [
		'encore_website_sync_lock',
		'encore_website_sync_records',
		'encore_website_airtable_pause',
		'encore_website_schema_check',
		'encore_website_link_throttle',
	];

	const CRON_HOOKS = [
		'encore_website_daily_check',
		'encore_website_run_sync',
		'encore_website_continue_sync',
	];

	/** 2.x bookkeeping, deleted once the move is done. */
	const OBSOLETE_OPTIONS = [
		'encore_website_migrated',
		'encore_website_migrating',
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
			update_option( self::DONE_OPTION, DRIFT_SURFACE_VERSION, true );
			if ( $moved ) {
				Drift_Surface_Log::info( 'Moved Encore Website 2.x settings and sync data to Drift: Surface names.', 'migrate' );
			}
		} catch ( Throwable $e ) {
			// Leave DONE_OPTION unset so the next request retries.
			error_log( 'Drift: Surface migration failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * @return bool Whether any 2.x data was found and moved.
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

		self::update_settings();

		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		foreach ( self::OBSOLETE_OPTIONS as $option ) {
			delete_option( $option );
		}

		$had_cron = false;
		foreach ( self::CRON_HOOKS as $hook ) {
			$had_cron = wp_unschedule_hook( $hook ) > 0 || $had_cron;
		}
		if ( $had_cron && '1' === (string) Drift_Surface_Settings::get( 'daily_check', '1' ) ) {
			Drift_Surface_Publish::schedule_daily_check();
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

	/**
	 * Points the product at the renamed map and re-seals the token and publish
	 * secret under the 3.0 key.
	 */
	private static function update_settings(): void {
		$saved = get_option( Drift_Surface_Settings::OPTION );
		if ( ! is_array( $saved ) ) {
			return;
		}

		$changed = false;

		if ( 'encore' === ( $saved['product'] ?? '' ) ) {
			$saved['product'] = 'surface';
			$changed          = true;
		}

		foreach ( [ 'token', 'publish_secret' ] as $key ) {
			if ( empty( $saved[ $key ] ) ) {
				continue;
			}
			$resealed = Drift_Surface_Crypto::reseal( (string) $saved[ $key ] );
			if ( $resealed !== $saved[ $key ] ) {
				$saved[ $key ] = $resealed;
				$changed       = true;
			}
		}

		if ( $changed ) {
			update_option( Drift_Surface_Settings::OPTION, $saved, false );
		}
	}
}
