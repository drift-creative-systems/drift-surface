<?php
/**
 * class-admin-page.php — the one "Encore Website" admin screen.
 *
 * The Encore Website admin shell (left-hand tabs, one page load per tab, other
 * code adds tabs via the `encore_website_admin_tabs` filter):
 *
 *   Connection (10)   Airtable base + token, product map, publish webhook.
 *   Sync (20)         Status, API budget, Sync now / Full resync, activity log.
 *   Content (30)      What's synced where, and the current site settings.
 *   White Label (55)  Encore_Website_White_Label.
 *   Setup Wizard (60) Create the product's standard pages.
 *
 * Adding a tab from a theme:
 *   add_filter( 'encore_website_admin_tabs', function ( $tabs ) {
 *       $tabs['my-tab'] = [ 'label' => 'My Tab', 'position' => 40, 'render' => 'my_render', 'load' => 'my_load' ];
 *       return $tabs;
 *   } );
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Encore_Website_Admin_Page {

	const MENU_SLUG    = 'encore-website';
	const CHECK_RESULT = 'encore_website_schema_check';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', [ __CLASS__, 'menu_order' ], 99 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'setup_notice' ] );
		add_action( 'load-toplevel_page_' . self::MENU_SLUG, [ __CLASS__, 'load_current_tab' ] );

		add_action( 'admin_post_encore_website_save_connection', [ __CLASS__, 'handle_save_connection' ] );
		add_action( 'admin_post_encore_website_regenerate_secret', [ __CLASS__, 'handle_regenerate_secret' ] );
		add_action( 'admin_post_encore_website_check_connection', [ __CLASS__, 'handle_check_connection' ] );
		add_action( 'admin_post_encore_website_clear_log', [ __CLASS__, 'handle_clear_log' ] );
	}

	/* ── Shell ───────────────────────────────────────────────────────── */

	public static function register_menu(): void {
		add_menu_page(
			__( 'Encore Website', 'encore-website' ),
			__( 'Encore Website', 'encore-website' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-layout',
			1
		);
	}

	/**
	 * Pins Encore Website to the top of the admin sidebar, above Dashboard.
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
				'label'       => __( 'Connection', 'encore-website' ),
				'description' => __( 'Connect this site to its Airtable base and choose which product it runs.', 'encore-website' ),
				'position'    => 10,
				'render'      => [ __CLASS__, 'render_connection_tab' ],
			],
			'sync'       => [
				'label'       => __( 'Sync', 'encore-website' ),
				'description' => __( 'When content last came across from Airtable, what it cost, and what happened.', 'encore-website' ),
				'position'    => 20,
				'render'      => [ __CLASS__, 'render_sync_tab' ],
			],
			'content'    => [
				'label'       => __( 'Content', 'encore-website' ),
				'description' => __( 'Where each Airtable table ends up in WordPress, and the site settings currently in use.', 'encore-website' ),
				'position'    => 30,
				'render'      => [ __CLASS__, 'render_content_tab' ],
			],
			'wizard'     => [
				'label'       => __( 'Setup Wizard', 'encore-website' ),
				'description' => __( 'Create this product\'s standard pages, with their modules already in place.', 'encore-website' ),
				'position'    => 60,
				'render'      => [ __CLASS__, 'render_wizard_tab' ],
			],
		];

		$tabs = (array) apply_filters( 'encore_website_admin_tabs', $tabs );
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
		wp_enqueue_style( 'encore-website-admin', ENCORE_WEBSITE_URL . 'assets/admin.css', [], ENCORE_WEBSITE_VERSION );
		wp_enqueue_script( 'encore-website-admin', ENCORE_WEBSITE_URL . 'assets/admin.js', [ 'jquery', 'wp-color-picker' ], ENCORE_WEBSITE_VERSION, true );
		wp_localize_script( 'encore-website-admin', 'EncoreWebsite', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( Encore_Website_Page_Creator::NONCE ),
			'i18n'    => [
				'selectImage' => __( 'Select image', 'encore-website' ),
				'useImage'    => __( 'Use this image', 'encore-website' ),
				'change'      => __( 'Change image', 'encore-website' ),
				'copied'      => __( 'Copied', 'encore-website' ),
				'creating'    => __( 'Creating…', 'encore-website' ),
				'failed'      => __( 'Request failed — check the connection and try again.', 'encore-website' ),
				/* translators: 1: created, 2: total. */
				'progress'    => __( '%1$d of %2$d pages created', 'encore-website' ),
				'edit'        => __( 'Edit', 'encore-website' ),
				'view'        => __( 'View', 'encore-website' ),
			],
		] );
	}

	public static function setup_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) || ! Encore_Website_Admin_Access::can_manage() || Encore_Website_Settings::is_connected() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Encore Website isn\'t connected to Airtable yet.', 'encore-website' ),
			esc_html__( 'Content won\'t sync until it is.', 'encore-website' ),
			esc_url( self::tab_url( 'connection' ) ),
			esc_html__( 'Connect now', 'encore-website' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'encore-website' ) );
		}

		$tabs    = self::tabs();
		$current = self::current_tab();
		$tab     = $tabs[ $current ] ?? null;
		$map     = Encore_Website_Map::current();
		?>
		<div class="wrap ew-admin">
			<header class="ew-head">
				<div class="ew-head__brand">
					<span class="ew-head__mark" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'Encore Website', 'encore-website' ); ?> <span class="ew-head__product"><?php echo esc_html( $map['label'] ); ?></span></h1>
						<p><?php esc_html_e( 'Airtable in, website out.', 'encore-website' ); ?></p>
					</div>
				</div>
				<span class="ew-head__version">v<?php echo esc_html( ENCORE_WEBSITE_VERSION ); ?></span>
			</header>

			<hr class="wp-header-end">

			<?php self::render_flash(); ?>

			<div class="ew-shell">
				<nav class="ew-nav" aria-label="<?php esc_attr_e( 'Encore Website settings', 'encore-website' ); ?>">
					<?php foreach ( $tabs as $slug => $item ) : ?>
						<a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>

				<main class="ew-main ew-main--<?php echo esc_attr( $current ); ?>">
					<?php if ( $tab ) : ?>
						<h2 class="ew-main__title"><?php echo esc_html( $tab['label'] ); ?></h2>
						<?php if ( ! empty( $tab['description'] ) ) : ?>
							<p class="ew-main__lead"><?php echo esc_html( $tab['description'] ); ?></p>
						<?php endif; ?>
						<?php call_user_func( $tab['render'] ); ?>
					<?php endif; ?>
				</main>
			</div>
		</div>
		<?php
	}

	/** One-shot notices passed via ?encore_notice=… */
	private static function render_flash(): void {
		$notice = sanitize_key( wp_unslash( $_GET['encore_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = [
			'saved'       => [ 'success', __( 'Connection settings saved.', 'encore-website' ) ],
			'saved_check' => [ 'success', __( 'Connection settings saved. Run "Check connection" to confirm the base matches the product map.', 'encore-website' ) ],
			'secret'      => [ 'warning', __( 'New publish secret generated. Update it in the Airtable automation, or publishing will stop working.', 'encore-website' ) ],
			'checked'     => [ 'success', __( 'Connection checked — results below.', 'encore-website' ) ],
			'log_cleared' => [ 'success', __( 'Activity log cleared.', 'encore-website' ) ],
		];
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	/* ── Connection tab ──────────────────────────────────────────────── */

	public static function render_connection_tab(): void {
		$settings  = Encore_Website_Settings::all();
		$maps      = Encore_Website_Map::available();
		$connected = Encore_Website_Settings::is_connected();
		$secret    = Encore_Website_Settings::publish_secret();
		?>
		<?php if ( ! Encore_Website_Crypto::available() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'OpenSSL (AES-256-GCM) isn\'t available on this server, so the token can\'t be stored safely. Define ENCORE_WEBSITE_AIRTABLE_TOKEN in wp-config.php instead.', 'encore-website' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'encore_website_save_connection' ); ?>
			<input type="hidden" name="action" value="encore_website_save_connection">

			<section class="ew-card">
				<div class="ew-card__head">
					<h3><?php esc_html_e( 'Airtable', 'encore-website' ); ?></h3>
					<span class="ew-pill ew-pill--<?php echo $connected ? 'ok' : 'off'; ?>"><?php echo $connected ? esc_html__( 'Connected', 'encore-website' ) : esc_html__( 'Not connected', 'encore-website' ); ?></span>
				</div>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ew-api-base"><?php esc_html_e( 'Data source', 'encore-website' ); ?></label></th>
						<td>
							<?php $ew_api_const = '' !== encore_website_constant( 'API_BASE' ); ?>
							<input type="url" id="ew-api-base" class="regular-text code" name="encore[api_base]" value="<?php echo esc_attr( (string) Encore_Website_Settings::get( 'api_base' ) ); ?>" placeholder="<?php esc_attr_e( 'Blank = Airtable', 'encore-website' ); ?>" <?php disabled( $ew_api_const ); ?>>
							<p class="description"><?php esc_html_e( 'Leave blank to sync from Airtable. To sync from a Drift Hub, paste the hub\'s Data source address from the artist\'s page in the hub, plus the Base ID and token shown there.', 'encore-website' ); ?></p>
							<?php if ( $ew_api_const ) : ?>
								<p class="description"><?php esc_html_e( 'Set by ENCORE_WEBSITE_API_BASE in wp-config.php.', 'encore-website' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ew-base"><?php esc_html_e( 'Base ID', 'encore-website' ); ?></label></th>
						<td>
							<input type="text" id="ew-base" class="regular-text code" name="encore[base_id]" value="<?php echo esc_attr( (string) Encore_Website_Settings::get( 'base_id' ) ); ?>" placeholder="appXXXXXXXXXXXXXX" <?php disabled( Encore_Website_Settings::base_from_constant() ); ?>>
							<p class="description"><?php esc_html_e( 'From the base\'s URL: airtable.com/appXXXXXXXXXXXXXX/…', 'encore-website' ); ?></p>
							<?php if ( Encore_Website_Settings::base_from_constant() ) : ?>
								<p class="description"><?php esc_html_e( 'Set by ENCORE_WEBSITE_AIRTABLE_BASE in wp-config.php.', 'encore-website' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ew-token"><?php esc_html_e( 'Personal access token', 'encore-website' ); ?></label></th>
						<td>
							<?php if ( Encore_Website_Settings::token_from_constant() ) : ?>
								<p><?php esc_html_e( 'Set by ENCORE_WEBSITE_AIRTABLE_TOKEN in wp-config.php.', 'encore-website' ); ?></p>
							<?php else : ?>
								<input type="password" id="ew-token" class="regular-text code" name="encore[token]" value="" autocomplete="new-password" placeholder="<?php echo Encore_Website_Settings::has_token() ? esc_attr__( '•••••••• saved — leave blank to keep', 'encore-website' ) : 'pat…'; ?>">
								<?php if ( Encore_Website_Settings::has_token() ) : ?>
									<label class="ew-inline-check"><input type="checkbox" name="encore[clear_token]" value="1"> <?php esc_html_e( 'Remove saved token', 'encore-website' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Create one at airtable.com/create/tokens with access to this base only. Scopes: data.records:read (sync), data.records:write (website forms), schema.bases:read (connection check). Stored encrypted.', 'encore-website' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ew-product"><?php esc_html_e( 'Product', 'encore-website' ); ?></label></th>
						<td>
							<select id="ew-product" name="encore[product]">
								<?php foreach ( array_keys( $maps ) as $slug ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['product'], $slug ); ?>><?php echo esc_html( Encore_Website_Map::label( $slug ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Which product map decides how Airtable tables become WordPress content.', 'encore-website' ); ?></p>
						</td>
					</tr>
				</table>
			</section>

			<section class="ew-card">
				<h3><?php esc_html_e( 'Sync behaviour', 'encore-website' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Daily safety check', 'encore-website' ); ?></th>
						<td>
							<label><input type="checkbox" name="encore[daily_check]" value="1" <?php checked( $settings['daily_check'], '1' ); ?>> <?php esc_html_e( 'Once a day, check Airtable\'s "Last Published" stamp and sync if a publish was missed (1 API call a day).', 'encore-website' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ew-budget"><?php esc_html_e( 'Monthly API budget', 'encore-website' ); ?></label></th>
						<td>
							<input type="number" id="ew-budget" class="small-text" min="100" step="100" name="encore[api_budget]" value="<?php echo esc_attr( (string) $settings['api_budget'] ); ?>">
							<p class="description"><?php esc_html_e( 'Airtable Free allows 1,000 calls per workspace per month; Team 100,000. The daily check stops when this is reached; publishing and Sync now still work.', 'encore-website' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ew-batch"><?php esc_html_e( 'Images per run', 'encore-website' ); ?></label></th>
						<td>
							<input type="number" id="ew-batch" class="small-text" min="1" max="200" name="encore[image_batch]" value="<?php echo esc_attr( (string) $settings['image_batch'] ); ?>">
							<p class="description"><?php esc_html_e( 'New images imported per sync before the rest continue a minute later (no extra API calls). Lower it on slow hosting.', 'encore-website' ); ?></p>
						</td>
					</tr>
				</table>
			</section>

			<p class="ew-save-row"><?php submit_button( __( 'Save connection', 'encore-website' ), 'primary', 'submit', false ); ?></p>
		</form>

		<section class="ew-card">
			<h3><?php esc_html_e( 'Publishing from Airtable', 'encore-website' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Free plan: put the Publish link in an Airtable button (Open URL). Paid plans can use the automation script with the webhook URL and secret instead.', 'encore-website' ); ?></p>
			<?php $link = Encore_Website_Publish::publish_link_url(); ?>
			<?php if ( $link ) : ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish link (Free plan)', 'encore-website' ); ?></th>
					<td><div class="ew-copy"><code class="ew-secret" data-secret="<?php echo esc_attr( $link ); ?>"><?php echo esc_html( home_url( '/?' . Encore_Website_Publish::LINK_PARAM . '=••••••••' ) ); ?></code><button type="button" class="button button-small ew-reveal"><?php esc_html_e( 'Show', 'encore-website' ); ?></button><button type="button" class="button button-small ew-copy__btn" data-copy="<?php echo esc_attr( $link ); ?>"><?php esc_html_e( 'Copy', 'encore-website' ); ?></button></div>
					<p class="description"><?php esc_html_e( 'Opening it syncs the site straight away and shows the band a confirmation page. Contains the secret — share it only inside the band\'s base.', 'encore-website' ); ?></p></td>
				</tr>
			</table>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Publish webhook (paid plans)', 'encore-website' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Paste these into the Airtable "Publish" automation script (docs/airtable-publish-automation.js in the plugin).', 'encore-website' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'encore-website' ); ?></th>
					<td><div class="ew-copy"><code><?php echo esc_html( Encore_Website_Publish::webhook_url() ); ?></code><button type="button" class="button button-small ew-copy__btn" data-copy="<?php echo esc_attr( Encore_Website_Publish::webhook_url() ); ?>"><?php esc_html_e( 'Copy', 'encore-website' ); ?></button></div></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish secret', 'encore-website' ); ?></th>
					<td>
						<?php if ( $secret ) : ?>
							<div class="ew-copy"><code class="ew-secret" data-secret="<?php echo esc_attr( $secret ); ?>">••••••••••••••••</code><button type="button" class="button button-small ew-reveal"><?php esc_html_e( 'Show', 'encore-website' ); ?></button><button type="button" class="button button-small ew-copy__btn" data-copy="<?php echo esc_attr( $secret ); ?>"><?php esc_html_e( 'Copy', 'encore-website' ); ?></button></div>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ew-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new secret? The Airtable automation will stop working until you paste the new one in.', 'encore-website' ) ); ?>');">
							<?php wp_nonce_field( 'encore_website_regenerate_secret' ); ?>
							<input type="hidden" name="action" value="encore_website_regenerate_secret">
							<button type="submit" class="button-link"><?php echo $secret ? esc_html__( 'Generate a new secret', 'encore-website' ) : esc_html__( 'Generate secret', 'encore-website' ); ?></button>
						</form>
					</td>
				</tr>
			</table>
		</section>

		<section class="ew-card">
			<div class="ew-card__head">
				<h3><?php esc_html_e( 'Check connection', 'encore-website' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'encore_website_check_connection' ); ?>
					<input type="hidden" name="action" value="encore_website_check_connection">
					<button type="submit" class="button" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Check connection', 'encore-website' ); ?></button>
				</form>
			</div>
			<p class="description"><?php esc_html_e( 'Compares the base with the product map: every table and field the site expects. Uses 1 API call.', 'encore-website' ); ?></p>
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
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'Records are readable, so syncing will work — but the token has no schema.bases:read scope, so tables and fields couldn\'t be checked against the map.', 'encore-website' ) );
			return;
		}

		$problems = (array) ( $result['problems'] ?? [] );
		if ( ! $problems ) {
			printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html__( 'Every table and field the product map expects is in the base.', 'encore-website' ) );
			return;
		}
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The base doesn\'t match the product map yet. Rename or add these in Airtable (names must match exactly, including case):', 'encore-website' ); ?></p></div>
		<table class="widefat striped ew-table">
			<thead><tr><th><?php esc_html_e( 'Table', 'encore-website' ); ?></th><th><?php esc_html_e( 'Missing', 'encore-website' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $problems as $table => $missing ) : ?>
				<tr>
					<td><strong><?php echo esc_html( (string) $table ); ?></strong></td>
					<td><?php echo true === $missing ? esc_html__( 'Whole table', 'encore-website' ) : esc_html( implode( ', ', (array) $missing ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_save_connection(): void {
		self::guard( 'encore_website_save_connection' );

		$input  = wp_unslash( $_POST['encore'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in Settings::save().
		$result = Encore_Website_Settings::save( is_array( $input ) ? $input : [] );

		Encore_Website_Map::flush();
		Encore_Website_Settings::ensure_publish_secret();
		delete_transient( self::CHECK_RESULT );

		if ( $result['changed_connection'] ) {
			// New post types may now be registered.
			Encore_Website_Content_Types::register();
			flush_rewrite_rules( false );
		}

		wp_safe_redirect( self::tab_url( 'connection', [ 'encore_notice' => $result['changed_connection'] ? 'saved_check' : 'saved' ] ) );
		exit;
	}

	public static function handle_regenerate_secret(): void {
		self::guard( 'encore_website_regenerate_secret' );
		Encore_Website_Settings::regenerate_publish_secret();
		Encore_Website_Log::warning( 'Publish secret regenerated.', 'settings' );
		wp_safe_redirect( self::tab_url( 'connection', [ 'encore_notice' => 'secret' ] ) );
		exit;
	}

	public static function handle_check_connection(): void {
		self::guard( 'encore_website_check_connection' );

		$schema = Encore_Website_Airtable::schema();
		$result = [];

		if ( is_wp_error( $schema ) ) {
			$status = (int) ( $schema->get_error_data()['status'] ?? 0 );
			if ( in_array( $status, [ 401, 403 ], true ) ) {
				// Maybe just missing schema.bases:read — see if records are readable.
				$map   = Encore_Website_Map::current();
				$table = $map['settings']['table'] ?? ( reset( $map['entities'] )['table'] ?? '' );
				$probe = $table ? Encore_Website_Airtable::list_records( $table, [ 'maxRecords' => 1 ] ) : $schema;
				$result = is_wp_error( $probe ) ? [ 'error' => $probe->get_error_message() ] : [ 'records_only' => true ];
			} else {
				$result = [ 'error' => $schema->get_error_message() ];
			}
		} else {
			$problems = [];
			foreach ( Encore_Website_Map::expected_schema() as $table => $fields ) {
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
		wp_safe_redirect( self::tab_url( 'connection', [ 'encore_notice' => 'checked' ] ) );
		exit;
	}

	/* ── Sync tab ────────────────────────────────────────────────────── */

	public static function render_sync_tab(): void {
		$status  = Encore_Website_Sync_Engine::status();
		$usage   = Encore_Website_Airtable::usage();
		$budget  = Encore_Website_Airtable::budget();
		$percent = $budget ? min( 100, (int) round( $usage['calls'] / $budget * 100 ) ) : 0;
		$publish = get_option( Encore_Website_Publish::PUBLISH_OPTION, [] );
		$daily   = wp_next_scheduled( Encore_Website_Publish::HOOK_DAILY );
		$return  = self::tab_url( 'sync' );
		$sync    = wp_nonce_url( add_query_arg( [ 'action' => Encore_Website_Publish::ACTION_SYNC, 'return_to' => rawurlencode( $return ) ], admin_url( 'admin-post.php' ) ), Encore_Website_Publish::ACTION_SYNC );
		$force   = wp_nonce_url( add_query_arg( [ 'action' => Encore_Website_Publish::ACTION_SYNC, 'force' => 1, 'return_to' => rawurlencode( $return ) ], admin_url( 'admin-post.php' ) ), Encore_Website_Publish::ACTION_SYNC );
		$fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="ew-grid">
			<section class="ew-card">
				<div class="ew-card__head">
					<h3><?php esc_html_e( 'Last sync', 'encore-website' ); ?></h3>
					<?php if ( ! empty( $status['last_run'] ) ) : ?>
						<span class="ew-pill ew-pill--<?php echo ! empty( $status['ok'] ) ? 'ok' : 'warn'; ?>"><?php echo ! empty( $status['ok'] ) ? esc_html__( 'OK', 'encore-website' ) : esc_html__( 'Problems', 'encore-website' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( empty( $status['last_run'] ) ) : ?>
					<p><?php esc_html_e( 'Nothing has synced yet.', 'encore-website' ); ?></p>
				<?php else : ?>
					<p class="ew-big"><?php echo esc_html( sprintf( /* translators: %s: time diff. */ __( '%s ago', 'encore-website' ), human_time_diff( (int) $status['last_run'] ) ) ); ?></p>
					<p class="description">
						<?php
						echo esc_html( sprintf(
							/* translators: 1: date, 2: trigger, 3: seconds, 4: calls, 5: images. */
							__( '%1$s · triggered by %2$s · %3$ss · %4$d API calls · %5$d images', 'encore-website' ),
							wp_date( $fmt, (int) $status['last_run'] ),
							(string) ( $status['trigger'] ?? '' ),
							(string) ( $status['duration'] ?? 0 ),
							(int) ( $status['api_calls'] ?? 0 ),
							(int) ( $status['images'] ?? 0 )
						) );
						?>
					</p>
					<ul class="ew-list">
						<?php foreach ( (array) ( $status['messages'] ?? [] ) as $message ) : ?>
							<li><?php echo esc_html( (string) $message ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="ew-actions">
					<a class="button button-primary" href="<?php echo esc_url( $sync ); ?>"><?php esc_html_e( 'Sync now', 'encore-website' ); ?></a>
					<a class="button" href="<?php echo esc_url( $force ); ?>" title="<?php esc_attr_e( 'Re-saves every item even if unchanged. Same API cost as a normal sync.', 'encore-website' ); ?>"><?php esc_html_e( 'Full resync', 'encore-website' ); ?></a>
				</p>
			</section>

			<section class="ew-card">
				<h3><?php esc_html_e( 'Airtable API this month', 'encore-website' ); ?></h3>
				<p class="ew-big"><?php echo esc_html( number_format_i18n( $usage['calls'] ) ); ?> <span>/ <?php echo esc_html( number_format_i18n( $budget ) ); ?></span></p>
				<div class="ew-meter<?php echo $percent >= 80 ? ' ew-meter--hot' : ''; ?>"><span style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
				<p class="description"><?php esc_html_e( 'Counted by this site, per calendar month (UTC). Other tools using the same workspace also count towards Airtable\'s limit.', 'encore-website' ); ?></p>
				<dl class="ew-facts">
					<dt><?php esc_html_e( 'Last publish received', 'encore-website' ); ?></dt>
					<dd><?php echo ! empty( $publish['received_at'] ) ? esc_html( wp_date( $fmt, (int) $publish['received_at'] ) ) : esc_html__( 'Never', 'encore-website' ); ?></dd>
					<dt><?php esc_html_e( 'Next daily check', 'encore-website' ); ?></dt>
					<dd><?php echo $daily ? esc_html( wp_date( $fmt, $daily ) ) : esc_html__( 'Off', 'encore-website' ); ?></dd>
					<?php if ( ! empty( $status['pending_images'] ) ) : ?>
						<dt><?php esc_html_e( 'Images', 'encore-website' ); ?></dt>
						<dd><?php esc_html_e( 'Still importing — continues automatically.', 'encore-website' ); ?></dd>
					<?php endif; ?>
				</dl>
			</section>
		</div>

		<section class="ew-card">
			<div class="ew-card__head">
				<h3><?php esc_html_e( 'Activity', 'encore-website' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'encore_website_clear_log' ); ?>
					<input type="hidden" name="action" value="encore_website_clear_log">
					<button type="submit" class="button-link"><?php esc_html_e( 'Clear', 'encore-website' ); ?></button>
				</form>
			</div>
			<?php $entries = array_reverse( Encore_Website_Log::entries() ); ?>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'No activity yet.', 'encore-website' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ew-table ew-log">
					<thead><tr><th><?php esc_html_e( 'When', 'encore-website' ); ?></th><th><?php esc_html_e( 'Source', 'encore-website' ); ?></th><th><?php esc_html_e( 'Message', 'encore-website' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr class="ew-log--<?php echo esc_attr( $entry['level'] ); ?>">
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
		self::guard( 'encore_website_clear_log' );
		Encore_Website_Log::clear();
		wp_safe_redirect( self::tab_url( 'sync', [ 'encore_notice' => 'log_cleared' ] ) );
		exit;
	}

	/* ── Content tab ─────────────────────────────────────────────────── */

	public static function render_content_tab(): void {
		$map = Encore_Website_Map::current();
		?>
		<section class="ew-card">
			<h3><?php esc_html_e( 'Tables', 'encore-website' ); ?></h3>
			<table class="widefat striped ew-table">
				<thead><tr><th><?php esc_html_e( 'Airtable table', 'encore-website' ); ?></th><th><?php esc_html_e( 'WordPress', 'encore-website' ); ?></th><th><?php esc_html_e( 'Live', 'encore-website' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $map['settings'] ) : ?>
					<tr><td><strong><?php echo esc_html( $map['settings']['table'] ); ?></strong></td><td><?php esc_html_e( 'Site settings', 'encore-website' ); ?> <code><?php echo esc_html( $map['settings']['option'] ); ?></code></td><td>—</td></tr>
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
								<?php echo esc_html( $entity['post_type'] ); ?> <em><?php esc_html_e( '(not registered)', 'encore-website' ); ?></em>
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
			<section class="ew-card">
				<h3><?php esc_html_e( 'Site settings in use', 'encore-website' ); ?></h3>
				<?php if ( ! is_array( $values ) || ! $values ) : ?>
					<p><?php esc_html_e( 'Nothing synced yet.', 'encore-website' ); ?></p>
				<?php else : ?>
					<table class="widefat striped ew-table">
						<thead><tr><th><?php esc_html_e( 'Airtable field', 'encore-website' ); ?></th><th><?php esc_html_e( 'Key', 'encore-website' ); ?></th><th><?php esc_html_e( 'Value', 'encore-website' ); ?></th></tr></thead>
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
										printf( '<span class="ew-swatch" style="background:%1$s"></span> %1$s', esc_attr( (string) $value ) );
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
		$pages    = Encore_Website_Page_Creator::visible();
		$existing = Encore_Website_Page_Creator::existing_ids();
		$total    = count( $pages );
		$done     = count( $existing );

		if ( ! $pages ) {
			echo '<section class="ew-card"><p>' . esc_html__( 'This product map defines no pages.', 'encore-website' ) . '</p></section>';
			return;
		}

		if ( ! Encore_Website_Theme_Check::satisfied() ) {
			echo '<section class="ew-card"><p>' . esc_html( Encore_Website_Theme_Check::blocked_message() ) . '</p></section>';
			return;
		}

		if ( ! function_exists( 'update_field' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'ACF Pro isn\'t active — pages will be created without their modules.', 'encore-website' ) . '</p></div>';
		}
		?>
		<section class="ew-card ew-wizard" data-total="<?php echo esc_attr( (string) $total ); ?>">
			<div class="ew-card__head">
				<div class="ew-progress"><span style="width:<?php echo esc_attr( (string) ( $total ? round( $done / $total * 100 ) : 0 ) ); ?>%"></span></div>
				<span class="ew-progress__label"><?php echo esc_html( sprintf( /* translators: 1: created, 2: total. */ __( '%1$d of %2$d pages created', 'encore-website' ), $done, $total ) ); ?></span>
			</div>

			<p class="ew-actions">
				<button type="button" class="button button-small" data-select="all"><?php esc_html_e( 'Select all', 'encore-website' ); ?></button>
				<button type="button" class="button button-small" data-select="required"><?php esc_html_e( 'Required only', 'encore-website' ); ?></button>
				<button type="button" class="button button-small" data-select="none"><?php esc_html_e( 'Clear', 'encore-website' ); ?></button>
			</p>

			<ul class="ew-pages">
				<?php foreach ( $pages as $page ) :
					$exists = in_array( $page['id'], $existing, true );
					$post   = $exists ? Encore_Website_Page_Creator::find_page( $page ) : null;
					?>
					<li class="ew-page<?php echo $exists ? ' is-existing' : ''; ?><?php echo $page['parent'] ? ' is-child' : ''; ?>" data-id="<?php echo esc_attr( $page['id'] ); ?>" data-required="<?php echo $page['required'] ? '1' : '0'; ?>" <?php echo $page['parent'] ? 'data-parent="' . esc_attr( (string) $page['parent'] ) . '"' : ''; ?>>
						<label>
							<input type="checkbox" value="<?php echo esc_attr( $page['id'] ); ?>" <?php disabled( $exists ); ?> <?php checked( $exists || $page['required'] ); ?>>
							<span class="ew-page__body">
								<strong><?php echo esc_html( $page['title'] ); ?></strong>
								<code>/<?php echo esc_html( $page['slug'] ); ?></code>
								<?php if ( $page['required'] ) : ?><span class="ew-tag"><?php esc_html_e( 'Required', 'encore-website' ); ?></span><?php endif; ?>
								<?php foreach ( (array) $page['tags'] as $tag ) : ?><span class="ew-tag ew-tag--soft"><?php echo esc_html( (string) $tag ); ?></span><?php endforeach; ?>
								<span class="ew-page__desc"><?php echo esc_html( $page['description'] ); ?></span>
							</span>
						</label>
						<span class="ew-page__status">
							<?php if ( $post ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'encore-website' ); ?></a>
								<a href="<?php echo esc_url( (string) get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'encore-website' ); ?></a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="ew-actions">
				<button type="button" class="button button-primary ew-create"><?php esc_html_e( 'Create selected pages', 'encore-website' ); ?></button>
			</p>
			<ol class="ew-wizard__log" hidden></ol>
		</section>
		<?php
	}

	/* ── Helpers ─────────────────────────────────────────────────────── */

	private static function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) || ! Encore_Website_Admin_Access::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'encore-website' ), 403 );
		}
		check_admin_referer( $nonce );
	}
}
