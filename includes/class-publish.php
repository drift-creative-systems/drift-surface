<?php
/**
 * class-publish.php — how a sync gets started.
 *
 * 1. Publish (the normal route). The client ticks "Publish" in Airtable; an
 *    Airtable automation POSTs to /wp-json/encore/v1/publish with the site's
 *    publish secret (docs/airtable-publish-automation.js). The site queues a
 *    sync in the background and answers 202 straight away. One automation run
 *    per publish, roughly one API call per table.
 *
 * 2. Daily safety check. Once a day the site reads one field — the settings
 *    row's "Last Published" stamp — and runs a full sync only if it differs
 *    from the last one synced. Catches a webhook that never arrived for one
 *    API call a day (~30 a month) instead of polling every table.
 *
 * 3. "Sync now" in the admin bar, for agency staff and editors.
 *
 * 4. Publish link (Airtable Free plan, where automations can't run scripts):
 *    a button in Airtable opens https://site/?encore_publish=SECRET in a new
 *    tab. The site syncs right there and shows the band a plain "your
 *    website is up to date" page. No automation run, ~1 API call per table.
 *
 * Why not the old 30-minute cron per table: at ~6 calls per run that's
 * ~8,600 calls a month, against the Free plan's 1,000 per workspace.
 *
 * Also GET /wp-json/encore/v1/status (same secret) for uptime/monitoring
 * scenarios in Make.com.
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Encore_Website_Publish {

	const NAMESPACE        = 'encore/v1';
	const LEGACY_NAMESPACE = 'drift/v1';
	const HOOK_DAILY     = 'encore_website_daily_check';
	const ACTION_SYNC    = 'encore_website_sync_now';
	const CAPABILITY     = 'edit_theme_options';
	const PUBLISH_OPTION = 'encore_website_last_publish';
	const NOTICE_PREFIX  = 'encore_website_sync_notice_';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( self::HOOK_DAILY, [ __CLASS__, 'daily_check' ] );

		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_node' ], 100 );
		add_action( 'admin_post_' . self::ACTION_SYNC, [ __CLASS__, 'handle_sync_now' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );

		// Publish link for Airtable's Free plan (no automation scripts).
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_handle_publish_link' ] ); // After init, so post types exist.
	}

	/* ── REST ────────────────────────────────────────────────────────── */

	public static function register_routes(): void {
		// drift/v1 is the 1.x namespace. Clients' Airtable automations still
		// post there until their webhook URL is updated, so it stays.
		foreach ( [ self::NAMESPACE, self::LEGACY_NAMESPACE ] as $namespace ) {
			register_rest_route(
				$namespace,
				'/publish',
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'rest_publish' ],
					'permission_callback' => [ __CLASS__, 'verify_secret' ],
				]
			);

			register_rest_route(
				$namespace,
				'/status',
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'rest_status' ],
					'permission_callback' => [ __CLASS__, 'verify_secret' ],
				]
			);
		}
	}

	/**
	 * Accepts the secret as an X-Encore-Secret header (preferred; 1.x
	 * automations send X-Drift-Secret) or a "secret" body/query parameter
	 * (for tools that can't set headers).
	 */
	public static function verify_secret( WP_REST_Request $request ): bool {
		$expected = Encore_Website_Settings::publish_secret();
		$given    = (string) ( $request->get_header( 'x_encore_secret' ) ?: $request->get_header( 'x_drift_secret' ) ?: $request->get_param( 'secret' ) );

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

		Encore_Website_Log::info( 'Publish received from Airtable' . ( $stamp ? ' (' . $stamp . ')' : '' ) . ' — sync queued.', 'publish' );
		Encore_Website_Sync_Engine::queue( 'publish' );

		return new WP_REST_Response( [ 'queued' => true, 'site' => home_url( '/' ) ], 202 );
	}

	public static function rest_status(): WP_REST_Response {
		$status = Encore_Website_Sync_Engine::status();
		$usage  = Encore_Website_Airtable::usage();

		return new WP_REST_Response(
			[
				'site'          => home_url( '/' ),
				'product'       => Encore_Website_Map::current()['slug'],
				'plugin'        => ENCORE_WEBSITE_VERSION,
				'connected'     => Encore_Website_Settings::is_connected(),
				'last_run'      => (int) ( $status['last_run'] ?? 0 ),
				'last_ok'       => (int) ( $status['last_ok'] ?? 0 ),
				'ok'            => (bool) ( $status['ok'] ?? false ),
				'messages'      => (array) ( $status['messages'] ?? [] ),
				'api_calls'     => $usage['calls'],
				'api_budget'    => Encore_Website_Airtable::budget(),
				'last_publish'  => get_option( self::PUBLISH_OPTION, [] ),
			],
			200
		);
	}

	public static function webhook_url(): string {
		return rest_url( self::NAMESPACE . '/publish' );
	}

	/* ── Publish link (Free plan) ─────────────────────────────────────── */

	const LINK_PARAM        = 'encore_publish';
	const LEGACY_LINK_PARAM = 'drift_publish'; // 1.x links already pasted into Airtable buttons.
	const LINK_THROTTLE     = 'encore_website_link_throttle';

	public static function publish_link_url(): string {
		$secret = Encore_Website_Settings::publish_secret();
		return $secret ? add_query_arg( self::LINK_PARAM, rawurlencode( $secret ), home_url( '/' ) ) : '';
	}

	/**
	 * ?encore_publish=SECRET — runs a sync now and shows the result as a
	 * simple page. Throttled to one run a minute so a double-click or a
	 * refresh can't burn the API budget.
	 */
	public static function maybe_handle_publish_link(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Authenticated by the secret.
		$param = isset( $_GET[ self::LINK_PARAM ] ) ? self::LINK_PARAM : ( isset( $_GET[ self::LEGACY_LINK_PARAM ] ) ? self::LEGACY_LINK_PARAM : '' );
		if ( '' === $param || is_admin() ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$given = sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) );
		// phpcs:enable
		$expected = Encore_Website_Settings::publish_secret();

		if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
			status_header( 403 );
			self::link_page( false, __( 'This publish link isn\'t valid.', 'encore-website' ), [ __( 'Ask your web team for the current link.', 'encore-website' ) ] );
		}

		$last = (int) get_transient( self::LINK_THROTTLE );
		if ( $last && time() - $last < MINUTE_IN_SECONDS ) {
			self::link_page( true, __( 'Already updating', 'encore-website' ), [ __( 'Your website was updated less than a minute ago — changes since then will show on the next publish.', 'encore-website' ) ] );
		}
		set_transient( self::LINK_THROTTLE, time(), MINUTE_IN_SECONDS );

		update_option( self::PUBLISH_OPTION, [ 'received_at' => time(), 'stamp' => '', 'by' => 'publish-link' ], false );
		Encore_Website_Log::info( 'Publish link used — syncing now.', 'publish' );

		ignore_user_abort( true );
		$result = Encore_Website_Sync_Engine::run( 'publish-link' );

		$lines = (array) $result['messages'];
		if ( Encore_Website_Media::was_deferred() ) {
			$lines[] = __( 'Some new images are still importing and will appear in a minute or two.', 'encore-website' );
		}

		self::link_page(
			! empty( $result['ok'] ),
			! empty( $result['ok'] ) ? __( 'Your website is up to date', 'encore-website' ) : __( 'Your website updated, with a problem', 'encore-website' ),
			$lines
		);
	}

	/** Minimal standalone result page, then exit. */
	private static function link_page( bool $ok, string $title, array $lines ): void {
		$name   = function_exists( 'encore_website_setting' ) ? (string) encore_website_setting( 'name', get_bloginfo( 'name' ) ) : get_bloginfo( 'name' );
		$accent = function_exists( 'encore_website_setting' ) ? (string) encore_website_setting( 'colour_primary', '#ee4367' ) : '#ee4367';
		$accent = sanitize_hex_color( $accent ) ?: '#ee4367';
		?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title . ' — ' . $name ); ?></title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#000;color:#fff;font:17px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{width:min(92vw,34rem);padding:2.5rem;background:#fff;color:#1d2327;border-radius:14px;border-top:6px solid <?php echo esc_attr( $accent ); ?>}
h1{margin:0 0 .25rem;font-size:1.6rem;line-height:1.2}.who{margin:0 0 1.25rem;color:#646970}
ul{margin:0 0 1.5rem;padding-left:1.2em;color:#3c434a;font-size:.95rem}
a{display:inline-block;padding:.7em 1.3em;background:<?php echo esc_attr( $accent ); ?>;color:#fff;border-radius:6px;text-decoration:none;font-weight:600}
.bad{border-top-color:#b32d2e}
</style></head>
<body><main class="<?php echo $ok ? 'ok' : 'bad'; ?>">
<h1><?php echo esc_html( ( $ok ? '✓ ' : '' ) . $title ); ?></h1>
<p class="who"><?php echo esc_html( $name ); ?></p>
<?php if ( $lines ) : ?><ul><?php foreach ( $lines as $line ) : ?><li><?php echo esc_html( (string) $line ); ?></li><?php endforeach; ?></ul><?php endif; ?>
<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'View the website', 'encore-website' ); ?></a>
</main></body></html>
		<?php
		exit;
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
		wp_unschedule_hook( Encore_Website_Sync_Engine::HOOK_RUN );
		wp_unschedule_hook( Encore_Website_Sync_Engine::HOOK_CONTINUE );
	}

	/**
	 * One API call: read the settings row's publish stamp. Full sync only if
	 * it changed since the last sync. Without a publish field in the map,
	 * falls back to a full sync (≈ one call per table).
	 */
	public static function daily_check(): void {
		if ( '1' !== (string) Encore_Website_Settings::get( 'daily_check', '1' ) || ! Encore_Website_Settings::is_connected() ) {
			return;
		}

		if ( Encore_Website_Airtable::over_budget() ) {
			Encore_Website_Log::warning( 'Daily check skipped — this month\'s API budget is used up.', 'daily' );
			return;
		}

		$map   = Encore_Website_Map::current();
		$table = $map['settings']['table'] ?? '';
		$field = (string) ( $map['publish']['field'] ?? '' );

		if ( ! $table || ! $field ) {
			Encore_Website_Sync_Engine::run( 'daily' );
			return;
		}

		$records = Encore_Website_Airtable::list_records( $table, [ 'maxRecords' => 1, 'fields' => [ $field ] ] );
		if ( is_wp_error( $records ) ) {
			Encore_Website_Log::error( 'Daily check failed — ' . $records->get_error_message(), 'daily' );
			return;
		}

		$remote   = Encore_Website_Media::plain( $records[0]['fields'][ $field ] ?? '' );
		$settings = get_option( $map['settings']['option'], [] );
		$local    = is_array( $settings ) ? (string) ( $settings['_last_published'] ?? '' ) : '';

		if ( '' !== $remote && $remote === $local ) {
			Encore_Website_Log::info( 'Daily check: up to date.', 'daily' );
			return;
		}

		Encore_Website_Log::info( 'Daily check: Airtable has an unsynced publish — syncing.', 'daily' );
		Encore_Website_Sync_Engine::run( 'daily' );
	}

	/* ── Admin bar "Sync now" ────────────────────────────────────────── */

	/** @param WP_Admin_Bar $bar Toolbar. */
	public static function admin_bar_node( $bar ): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Encore_Website_Settings::is_connected() ) {
			return;
		}

		$return_to = ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

		$bar->add_node( [
			'id'    => 'ew-sync',
			'title' => '<span class="ab-icon dashicons dashicons-update" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Sync from Airtable', 'encore-website' ) . '</span>',
			'href'  => wp_nonce_url( add_query_arg( [ 'action' => self::ACTION_SYNC, 'return_to' => rawurlencode( $return_to ) ], admin_url( 'admin-post.php' ) ), self::ACTION_SYNC ),
			'meta'  => [ 'title' => __( 'Pull the latest content from Airtable now', 'encore-website' ) ],
		] );

		$last = (int) ( Encore_Website_Sync_Engine::status()['last_run'] ?? 0 );
		$bar->add_node( [
			'id'     => 'ew-sync-last',
			'parent' => 'ew-sync',
			/* translators: %s: human time difference. */
			'title'  => $last ? esc_html( sprintf( __( 'Last synced %s ago', 'encore-website' ), human_time_diff( $last ) ) ) : esc_html__( 'Not synced yet', 'encore-website' ),
			'href'   => false,
		] );
	}

	public static function handle_sync_now(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to sync.', 'encore-website' ), 403 );
		}
		check_admin_referer( self::ACTION_SYNC );

		$force  = ! empty( $_GET['force'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked above.
		$result = Encore_Website_Sync_Engine::run( 'manual', [ 'force' => $force ] );

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
			esc_html( ! empty( $result['ok'] ) ? __( 'Synced from Airtable.', 'encore-website' ) : __( 'Sync finished with problems.', 'encore-website' ) ),
			implode( '', array_map( static fn( $m ) => '<li>' . esc_html( (string) $m ) . '</li>', (array) ( $result['messages'] ?? [] ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped per item.
		);
	}
}
