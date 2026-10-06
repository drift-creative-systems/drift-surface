<?php
/**
 * class-admin-page.php — the one "Drift" admin screen.
 *
 * The Drift admin shell (left-hand tabs, one page load per tab, other
 * code adds tabs via the `drift_website_admin_tabs` filter):
 *
 *   Connection (10)   Airtable base + token, product map, publish webhook.
 *   Sync (20)         Status, API budget, Sync now / Full resync, activity log.
 *   Content (30)      What's synced where, and the current site settings.
 *   White Label (55)  Drift_Website_White_Label.
 *   Setup Wizard (60) Create the product's standard pages.
 *
 * Adding a tab from a theme:
 *   add_filter( 'drift_website_admin_tabs', function ( $tabs ) {
 *       $tabs['my-tab'] = [ 'label' => 'My Tab', 'position' => 40, 'render' => 'my_render', 'load' => 'my_load' ];
 *       return $tabs;
 *   } );
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Website_Admin_Page {

	const MENU_SLUG    = 'drift-website';
	const CHECK_RESULT = 'drift_website_schema_check';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', [ __CLASS__, 'menu_order' ], 99 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'setup_notice' ] );
		add_action( 'load-toplevel_page_' . self::MENU_SLUG, [ __CLASS__, 'load_current_tab' ] );

		add_action( 'admin_post_drift_website_save_connection', [ __CLASS__, 'handle_save_connection' ] );
		add_action( 'admin_post_drift_website_regenerate_secret', [ __CLASS__, 'handle_regenerate_secret' ] );
		add_action( 'admin_post_drift_website_check_connection', [ __CLASS__, 'handle_check_connection' ] );
		add_action( 'admin_post_drift_website_clear_log', [ __CLASS__, 'handle_clear_log' ] );
	}

	/* ── Shell ───────────────────────────────────────────────────────── */

	public static function register_menu(): void {
		add_menu_page(
			__( 'Drift', 'drift-website' ),
			__( 'Drift', 'drift-website' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-layout',
			1
		);
	}

	/**
	 * Pins Drift to the top of the admin sidebar, above Dashboard.
	 * Position 1 alone can be shuffled by other plugins claiming the same slot.
	 *
	 * @param string[] $order Top-level menu slugs in display order.
	 * @return string[]
	 */
	public static function menu_order( $order ): array {
		$order = array_values( array_diff( (array) $order, [ self::MENU_SLUG ] ) );
		array_unshift( $order, self::MENU_SLUG );

		return $order;
	}

	public static function tabs(): array {
		$tabs = [
			'connection' => [
				'label'       => __( 'Connection', 'drift-website' ),
				'description' => __( 'Connect this site to its Airtable base and choose which Drift product it runs.', 'drift-website' ),
				'position'    => 10,
				'render'      => [ __CLASS__, 'render_connection_tab' ],
			],
			'sync'       => [
				'label'       => __( 'Sync', 'drift-website' ),
				'description' => __( 'When content last came across from Airtable, what it cost, and what happened.', 'drift-website' ),
				'position'    => 20,
				'render'      => [ __CLASS__, 'render_sync_tab' ],
			],
			'content'    => [
				'label'       => __( 'Content', 'drift-website' ),
				'description' => __( 'Where each Airtable table ends up in WordPress, and the site settings currently in use.', 'drift-website' ),
				'position'    => 30,
				'render'      => [ __CLASS__, 'render_content_tab' ],
			],
			'wizard'     => [
				'label'       => __( 'Setup Wizard', 'drift-website' ),
				'description' => __( 'Create this product\'s standard pages, with their modules already in place.', 'drift-website' ),
				'position'    => 60,
				'render'      => [ __CLASS__, 'render_wizard_tab' ],
			],
		];

		$tabs = (array) apply_filters( 'drift_website_admin_tabs', $tabs );
		$tabs = array_filter( $tabs, static fn( $t ) => is_array( $t ) && ! empty( $t['label'] ) && isset( $t['render'] ) && is_callable( $t['render'] ) );
		uasort( $tabs, static fn( $a, $b ) => ( (int) ( $a['position'] ?? 100 ) ) <=> ( (int) ( $b['position'] ?? 100 ) ) );

		return $tabs;
	}

	public static function current_tab(): string {
		$tabs = self::tabs();
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	public static function tab_url( string $tab, array $extra = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::MENU_SLUG, 'tab' => $tab ], $extra ), admin_url( 'admin.php' ) );
	}

	public static function load_current_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = self::tabs()[ self::current_tab() ] ?? [];
		if ( isset( $tab['load'] ) && is_callable( $tab['load'] ) ) {
			call_user_func( $tab['load'] );
		}
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'drift-website-admin', DRIFT_WEBSITE_URL . 'assets/admin.css', [], DRIFT_WEBSITE_VERSION );
		wp_enqueue_script( 'drift-website-admin', DRIFT_WEBSITE_URL . 'assets/admin.js', [ 'jquery', 'wp-color-picker' ], DRIFT_WEBSITE_VERSION, true );
		wp_localize_script( 'drift-website-admin', 'DriftWebsite', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( Drift_Website_Page_Creator::NONCE ),
			'i18n'    => [
				'selectImage' => __( 'Select image', 'drift-website' ),
				'useImage'    => __( 'Use this image', 'drift-website' ),
				'change'      => __( 'Change image', 'drift-website' ),
				'copied'      => __( 'Copied', 'drift-website' ),
				'creating'    => __( 'Creating…', 'drift-website' ),
				'failed'      => __( 'Request failed — check the connection and try again.', 'drift-website' ),
				/* translators: 1: created, 2: total. */
				'progress'    => __( '%1$d of %2$d pages created', 'drift-website' ),
				'edit'        => __( 'Edit', 'drift-website' ),
				'view'        => __( 'View', 'drift-website' ),
			],
		] );
	}

	public static function setup_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) || ! Drift_Website_Admin_Access::can_manage() || Drift_Website_Settings::is_connected() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Drift isn\'t connected to Airtable yet.', 'drift-website' ),
			esc_html__( 'Content won\'t sync until it is.', 'drift-website' ),
			esc_url( self::tab_url( 'connection' ) ),
			esc_html__( 'Connect now', 'drift-website' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'drift-website' ) );
		}

		$tabs    = self::tabs();
		$current = self::current_tab();
		$tab     = $tabs[ $current ] ?? null;
		$map     = Drift_Website_Map::current();
		?>
		<div class="wrap drift-admin">
			<header class="drift-head">
				<div class="drift-head__brand">
					<span class="drift-head__mark" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'Drift', 'drift-website' ); ?> <span class="drift-head__product"><?php echo esc_html( $map['label'] ); ?></span></h1>
						<p><?php esc_html_e( 'Airtable in, website out.', 'drift-website' ); ?></p>
					</div>
				</div>
				<span class="drift-head__version">v<?php echo esc_html( DRIFT_WEBSITE_VERSION ); ?></span>
			</header>

			<hr class="wp-header-end">

			<?php self::render_flash(); ?>

			<div class="drift-shell">
				<nav class="drift-nav" aria-label="<?php esc_attr_e( 'Drift settings', 'drift-website' ); ?>">
					<?php foreach ( $tabs as $slug => $item ) : ?>
						<a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>

				<main class="drift-main drift-main--<?php echo esc_attr( $current ); ?>">
					<?php if ( $tab ) : ?>
						<h2 class="drift-main__title"><?php echo esc_html( $tab['label'] ); ?></h2>
						<?php if ( ! empty( $tab['description'] ) ) : ?>
							<p class="drift-main__lead"><?php echo esc_html( $tab['description'] ); ?></p>
						<?php endif; ?>
						<?php call_user_func( $tab['render'] ); ?>
					<?php endif; ?>
				</main>
			</div>
		</div>
		<?php
	}

	/** One-shot notices passed via ?drift_notice=… */
	private static function render_flash(): void {
		$notice = sanitize_key( wp_unslash( $_GET['drift_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = [
			'saved'       => [ 'success', __( 'Connection settings saved.', 'drift-website' ) ],
			'saved_check' => [ 'success', __( 'Connection settings saved. Run "Check connection" to confirm the base matches the product map.', 'drift-website' ) ],
			'secret'      => [ 'warning', __( 'New publish secret generated. Update it in the Airtable automation, or publishing will stop working.', 'drift-website' ) ],
			'checked'     => [ 'success', __( 'Connection checked — results below.', 'drift-website' ) ],
			'log_cleared' => [ 'success', __( 'Activity log cleared.', 'drift-website' ) ],
		];
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	/* ── Connection tab ──────────────────────────────────────────────── */

	public static function render_connection_tab(): void {
		$settings  = Drift_Website_Settings::all();
		$maps      = Drift_Website_Map::available();
		$connected = Drift_Website_Settings::is_connected();
		$secret    = Drift_Website_Settings::publish_secret();
		?>
		<?php if ( ! Drift_Website_Crypto::available() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'OpenSSL (AES-256-GCM) isn\'t available on this server, so the token can\'t be stored safely. Define DRIFT_WEBSITE_AIRTABLE_TOKEN in wp-config.php instead.', 'drift-website' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'drift_website_save_connection' ); ?>
			<input type="hidden" name="action" value="drift_website_save_connection">

			<section class="drift-card">
				<div class="drift-card__head">
					<h3><?php esc_html_e( 'Airtable', 'drift-website' ); ?></h3>
					<span class="drift-pill drift-pill--<?php echo $connected ? 'ok' : 'off'; ?>"><?php echo $connected ? esc_html__( 'Connected', 'drift-website' ) : esc_html__( 'Not connected', 'drift-website' ); ?></span>
				</div>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="drift-base"><?php esc_html_e( 'Base ID', 'drift-website' ); ?></label></th>
						<td>
							<input type="text" id="drift-base" class="regular-text code" name="drift[base_id]" value="<?php echo esc_attr( (string) Drift_Website_Settings::get( 'base_id' ) ); ?>" placeholder="appXXXXXXXXXXXXXX" <?php disabled( Drift_Website_Settings::base_from_constant() ); ?>>
							<p class="description"><?php esc_html_e( 'From the base\'s URL: airtable.com/appXXXXXXXXXXXXXX/…', 'drift-website' ); ?></p>
							<?php if ( Drift_Website_Settings::base_from_constant() ) : ?>
								<p class="description"><?php esc_html_e( 'Set by DRIFT_WEBSITE_AIRTABLE_BASE in wp-config.php.', 'drift-website' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="drift-token"><?php esc_html_e( 'Personal access token', 'drift-website' ); ?></label></th>
						<td>
							<?php if ( Drift_Website_Settings::token_from_constant() ) : ?>
								<p><?php esc_html_e( 'Set by DRIFT_WEBSITE_AIRTABLE_TOKEN in wp-config.php.', 'drift-website' ); ?></p>
							<?php else : ?>
								<input type="password" id="drift-token" class="regular-text code" name="drift[token]" value="" autocomplete="new-password" placeholder="<?php echo Drift_Website_Settings::has_token() ? esc_attr__( '•••••••• saved — leave blank to keep', 'drift-website' ) : 'pat…'; ?>">
								<?php if ( Drift_Website_Settings::has_token() ) : ?>
									<label class="drift-inline-check"><input type="checkbox" name="drift[clear_token]" value="1"> <?php esc_html_e( 'Remove saved token', 'drift-website' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Create one at airtable.com/create/tokens with access to this base only. Scopes: data.records:read (sync), data.records:write (website forms), schema.bases:read (connection check). Stored encrypted.', 'drift-website' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="drift-product"><?php esc_html_e( 'Product', 'drift-website' ); ?></label></th>
						<td>
							<select id="drift-product" name="drift[product]">
								<?php foreach ( array_keys( $maps ) as $slug ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['product'], $slug ); ?>><?php echo esc_html( Drift_Website_Map::label( $slug ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Which product map decides how Airtable tables become WordPress content.', 'drift-website' ); ?></p>
						</td>
					</tr>
				</table>
			</section>

			<section class="drift-card">
				<h3><?php esc_html_e( 'Sync behaviour', 'drift-website' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Daily safety check', 'drift-website' ); ?></th>
						<td>
							<label><input type="checkbox" name="drift[daily_check]" value="1" <?php checked( $settings['daily_check'], '1' ); ?>> <?php esc_html_e( 'Once a day, check Airtable\'s "Last Published" stamp and sync if a publish was missed (1 API call a day).', 'drift-website' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="drift-budget"><?php esc_html_e( 'Monthly API budget', 'drift-website' ); ?></label></th>
						<td>
							<input type="number" id="drift-budget" class="small-text" min="100" step="100" name="drift[api_budget]" value="<?php echo esc_attr( (string) $settings['api_budget'] ); ?>">
							<p class="description"><?php esc_html_e( 'Airtable Free allows 1,000 calls per workspace per month; Team 100,000. The daily check stops when this is reached; publishing and Sync now still work.', 'drift-website' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="drift-batch"><?php esc_html_e( 'Images per run', 'drift-website' ); ?></label></th>
						<td>
							<input type="number" id="drift-batch" class="small-text" min="1" max="200" name="drift[image_batch]" value="<?php echo esc_attr( (string) $settings['image_batch'] ); ?>">
							<p class="description"><?php esc_html_e( 'New images imported per sync before the rest continue a minute later (no extra API calls). Lower it on slow hosting.', 'drift-website' ); ?></p>
						</td>
					</tr>
				</table>
			</section>

			<p class="drift-save-row"><?php submit_button( __( 'Save connection', 'drift-website' ), 'primary', 'submit', false ); ?></p>
		</form>

		<section class="drift-card">
			<h3><?php esc_html_e( 'Publishing from Airtable', 'drift-website' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Free plan: put the Publish link in an Airtable button (Open URL). Paid plans can use the automation script with the webhook URL and secret instead.', 'drift-website' ); ?></p>
			<?php $link = Drift_Website_Publish::publish_link_url(); ?>
			<?php if ( $link ) : ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish link (Free plan)', 'drift-website' ); ?></th>
					<td><div class="drift-copy"><code class="drift-secret" data-secret="<?php echo esc_attr( $link ); ?>"><?php echo esc_html( home_url( '/?' . Drift_Website_Publish::LINK_PARAM . '=••••••••' ) ); ?></code><button type="button" class="button button-small drift-reveal"><?php esc_html_e( 'Show', 'drift-website' ); ?></button><button type="button" class="button button-small drift-copy__btn" data-copy="<?php echo esc_attr( $link ); ?>"><?php esc_html_e( 'Copy', 'drift-website' ); ?></button></div>
					<p class="description"><?php esc_html_e( 'Opening it syncs the site straight away and shows the band a confirmation page. Contains the secret — share it only inside the band\'s base.', 'drift-website' ); ?></p></td>
				</tr>
			</table>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Publish webhook (paid plans)', 'drift-website' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Paste these into the Airtable "Publish" automation script (docs/airtable-publish-automation.js in the plugin).', 'drift-website' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'drift-website' ); ?></th>
					<td><div class="drift-copy"><code><?php echo esc_html( Drift_Website_Publish::webhook_url() ); ?></code><button type="button" class="button button-small drift-copy__btn" data-copy="<?php echo esc_attr( Drift_Website_Publish::webhook_url() ); ?>"><?php esc_html_e( 'Copy', 'drift-website' ); ?></button></div></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish secret', 'drift-website' ); ?></th>
					<td>
						<?php if ( $secret ) : ?>
							<div class="drift-copy"><code class="drift-secret" data-secret="<?php echo esc_attr( $secret ); ?>">••••••••••••••••</code><button type="button" class="button button-small drift-reveal"><?php esc_html_e( 'Show', 'drift-website' ); ?></button><button type="button" class="button button-small drift-copy__btn" data-copy="<?php echo esc_attr( $secret ); ?>"><?php esc_html_e( 'Copy', 'drift-website' ); ?></button></div>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="drift-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new secret? The Airtable automation will stop working until you paste the new one in.', 'drift-website' ) ); ?>');">
							<?php wp_nonce_field( 'drift_website_regenerate_secret' ); ?>
							<input type="hidden" name="action" value="drift_website_regenerate_secret">
							<button type="submit" class="button-link"><?php echo $secret ? esc_html__( 'Generate a new secret', 'drift-website' ) : esc_html__( 'Generate secret', 'drift-website' ); ?></button>
						</form>
					</td>
				</tr>
			</table>
		</section>

		<section class="drift-card">
			<div class="drift-card__head">
				<h3><?php esc_html_e( 'Check connection', 'drift-website' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'drift_website_check_connection' ); ?>
					<input type="hidden" name="action" value="drift_website_check_connection">
					<button type="submit" class="button" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Check connection', 'drift-website' ); ?></button>
				</form>
			</div>
			<p class="description"><?php esc_html_e( 'Compares the base with the product map: every table and field the site expects. Uses 1 API call.', 'drift-website' ); ?></p>
			<?php self::render_check_result(); ?>
		</section>
		<?php
	}

	private static function render_check_result(): void {
		$result = get_transient( self::CHECK_RESULT );
		if ( ! is_array( $result ) ) {
			return;
		}

		if ( ! empty( $result['error'] ) ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( (string) $result['error'] ) );
			return;
		}

		if ( ! empty( $result['records_only'] ) ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'Records are readable, so syncing will work — but the token has no schema.bases:read scope, so tables and fields couldn\'t be checked against the map.', 'drift-website' ) );
			return;
		}

		$problems = (array) ( $result['problems'] ?? [] );
		if ( ! $problems ) {
			printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html__( 'Every table and field the product map expects is in the base.', 'drift-website' ) );
			return;
		}
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The base doesn\'t match the product map yet. Rename or add these in Airtable (names must match exactly, including case):', 'drift-website' ); ?></p></div>
		<table class="widefat striped drift-table">
			<thead><tr><th><?php esc_html_e( 'Table', 'drift-website' ); ?></th><th><?php esc_html_e( 'Missing', 'drift-website' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $problems as $table => $missing ) : ?>
				<tr>
					<td><strong><?php echo esc_html( (string) $table ); ?></strong></td>
					<td><?php echo true === $missing ? esc_html__( 'Whole table', 'drift-website' ) : esc_html( implode( ', ', (array) $missing ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_save_connection(): void {
		self::guard( 'drift_website_save_connection' );

		$input  = wp_unslash( $_POST['drift'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in Settings::save().
		$result = Drift_Website_Settings::save( is_array( $input ) ? $input : [] );

		Drift_Website_Map::flush();
		Drift_Website_Settings::ensure_publish_secret();
		delete_transient( self::CHECK_RESULT );

		if ( $result['changed_connection'] ) {
			// New post types may now be registered.
			Drift_Website_Content_Types::register();
			flush_rewrite_rules( false );
		}

		wp_safe_redirect( self::tab_url( 'connection', [ 'drift_notice' => $result['changed_connection'] ? 'saved_check' : 'saved' ] ) );
		exit;
	}

	public static function handle_regenerate_secret(): void {
		self::guard( 'drift_website_regenerate_secret' );
		Drift_Website_Settings::regenerate_publish_secret();
		Drift_Website_Log::warning( 'Publish secret regenerated.', 'settings' );
		wp_safe_redirect( self::tab_url( 'connection', [ 'drift_notice' => 'secret' ] ) );
		exit;
	}

	public static function handle_check_connection(): void {
		self::guard( 'drift_website_check_connection' );

		$schema = Drift_Website_Airtable::schema();
		$result = [];

		if ( is_wp_error( $schema ) ) {
			$status = (int) ( $schema->get_error_data()['status'] ?? 0 );
			if ( in_array( $status, [ 401, 403 ], true ) ) {
				// Maybe just missing schema.bases:read — see if records are readable.
				$map   = Drift_Website_Map::current();
				$table = $map['settings']['table'] ?? ( reset( $map['entities'] )['table'] ?? '' );
				$probe = $table ? Drift_Website_Airtable::list_records( $table, [ 'maxRecords' => 1 ] ) : $schema;
				$result = is_wp_error( $probe ) ? [ 'error' => $probe->get_error_message() ] : [ 'records_only' => true ];
			} else {
				$result = [ 'error' => $schema->get_error_message() ];
			}
		} else {
			$problems = [];
			foreach ( Drift_Website_Map::expected_schema() as $table => $fields ) {
				if ( ! isset( $schema[ $table ] ) ) {
					$problems[ $table ] = true;
					continue;
				}
				$missing = array_values( array_diff( $fields, array_keys( $schema[ $table ] ) ) );
				if ( $missing ) {
					$problems[ $table ] = $missing;
				}
			}
			$result = [ 'problems' => $problems ];
		}

		set_transient( self::CHECK_RESULT, $result, HOUR_IN_SECONDS );
		wp_safe_redirect( self::tab_url( 'connection', [ 'drift_notice' => 'checked' ] ) );
		exit;
	}

	/* ── Sync tab ────────────────────────────────────────────────────── */

	public static function render_sync_tab(): void {
		$status  = Drift_Website_Sync_Engine::status();
		$usage   = Drift_Website_Airtable::usage();
		$budget  = Drift_Website_Airtable::budget();
		$percent = $budget ? min( 100, (int) round( $usage['calls'] / $budget * 100 ) ) : 0;
		$publish = get_option( Drift_Website_Publish::PUBLISH_OPTION, [] );
		$daily   = wp_next_scheduled( Drift_Website_Publish::HOOK_DAILY );
		$return  = self::tab_url( 'sync' );
		$sync    = wp_nonce_url( add_query_arg( [ 'action' => Drift_Website_Publish::ACTION_SYNC, 'return_to' => rawurlencode( $return ) ], admin_url( 'admin-post.php' ) ), Drift_Website_Publish::ACTION_SYNC );
		$force   = wp_nonce_url( add_query_arg( [ 'action' => Drift_Website_Publish::ACTION_SYNC, 'force' => 1, 'return_to' => rawurlencode( $return ) ], admin_url( 'admin-post.php' ) ), Drift_Website_Publish::ACTION_SYNC );
		$fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="drift-grid">
			<section class="drift-card">
				<div class="drift-card__head">
					<h3><?php esc_html_e( 'Last sync', 'drift-website' ); ?></h3>
					<?php if ( ! empty( $status['last_run'] ) ) : ?>
						<span class="drift-pill drift-pill--<?php echo ! empty( $status['ok'] ) ? 'ok' : 'warn'; ?>"><?php echo ! empty( $status['ok'] ) ? esc_html__( 'OK', 'drift-website' ) : esc_html__( 'Problems', 'drift-website' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( empty( $status['last_run'] ) ) : ?>
					<p><?php esc_html_e( 'Nothing has synced yet.', 'drift-website' ); ?></p>
				<?php else : ?>
					<p class="drift-big"><?php echo esc_html( sprintf( /* translators: %s: time diff. */ __( '%s ago', 'drift-website' ), human_time_diff( (int) $status['last_run'] ) ) ); ?></p>
					<p class="description">
						<?php
						echo esc_html( sprintf(
							/* translators: 1: date, 2: trigger, 3: seconds, 4: calls, 5: images. */
							__( '%1$s · triggered by %2$s · %3$ss · %4$d API calls · %5$d images', 'drift-website' ),
							wp_date( $fmt, (int) $status['last_run'] ),
							(string) ( $status['trigger'] ?? '' ),
							(string) ( $status['duration'] ?? 0 ),
							(int) ( $status['api_calls'] ?? 0 ),
							(int) ( $status['images'] ?? 0 )
						) );
						?>
					</p>
					<ul class="drift-list">
						<?php foreach ( (array) ( $status['messages'] ?? [] ) as $message ) : ?>
							<li><?php echo esc_html( (string) $message ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="drift-actions">
					<a class="button button-primary" href="<?php echo esc_url( $sync ); ?>"><?php esc_html_e( 'Sync now', 'drift-website' ); ?></a>
					<a class="button" href="<?php echo esc_url( $force ); ?>" title="<?php esc_attr_e( 'Re-saves every item even if unchanged. Same API cost as a normal sync.', 'drift-website' ); ?>"><?php esc_html_e( 'Full resync', 'drift-website' ); ?></a>
				</p>
			</section>

			<section class="drift-card">
				<h3><?php esc_html_e( 'Airtable API this month', 'drift-website' ); ?></h3>
				<p class="drift-big"><?php echo esc_html( number_format_i18n( $usage['calls'] ) ); ?> <span>/ <?php echo esc_html( number_format_i18n( $budget ) ); ?></span></p>
				<div class="drift-meter<?php echo $percent >= 80 ? ' drift-meter--hot' : ''; ?>"><span style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
				<p class="description"><?php esc_html_e( 'Counted by this site, per calendar month (UTC). Other tools using the same workspace also count towards Airtable\'s limit.', 'drift-website' ); ?></p>
				<dl class="drift-facts">
					<dt><?php esc_html_e( 'Last publish received', 'drift-website' ); ?></dt>
					<dd><?php echo ! empty( $publish['received_at'] ) ? esc_html( wp_date( $fmt, (int) $publish['received_at'] ) ) : esc_html__( 'Never', 'drift-website' ); ?></dd>
					<dt><?php esc_html_e( 'Next daily check', 'drift-website' ); ?></dt>
					<dd><?php echo $daily ? esc_html( wp_date( $fmt, $daily ) ) : esc_html__( 'Off', 'drift-website' ); ?></dd>
					<?php if ( ! empty( $status['pending_images'] ) ) : ?>
						<dt><?php esc_html_e( 'Images', 'drift-website' ); ?></dt>
						<dd><?php esc_html_e( 'Still importing — continues automatically.', 'drift-website' ); ?></dd>
					<?php endif; ?>
				</dl>
			</section>
		</div>

		<section class="drift-card">
			<div class="drift-card__head">
				<h3><?php esc_html_e( 'Activity', 'drift-website' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'drift_website_clear_log' ); ?>
					<input type="hidden" name="action" value="drift_website_clear_log">
					<button type="submit" class="button-link"><?php esc_html_e( 'Clear', 'drift-website' ); ?></button>
				</form>
			</div>
			<?php $entries = array_reverse( Drift_Website_Log::entries() ); ?>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'No activity yet.', 'drift-website' ); ?></p>
			<?php else : ?>
				<table class="widefat striped drift-table drift-log">
					<thead><tr><th><?php esc_html_e( 'When', 'drift-website' ); ?></th><th><?php esc_html_e( 'Source', 'drift-website' ); ?></th><th><?php esc_html_e( 'Message', 'drift-website' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr class="drift-log--<?php echo esc_attr( $entry['level'] ); ?>">
							<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $entry['time'] ) ); ?></td>
							<td><?php echo esc_html( $entry['source'] ); ?></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function handle_clear_log(): void {
		self::guard( 'drift_website_clear_log' );
		Drift_Website_Log::clear();
		wp_safe_redirect( self::tab_url( 'sync', [ 'drift_notice' => 'log_cleared' ] ) );
		exit;
	}

	/* ── Content tab ─────────────────────────────────────────────────── */

	public static function render_content_tab(): void {
		$map = Drift_Website_Map::current();
		?>
		<section class="drift-card">
			<h3><?php esc_html_e( 'Tables', 'drift-website' ); ?></h3>
			<table class="widefat striped drift-table">
				<thead><tr><th><?php esc_html_e( 'Airtable table', 'drift-website' ); ?></th><th><?php esc_html_e( 'WordPress', 'drift-website' ); ?></th><th><?php esc_html_e( 'Live', 'drift-website' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $map['settings'] ) : ?>
					<tr><td><strong><?php echo esc_html( $map['settings']['table'] ); ?></strong></td><td><?php esc_html_e( 'Site settings', 'drift-website' ); ?> <code><?php echo esc_html( $map['settings']['option'] ); ?></code></td><td>—</td></tr>
				<?php endif; ?>
				<?php foreach ( $map['entities'] as $entity ) :
					$object = get_post_type_object( $entity['post_type'] );
					$count  = $object ? (int) ( wp_count_posts( $entity['post_type'] )->publish ?? 0 ) : 0;
					?>
					<tr>
						<td><strong><?php echo esc_html( $entity['table'] ); ?></strong></td>
						<td>
							<?php if ( $object ) : ?>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $entity['post_type'] ) ); ?>"><?php echo esc_html( $object->labels->name ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $entity['post_type'] ); ?> <em><?php esc_html_e( '(not registered)', 'drift-website' ); ?></em>
							<?php endif; ?>
							<code><?php echo esc_html( $entity['post_type'] ); ?></code>
						</td>
						<td><?php echo esc_html( (string) $count ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<?php if ( $map['settings'] ) :
			$values = get_option( $map['settings']['option'], [] );
			?>
			<section class="drift-card">
				<h3><?php esc_html_e( 'Site settings in use', 'drift-website' ); ?></h3>
				<?php if ( ! is_array( $values ) || ! $values ) : ?>
					<p><?php esc_html_e( 'Nothing synced yet.', 'drift-website' ); ?></p>
				<?php else : ?>
					<table class="widefat striped drift-table">
						<thead><tr><th><?php esc_html_e( 'Airtable field', 'drift-website' ); ?></th><th><?php esc_html_e( 'Key', 'drift-website' ); ?></th><th><?php esc_html_e( 'Value', 'drift-website' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $map['settings']['fields'] as $name => $field ) :
							$key   = (string) $field['to'];
							$value = $values[ $key ] ?? '';
							?>
							<tr>
								<td><?php echo esc_html( $name ); ?></td>
								<td><code><?php echo esc_html( $key ); ?></code></td>
								<td>
									<?php
									if ( 'image' === $field['type'] && $value ) {
										echo wp_get_attachment_image( (int) $value, [ 80, 80 ] );
									} elseif ( is_array( $value ) ) {
										echo esc_html( implode( ', ', array_map( 'strval', $value ) ) );
									} elseif ( preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $value ) ) {
										printf( '<span class="drift-swatch" style="background:%1$s"></span> %1$s', esc_attr( (string) $value ) );
									} else {
										echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $value ), 18 ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		<?php endif; ?>
		<?php
	}

	/* ── Wizard tab ──────────────────────────────────────────────────── */

	public static function render_wizard_tab(): void {
		$pages    = Drift_Website_Page_Creator::visible();
		$existing = Drift_Website_Page_Creator::existing_ids();
		$total    = count( $pages );
		$done     = count( $existing );

		if ( ! $pages ) {
			echo '<section class="drift-card"><p>' . esc_html__( 'This product map defines no pages.', 'drift-website' ) . '</p></section>';
			return;
		}

		if ( ! Drift_Website_Theme_Check::satisfied() ) {
			echo '<section class="drift-card"><p>' . esc_html( Drift_Website_Theme_Check::blocked_message() ) . '</p></section>';
			return;
		}

		if ( ! function_exists( 'update_field' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'ACF Pro isn\'t active — pages will be created without their modules.', 'drift-website' ) . '</p></div>';
		}
		?>
		<section class="drift-card drift-wizard" data-total="<?php echo esc_attr( (string) $total ); ?>">
			<div class="drift-card__head">
				<div class="drift-progress"><span style="width:<?php echo esc_attr( (string) ( $total ? round( $done / $total * 100 ) : 0 ) ); ?>%"></span></div>
				<span class="drift-progress__label"><?php echo esc_html( sprintf( /* translators: 1: created, 2: total. */ __( '%1$d of %2$d pages created', 'drift-website' ), $done, $total ) ); ?></span>
			</div>

			<p class="drift-actions">
				<button type="button" class="button button-small" data-select="all"><?php esc_html_e( 'Select all', 'drift-website' ); ?></button>
				<button type="button" class="button button-small" data-select="required"><?php esc_html_e( 'Required only', 'drift-website' ); ?></button>
				<button type="button" class="button button-small" data-select="none"><?php esc_html_e( 'Clear', 'drift-website' ); ?></button>
			</p>

			<ul class="drift-pages">
				<?php foreach ( $pages as $page ) :
					$exists = in_array( $page['id'], $existing, true );
					$post   = $exists ? Drift_Website_Page_Creator::find_page( $page ) : null;
					?>
					<li class="drift-page<?php echo $exists ? ' is-existing' : ''; ?><?php echo $page['parent'] ? ' is-child' : ''; ?>" data-id="<?php echo esc_attr( $page['id'] ); ?>" data-required="<?php echo $page['required'] ? '1' : '0'; ?>" <?php echo $page['parent'] ? 'data-parent="' . esc_attr( (string) $page['parent'] ) . '"' : ''; ?>>
						<label>
							<input type="checkbox" value="<?php echo esc_attr( $page['id'] ); ?>" <?php disabled( $exists ); ?> <?php checked( $exists || $page['required'] ); ?>>
							<span class="drift-page__body">
								<strong><?php echo esc_html( $page['title'] ); ?></strong>
								<code>/<?php echo esc_html( $page['slug'] ); ?></code>
								<?php if ( $page['required'] ) : ?><span class="drift-tag"><?php esc_html_e( 'Required', 'drift-website' ); ?></span><?php endif; ?>
								<?php foreach ( (array) $page['tags'] as $tag ) : ?><span class="drift-tag drift-tag--soft"><?php echo esc_html( (string) $tag ); ?></span><?php endforeach; ?>
								<span class="drift-page__desc"><?php echo esc_html( $page['description'] ); ?></span>
							</span>
						</label>
						<span class="drift-page__status">
							<?php if ( $post ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'drift-website' ); ?></a>
								<a href="<?php echo esc_url( (string) get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'drift-website' ); ?></a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="drift-actions">
				<button type="button" class="button button-primary drift-create"><?php esc_html_e( 'Create selected pages', 'drift-website' ); ?></button>
			</p>
			<ol class="drift-wizard__log" hidden></ol>
		</section>
		<?php
	}

	/* ── Helpers ─────────────────────────────────────────────────────── */

	private static function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) || ! Drift_Website_Admin_Access::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'drift-website' ), 403 );
		}
		check_admin_referer( $nonce );
	}
}
