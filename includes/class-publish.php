<?php
/**
 * class-publish.php — how a sync gets started.
 *
 * 1. Publish (the normal route). The artist or label presses Publish in the
 *    Drift: Surface Hub, which stamps the Site Settings "Last Published"
 *    field and POSTs to /wp-json/drift-surface/v1/publish with the site's
 *    publish secret (drift-hub/includes/class-publish.php). The site queues a
 *    sync in the background and answers 202 straight away. Roughly one hub
 *    request per table.
 *
 * 2. Daily safety check. Once a day the site reads one field — the settings
 *    row's "Last Published" stamp — and runs a full sync only if it differs
 *    from the last one synced. Catches a webhook that never arrived for one
 *    request a day instead of polling every table.
 *
 * 3. "Sync now" in the admin bar, for agency staff and editors.
 *
 * Also GET /wp-json/drift-surface/v1/status (same secret) for uptime and
 * monitoring tools.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Publish {

	const NAMESPACE      = 'drift-surface/v1';
	const HOOK_DAILY     = 'drift_surface_daily_check';
	const ACTION_SYNC    = 'drift_surface_sync_now';
	const CAPABILITY     = 'edit_theme_options';
	const PUBLISH_OPTION = 'drift_surface_last_publish';
	const NOTICE_PREFIX  = 'drift_surface_sync_notice_';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( self::HOOK_DAILY, [ __CLASS__, 'daily_check' ] );

		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_node' ], 100 );
		add_action( 'admin_post_' . self::ACTION_SYNC, [ __CLASS__, 'handle_sync_now' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
	}

	/* ── REST ────────────────────────────────────────────────────────── */

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/publish',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_publish' ],
				'permission_callback' => [ __CLASS__, 'verify_secret' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_status' ],
				'permission_callback' => [ __CLASS__, 'verify_secret' ],
			]
		);
	}

	/**
	 * Accepts the secret as an X-Drift-Surface-Secret header (preferred) or a
	 * "secret" body/query parameter (for tools that can't set headers).
	 */
	public static function verify_secret( WP_REST_Request $request ): bool {
		$expected = Drift_Surface_Settings::publish_secret();
		$given    = (string) ( $request->get_header( 'x_drift_surface_secret' ) ?: $request->get_param( 'secret' ) );

		return '' !== $expected && '' !== $given && hash_equals( $expected, $given );
	}

	public static function rest_publish( WP_REST_Request $request ): WP_REST_Response {
		$stamp = sanitize_text_field( (string) $request->get_param( 'last_published' ) );

		update_option(
			self::PUBLISH_OPTION,
			[
				'received_at' => time(),
				'stamp'       => $stamp,
				'by'          => sanitize_text_field( (string) $request->get_param( 'by' ) ),
			],
			false
		);

		Drift_Surface_Log::info( 'Publish received from the hub' . ( $stamp ? ' (' . $stamp . ')' : '' ) . ' — sync queued.', 'publish' );
		Drift_Surface_Sync_Engine::queue( 'publish' );

		return new WP_REST_Response( [ 'queued' => true, 'site' => home_url( '/' ) ], 202 );
	}

	public static function rest_status(): WP_REST_Response {
		$status = Drift_Surface_Sync_Engine::status();

		return new WP_REST_Response(
			[
				'site'          => home_url( '/' ),
				'product'       => Drift_Surface_Map::current()['slug'],
				'plugin'        => DRIFT_SURFACE_VERSION,
				'connected'     => Drift_Surface_Settings::is_connected(),
				'last_run'      => (int) ( $status['last_run'] ?? 0 ),
				'last_ok'       => (int) ( $status['last_ok'] ?? 0 ),
				'ok'            => (bool) ( $status['ok'] ?? false ),
				'messages'      => (array) ( $status['messages'] ?? [] ),
				'last_publish'  => get_option( self::PUBLISH_OPTION, [] ),
			],
			200
		);
	}

	public static function webhook_url(): string {
		return rest_url( self::NAMESPACE . '/publish' );
	}

	/* ── Daily safety check ──────────────────────────────────────────── */

	public static function schedule_daily_check(): void {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			// 03:00 site time tomorrow, away from busy hours.
			$next = ( new DateTimeImmutable( 'tomorrow 03:00', wp_timezone() ) )->getTimestamp();
			wp_schedule_event( $next, 'daily', self::HOOK_DAILY );
		}
	}

	public static function unschedule_daily_check(): void {
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	public static function unschedule(): void {
		self::unschedule_daily_check();
		// wp_unschedule_hook(), not wp_clear_scheduled_hook(): runs are queued
		// with a trigger argument, which the latter wouldn't match.
		wp_unschedule_hook( Drift_Surface_Sync_Engine::HOOK_RUN );
		wp_unschedule_hook( Drift_Surface_Sync_Engine::HOOK_CONTINUE );
	}

	/**
	 * One hub request: read the settings row's publish stamp. Full sync only if
	 * it changed since the last sync. Without a publish field in the map,
	 * falls back to a full sync (≈ one request per table).
	 */
	public static function daily_check(): void {
		if ( '1' !== (string) Drift_Surface_Settings::get( 'daily_check', '1' ) || ! Drift_Surface_Settings::is_connected() ) {
			return;
		}

		$map   = Drift_Surface_Map::current();
		$table = $map['settings']['table'] ?? '';
		$field = (string) ( $map['publish']['field'] ?? '' );

		if ( ! $table || ! $field ) {
			Drift_Surface_Sync_Engine::run( 'daily' );
			return;
		}

		$records = Drift_Surface_Hub_Client::list_records( $table, [ 'maxRecords' => 1, 'fields' => [ $field ] ] );
		if ( is_wp_error( $records ) ) {
			Drift_Surface_Log::error( 'Daily check failed — ' . $records->get_error_message(), 'daily' );
			return;
		}

		$remote   = Drift_Surface_Media::plain( $records[0]['fields'][ $field ] ?? '' );
		$settings = get_option( $map['settings']['option'], [] );
		$local    = is_array( $settings ) ? (string) ( $settings['_last_published'] ?? '' ) : '';

		if ( '' !== $remote && $remote === $local ) {
			Drift_Surface_Log::info( 'Daily check: up to date.', 'daily' );
			return;
		}

		Drift_Surface_Log::info( 'Daily check: the hub has an unsynced publish — syncing.', 'daily' );
		Drift_Surface_Sync_Engine::run( 'daily' );
	}

	/* ── Admin bar "Sync now" ────────────────────────────────────────── */

	/** @param WP_Admin_Bar $bar Toolbar. */
	public static function admin_bar_node( $bar ): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Drift_Surface_Settings::is_connected() ) {
			return;
		}

		$return_to = ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

		$bar->add_node( [
			'id'    => 'ds-sync',
			'title' => '<span class="ab-icon dashicons dashicons-update" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Sync from hub', 'drift-surface' ) . '</span>',
			'href'  => wp_nonce_url( add_query_arg( [ 'action' => self::ACTION_SYNC, 'return_to' => rawurlencode( $return_to ) ], admin_url( 'admin-post.php' ) ), self::ACTION_SYNC ),
			'meta'  => [ 'title' => __( 'Pull the latest content from the Drift: Surface Hub now', 'drift-surface' ) ],
		] );

		$last = (int) ( Drift_Surface_Sync_Engine::status()['last_run'] ?? 0 );
		$bar->add_node( [
			'id'     => 'ds-sync-last',
			'parent' => 'ds-sync',
			/* translators: %s: human time difference. */
			'title'  => $last ? esc_html( sprintf( __( 'Last synced %s ago', 'drift-surface' ), human_time_diff( $last ) ) ) : esc_html__( 'Not synced yet', 'drift-surface' ),
			'href'   => false,
		] );
	}

	public static function handle_sync_now(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to sync.', 'drift-surface' ), 403 );
		}
		check_admin_referer( self::ACTION_SYNC );

		$force  = ! empty( $_GET['force'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked above.
		$result = Drift_Surface_Sync_Engine::run( 'manual', [ 'force' => $force ] );

		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		$return_to = isset( $_GET['return_to'] ) ? esc_url_raw( rawurldecode( wp_unslash( (string) $_GET['return_to'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( $return_to ?: admin_url() );
		exit;
	}

	public static function render_notice(): void {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong></p><ul style="list-style:disc;margin-left:1.5em;">%3$s</ul></div>',
			! empty( $result['ok'] ) ? 'success' : 'warning',
			esc_html( ! empty( $result['ok'] ) ? __( 'Synced from the hub.', 'drift-surface' ) : __( 'Sync finished with problems.', 'drift-surface' ) ),
			implode( '', array_map( static fn( $m ) => '<li>' . esc_html( (string) $m ) . '</li>', (array) ( $result['messages'] ?? [] ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped per item.
		);
	}
}
