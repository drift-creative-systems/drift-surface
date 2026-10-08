<?php
/**
 * class-admin-page.php — the one "Drift: Surface" admin screen.
 *
 * The Drift: Surface admin shell (left-hand tabs, one page load per tab, other
 * code adds tabs via the `drift_surface_admin_tabs` filter):
 *
 *   Connection (10)   Hub address, Base ID + token, product map, publish secret.
 *   Sync (20)         Status, Sync now / Full resync, activity log.
 *   Content (30)      What's synced where, and the current site settings.
 *   White Label (55)  Drift_Surface_White_Label.
 *   Setup Wizard (60) Create the product's standard pages.
 *
 * Adding a tab from a theme:
 *   add_filter( 'drift_surface_admin_tabs', function ( $tabs ) {
 *       $tabs['my-tab'] = [ 'label' => 'My Tab', 'position' => 40, 'render' => 'my_render', 'load' => 'my_load' ];
 *       return $tabs;
 *   } );
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Admin_Page {

	const MENU_SLUG    = 'drift-surface';
	const CHECK_RESULT = 'drift_surface_schema_check';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', [ __CLASS__, 'menu_order' ], 99 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'setup_notice' ] );
		add_action( 'load-toplevel_page_' . self::MENU_SLUG, [ __CLASS__, 'load_current_tab' ] );

		add_action( 'admin_post_drift_surface_save_connection', [ __CLASS__, 'handle_save_connection' ] );
		add_action( 'admin_post_drift_surface_regenerate_secret', [ __CLASS__, 'handle_regenerate_secret' ] );
		add_action( 'admin_post_drift_surface_check_connection', [ __CLASS__, 'handle_check_connection' ] );
		add_action( 'admin_post_drift_surface_clear_log', [ __CLASS__, 'handle_clear_log' ] );
	}

	/* ── Shell ───────────────────────────────────────────────────────── */

	public static function register_menu(): void {
		add_menu_page(
			__( 'Drift: Surface', 'drift-surface' ),
			__( 'Drift: Surface', 'drift-surface' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-layout',
			1
		);
	}

	/**
	 * Pins Drift: Surface to the top of the admin sidebar, above Dashboard.
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
				'label'       => __( 'Connection', 'drift-surface' ),
				'description' => __( 'Connect this site to its artist in a Drift: Surface Hub and choose which product it runs.', 'drift-surface' ),
				'position'    => 10,
				'render'      => [ __CLASS__, 'render_connection_tab' ],
			],
			'sync'       => [
				'label'       => __( 'Sync', 'drift-surface' ),
				'description' => __( 'When content last came across from the hub, and what happened.', 'drift-surface' ),
				'position'    => 20,
				'render'      => [ __CLASS__, 'render_sync_tab' ],
			],
			'content'    => [
				'label'       => __( 'Content', 'drift-surface' ),
				'description' => __( 'Where each hub table ends up in WordPress, and the site settings currently in use.', 'drift-surface' ),
				'position'    => 30,
				'render'      => [ __CLASS__, 'render_content_tab' ],
			],
			'wizard'     => [
				'label'       => __( 'Setup Wizard', 'drift-surface' ),
				'description' => __( 'Create this product\'s standard pages, with their modules already in place.', 'drift-surface' ),
				'position'    => 60,
				'render'      => [ __CLASS__, 'render_wizard_tab' ],
			],
		];

		$tabs = (array) apply_filters( 'drift_surface_admin_tabs', $tabs );
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
		self::enqueue_fonts();
		wp_enqueue_style( 'drift-surface-admin', DRIFT_SURFACE_URL . 'assets/admin.css', [ 'drift-surface-fonts' ], DRIFT_SURFACE_VERSION );
		wp_enqueue_script( 'drift-surface-admin', DRIFT_SURFACE_URL . 'assets/admin.js', [ 'jquery', 'wp-color-picker' ], DRIFT_SURFACE_VERSION, true );
		wp_localize_script( 'drift-surface-admin', 'DriftSurface', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( Drift_Surface_Page_Creator::NONCE ),
			'i18n'    => [
				'selectImage' => __( 'Select image', 'drift-surface' ),
				'useImage'    => __( 'Use this image', 'drift-surface' ),
				'change'      => __( 'Change image', 'drift-surface' ),
				'copied'      => __( 'Copied', 'drift-surface' ),
				'creating'    => __( 'Creating…', 'drift-surface' ),
				'failed'      => __( 'Request failed — check the connection and try again.', 'drift-surface' ),
				/* translators: 1: created, 2: total. */
				'progress'    => __( '%1$d of %2$d pages created', 'drift-surface' ),
				'edit'        => __( 'Edit', 'drift-surface' ),
				'view'        => __( 'View', 'drift-surface' ),
			],
		] );
	}

	/**
	 * Inter and Poppins, self-hosted (assets/fonts/), as on the Drift: Surface
	 * Hub. No requests to Google. Also used by the login screen.
	 */
	public static function enqueue_fonts(): void {
		wp_enqueue_style( 'drift-surface-fonts', DRIFT_SURFACE_URL . 'assets/fonts/fonts.css', [], DRIFT_SURFACE_VERSION );
	}

	/**
	 * The Drift mark, as on the Drift: Surface Hub. Decorative: the name is
	 * given in text beside it.
	 */
	public static function mark( string $class = 'ds-head__mark' ): string {
		return '<svg class="' . esc_attr( $class ) . '" viewBox="80 40 180 160" aria-hidden="true" focusable="false"><path fill="currentColor" d="M80 40H180C220 40 260 80 260 120C260 160 220 200 180 200H80L130 150H180C196 150 210 136 210 120C210 104 196 90 180 90H80V40Z"/><path fill="currentColor" d="M90 170L150 110H210L150 170H90Z"/></svg>';
	}

	public static function setup_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) || ! Drift_Surface_Admin_Access::can_manage() || Drift_Surface_Settings::is_connected() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Drift: Surface isn\'t connected to a Drift: Surface Hub yet.', 'drift-surface' ),
			esc_html__( 'Content won\'t sync until it is.', 'drift-surface' ),
			esc_url( self::tab_url( 'connection' ) ),
			esc_html__( 'Connect now', 'drift-surface' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'drift-surface' ) );
		}

		$tabs    = self::tabs();
		$current = self::current_tab();
		$tab     = $tabs[ $current ] ?? null;
		$map     = Drift_Surface_Map::current();
		?>
		<div class="wrap ds-admin">
			<header class="ds-head">
				<h1 class="ds-head__brand">
					<?php echo self::mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
					<span class="ds-head__name" aria-hidden="true">DRIFT<small>Surface</small></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Drift: Surface', 'drift-surface' ); ?></span>
				</h1>
				<div class="ds-head__meta">
					<span class="ds-head__product"><?php echo esc_html( $map['label'] ); ?></span>
					<span class="ds-head__source">
						<?php
						$ds_hub_host = (string) wp_parse_url( Drift_Surface_Hub_Client::api_base(), PHP_URL_HOST );
						echo esc_html(
							'' !== $ds_hub_host
								/* translators: %s: the hub's host name. */
								? sprintf( __( 'Syncing from %s', 'drift-surface' ), $ds_hub_host )
								: __( 'No hub connected', 'drift-surface' )
						);
						?>
					</span>
					<span class="ds-head__version">v<?php echo esc_html( DRIFT_SURFACE_VERSION ); ?></span>
				</div>
			</header>

			<hr class="wp-header-end">

			<?php self::render_flash(); ?>

			<div class="ds-shell">
				<nav class="ds-nav" aria-label="<?php esc_attr_e( 'Drift: Surface settings', 'drift-surface' ); ?>">
					<?php foreach ( $tabs as $slug => $item ) : ?>
						<a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>

				<main class="ds-main ds-main--<?php echo esc_attr( $current ); ?>">
					<?php if ( $tab ) : ?>
						<h2 class="ds-main__title"><?php echo esc_html( $tab['label'] ); ?></h2>
						<?php if ( ! empty( $tab['description'] ) ) : ?>
							<p class="ds-main__lead"><?php echo esc_html( $tab['description'] ); ?></p>
						<?php endif; ?>
						<?php call_user_func( $tab['render'] ); ?>
					<?php endif; ?>
				</main>
			</div>
		</div>
		<?php
	}

	/** One-shot notices passed via ?drift_surface_notice=… */
	private static function render_flash(): void {
		$notice = sanitize_key( wp_unslash( $_GET['drift_surface_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = [
			'saved'       => [ 'success', __( 'Connection settings saved.', 'drift-surface' ) ],
			'saved_check' => [ 'success', __( 'Connection settings saved. Run "Check connection" to confirm the hub matches the product map.', 'drift-surface' ) ],
			'secret'      => [ 'warning', __( 'New publish secret generated. Paste it into the artist\'s page in the hub, or the hub\'s Publish button will stop working.', 'drift-surface' ) ],
			'checked'     => [ 'success', __( 'Connection checked — results below.', 'drift-surface' ) ],
			'log_cleared' => [ 'success', __( 'Activity log cleared.', 'drift-surface' ) ],
		];
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	/* ── Connection tab ──────────────────────────────────────────────── */

	public static function render_connection_tab(): void {
		$settings  = Drift_Surface_Settings::all();
		$maps      = Drift_Surface_Map::available();
		$connected = Drift_Surface_Settings::is_connected();
		$secret    = Drift_Surface_Settings::publish_secret();
		?>
		<?php if ( ! Drift_Surface_Crypto::available() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'OpenSSL (AES-256-GCM) isn\'t available on this server, so the token can\'t be stored safely. Define DRIFT_SURFACE_HUB_TOKEN in wp-config.php instead.', 'drift-surface' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'drift_surface_save_connection' ); ?>
			<input type="hidden" name="action" value="drift_surface_save_connection">

			<section class="ds-card">
				<div class="ds-card__head">
					<h3><?php esc_html_e( 'Drift: Surface Hub', 'drift-surface' ); ?></h3>
					<span class="ds-pill ds-pill--<?php echo $connected ? 'ok' : 'off'; ?>"><?php echo $connected ? esc_html__( 'Connected', 'drift-surface' ) : esc_html__( 'Not connected', 'drift-surface' ); ?></span>
				</div>
				<p class="description"><?php esc_html_e( 'Copy these from the artist\'s page in the hub (Website connection).', 'drift-surface' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ds-api-base"><?php esc_html_e( 'Hub address', 'drift-surface' ); ?></label></th>
						<td>
							<input type="url" id="ds-api-base" class="regular-text code" name="drift_surface[api_base]" value="<?php echo esc_attr( (string) Drift_Surface_Settings::get( 'api_base' ) ); ?>" placeholder="https://hub.example/wp-json/drift-hub/v0/" <?php disabled( Drift_Surface_Settings::hub_url_from_constant() ); ?>>
							<p class="description"><?php esc_html_e( 'Shown as "Data source" in the hub.', 'drift-surface' ); ?></p>
							<?php if ( Drift_Surface_Settings::hub_url_from_constant() ) : ?>
								<p class="description"><?php esc_html_e( 'Set by DRIFT_SURFACE_HUB_URL in wp-config.php.', 'drift-surface' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ds-base"><?php esc_html_e( 'Base ID', 'drift-surface' ); ?></label></th>
						<td>
							<input type="text" id="ds-base" class="regular-text code" name="drift_surface[base_id]" value="<?php echo esc_attr( (string) Drift_Surface_Settings::get( 'base_id' ) ); ?>" placeholder="appXXXXXXXXXXXXXX" <?php disabled( Drift_Surface_Settings::base_from_constant() ); ?>>
							<?php if ( Drift_Surface_Settings::base_from_constant() ) : ?>
								<p class="description"><?php esc_html_e( 'Set by DRIFT_SURFACE_HUB_BASE in wp-config.php.', 'drift-surface' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ds-token"><?php esc_html_e( 'Token', 'drift-surface' ); ?></label></th>
						<td>
							<?php if ( Drift_Surface_Settings::token_from_constant() ) : ?>
								<p><?php esc_html_e( 'Set by DRIFT_SURFACE_HUB_TOKEN in wp-config.php.', 'drift-surface' ); ?></p>
							<?php else : ?>
								<input type="password" id="ds-token" class="regular-text code" name="drift_surface[token]" value="" autocomplete="new-password" placeholder="<?php echo Drift_Surface_Settings::has_token() ? esc_attr__( '•••••••• saved — leave blank to keep', 'drift-surface' ) : 'hub_…'; ?>">
								<?php if ( Drift_Surface_Settings::has_token() ) : ?>
									<label class="ds-inline-check"><input type="checkbox" name="drift_surface[clear_token]" value="1"> <?php esc_html_e( 'Remove saved token', 'drift-surface' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'The hub shows a new token once, when it\'s generated. Stored encrypted.', 'drift-surface' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ds-product"><?php esc_html_e( 'Product', 'drift-surface' ); ?></label></th>
						<td>
							<select id="ds-product" name="drift_surface[product]">
								<?php foreach ( array_keys( $maps ) as $slug ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['product'], $slug ); ?>><?php echo esc_html( Drift_Surface_Map::label( $slug ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Which product map decides how hub tables become WordPress content.', 'drift-surface' ); ?></p>
						</td>
					</tr>
				</table>
			</section>

			<section class="ds-card">
				<h3><?php esc_html_e( 'Sync behaviour', 'drift-surface' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Daily safety check', 'drift-surface' ); ?></th>
						<td>
							<label><input type="checkbox" name="drift_surface[daily_check]" value="1" <?php checked( $settings['daily_check'], '1' ); ?>> <?php esc_html_e( 'Once a day, check the hub\'s "Last Published" stamp and sync if a publish was missed (1 request a day).', 'drift-surface' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ds-batch"><?php esc_html_e( 'Images per run', 'drift-surface' ); ?></label></th>
						<td>
							<input type="number" id="ds-batch" class="small-text" min="1" max="200" name="drift_surface[image_batch]" value="<?php echo esc_attr( (string) $settings['image_batch'] ); ?>">
							<p class="description"><?php esc_html_e( 'New images imported per sync before the rest continue a minute later (no extra hub requests). Lower it on slow hosting.', 'drift-surface' ); ?></p>
						</td>
					</tr>
				</table>
			</section>

			<p class="ds-save-row"><?php submit_button( __( 'Save connection', 'drift-surface' ), 'primary', 'submit', false ); ?></p>
		</form>

		<section class="ds-card">
			<h3><?php esc_html_e( 'Publishing from the hub', 'drift-surface' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Paste these into the artist\'s page in the hub (Website connection), so its Publish button can update this site.', 'drift-surface' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Website address', 'drift-surface' ); ?></th>
					<td><div class="ds-copy"><code><?php echo esc_html( home_url( '/' ) ); ?></code><button type="button" class="button button-small ds-copy__btn" data-copy="<?php echo esc_attr( home_url( '/' ) ); ?>"><?php esc_html_e( 'Copy', 'drift-surface' ); ?></button></div></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Publish secret', 'drift-surface' ); ?></th>
					<td>
						<?php if ( $secret ) : ?>
							<div class="ds-copy"><code class="ds-secret" data-secret="<?php echo esc_attr( $secret ); ?>">••••••••••••••••</code><button type="button" class="button button-small ds-reveal"><?php esc_html_e( 'Show', 'drift-surface' ); ?></button><button type="button" class="button button-small ds-copy__btn" data-copy="<?php echo esc_attr( $secret ); ?>"><?php esc_html_e( 'Copy', 'drift-surface' ); ?></button></div>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ds-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new secret? The hub\'s Publish button will stop working until you paste the new one into the hub.', 'drift-surface' ) ); ?>');">
							<?php wp_nonce_field( 'drift_surface_regenerate_secret' ); ?>
							<input type="hidden" name="action" value="drift_surface_regenerate_secret">
							<button type="submit" class="button-link"><?php echo $secret ? esc_html__( 'Generate a new secret', 'drift-surface' ) : esc_html__( 'Generate secret', 'drift-surface' ); ?></button>
						</form>
					</td>
				</tr>
			</table>
		</section>

		<section class="ds-card">
			<div class="ds-card__head">
				<h3><?php esc_html_e( 'Check connection', 'drift-surface' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'drift_surface_check_connection' ); ?>
					<input type="hidden" name="action" value="drift_surface_check_connection">
					<button type="submit" class="button" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Check connection', 'drift-surface' ); ?></button>
				</form>
			</div>
			<p class="description"><?php esc_html_e( 'Compares the hub with the product map: every table and field the site expects. Uses 1 hub request.', 'drift-surface' ); ?></p>
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

		$problems = (array) ( $result['problems'] ?? [] );
		if ( ! $problems ) {
			printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html__( 'Every table and field the product map expects is in the hub.', 'drift-surface' ) );
			return;
		}
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The hub doesn\'t have everything the product map expects. Update the Drift: Surface Hub plugin so its schema matches this version of Drift: Surface:', 'drift-surface' ); ?></p></div>
		<table class="widefat striped ds-table">
			<thead><tr><th><?php esc_html_e( 'Table', 'drift-surface' ); ?></th><th><?php esc_html_e( 'Missing', 'drift-surface' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $problems as $table => $missing ) : ?>
				<tr>
					<td><strong><?php echo esc_html( (string) $table ); ?></strong></td>
					<td><?php echo true === $missing ? esc_html__( 'Whole table', 'drift-surface' ) : esc_html( implode( ', ', (array) $missing ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_save_connection(): void {
		self::guard( 'drift_surface_save_connection' );

		$input  = wp_unslash( $_POST['drift_surface'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in Settings::save().
		$result = Drift_Surface_Settings::save( is_array( $input ) ? $input : [] );

		Drift_Surface_Map::flush();
		Drift_Surface_Settings::ensure_publish_secret();
		delete_transient( self::CHECK_RESULT );

		if ( $result['changed_connection'] ) {
			// New post types may now be registered.
			Drift_Surface_Content_Types::register();
			flush_rewrite_rules( false );
		}

		wp_safe_redirect( self::tab_url( 'connection', [ 'drift_surface_notice' => $result['changed_connection'] ? 'saved_check' : 'saved' ] ) );
		exit;
	}

	public static function handle_regenerate_secret(): void {
		self::guard( 'drift_surface_regenerate_secret' );
		Drift_Surface_Settings::regenerate_publish_secret();
		Drift_Surface_Log::warning( 'Publish secret regenerated.', 'settings' );
		wp_safe_redirect( self::tab_url( 'connection', [ 'drift_surface_notice' => 'secret' ] ) );
		exit;
	}

	public static function handle_check_connection(): void {
		self::guard( 'drift_surface_check_connection' );

		$schema = Drift_Surface_Hub_Client::schema();
		$result = [];

		if ( is_wp_error( $schema ) ) {
			$result = [ 'error' => $schema->get_error_message() ];
		} else {
			$problems = [];
			foreach ( Drift_Surface_Map::expected_schema() as $table => $fields ) {
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
		wp_safe_redirect( self::tab_url( 'connection', [ 'drift_surface_notice' => 'checked' ] ) );
		exit;
	}

	/* ── Sync tab ────────────────────────────────────────────────────── */

	public static function render_sync_tab(): void {
		$status  = Drift_Surface_Sync_Engine::status();
		$publish = get_option( Drift_Surface_Publish::PUBLISH_OPTION, [] );
		$daily   = wp_next_scheduled( Drift_Surface_Publish::HOOK_DAILY );
		$return  = self::tab_url( 'sync' );
		$sync    = wp_nonce_url( add_query_arg( [ 'action' => Drift_Surface_Publish::ACTION_SYNC, 'return_to' => rawurlencode( $return ) ], admin_url( 'admin-post.php' ) ), Drift_Surface_Publish::ACTION_SYNC );
		$force   = wp_nonce_url( add_query_arg( [ 'action' => Drift_Surface_Publish::ACTION_SYNC, 'force' => 1, 'return_to' => rawurlencode( $return ) ], admin_url( 'admin-post.php' ) ), Drift_Surface_Publish::ACTION_SYNC );
		$fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="ds-grid">
			<section class="ds-card">
				<div class="ds-card__head">
					<h3><?php esc_html_e( 'Last sync', 'drift-surface' ); ?></h3>
					<?php if ( ! empty( $status['last_run'] ) ) : ?>
						<span class="ds-pill ds-pill--<?php echo ! empty( $status['ok'] ) ? 'ok' : 'warn'; ?>"><?php echo ! empty( $status['ok'] ) ? esc_html__( 'OK', 'drift-surface' ) : esc_html__( 'Problems', 'drift-surface' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( empty( $status['last_run'] ) ) : ?>
					<p><?php esc_html_e( 'Nothing has synced yet.', 'drift-surface' ); ?></p>
				<?php else : ?>
					<p class="ds-big"><?php echo esc_html( sprintf( /* translators: %s: time diff. */ __( '%s ago', 'drift-surface' ), human_time_diff( (int) $status['last_run'] ) ) ); ?></p>
					<p class="description">
						<?php
						echo esc_html( sprintf(
							/* translators: 1: date, 2: trigger, 3: seconds, 4: hub requests, 5: images. */
							__( '%1$s · triggered by %2$s · %3$ss · %4$d hub requests · %5$d images', 'drift-surface' ),
							wp_date( $fmt, (int) $status['last_run'] ),
							(string) ( $status['trigger'] ?? '' ),
							(string) ( $status['duration'] ?? 0 ),
							(int) ( $status['api_calls'] ?? 0 ),
							(int) ( $status['images'] ?? 0 )
						) );
						?>
					</p>
					<ul class="ds-list">
						<?php foreach ( (array) ( $status['messages'] ?? [] ) as $message ) : ?>
							<li><?php echo esc_html( (string) $message ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="ds-actions">
					<a class="button button-primary" href="<?php echo esc_url( $sync ); ?>"><?php esc_html_e( 'Sync now', 'drift-surface' ); ?></a>
					<a class="button" href="<?php echo esc_url( $force ); ?>" title="<?php esc_attr_e( 'Re-saves every item even if unchanged. Same hub requests as a normal sync.', 'drift-surface' ); ?>"><?php esc_html_e( 'Full resync', 'drift-surface' ); ?></a>
				</p>
			</section>

			<section class="ds-card">
				<h3><?php esc_html_e( 'Publishing', 'drift-surface' ); ?></h3>
				<dl class="ds-facts">
					<dt><?php esc_html_e( 'Last publish received', 'drift-surface' ); ?></dt>
					<dd><?php echo ! empty( $publish['received_at'] ) ? esc_html( wp_date( $fmt, (int) $publish['received_at'] ) ) : esc_html__( 'Never', 'drift-surface' ); ?></dd>
					<dt><?php esc_html_e( 'Next daily check', 'drift-surface' ); ?></dt>
					<dd><?php echo $daily ? esc_html( wp_date( $fmt, $daily ) ) : esc_html__( 'Off', 'drift-surface' ); ?></dd>
					<?php if ( ! empty( $status['pending_images'] ) ) : ?>
						<dt><?php esc_html_e( 'Images', 'drift-surface' ); ?></dt>
						<dd><?php esc_html_e( 'Still importing — continues automatically.', 'drift-surface' ); ?></dd>
					<?php endif; ?>
				</dl>
			</section>
		</div>

		<section class="ds-card">
			<div class="ds-card__head">
				<h3><?php esc_html_e( 'Activity', 'drift-surface' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'drift_surface_clear_log' ); ?>
					<input type="hidden" name="action" value="drift_surface_clear_log">
					<button type="submit" class="button-link"><?php esc_html_e( 'Clear', 'drift-surface' ); ?></button>
				</form>
			</div>
			<?php $entries = array_reverse( Drift_Surface_Log::entries() ); ?>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'No activity yet.', 'drift-surface' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ds-table ds-log">
					<thead><tr><th><?php esc_html_e( 'When', 'drift-surface' ); ?></th><th><?php esc_html_e( 'Source', 'drift-surface' ); ?></th><th><?php esc_html_e( 'Message', 'drift-surface' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr class="ds-log--<?php echo esc_attr( $entry['level'] ); ?>">
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
		self::guard( 'drift_surface_clear_log' );
		Drift_Surface_Log::clear();
		wp_safe_redirect( self::tab_url( 'sync', [ 'drift_surface_notice' => 'log_cleared' ] ) );
		exit;
	}

	/* ── Content tab ─────────────────────────────────────────────────── */

	public static function render_content_tab(): void {
		$map = Drift_Surface_Map::current();
		?>
		<section class="ds-card">
			<h3><?php esc_html_e( 'Tables', 'drift-surface' ); ?></h3>
			<table class="widefat striped ds-table">
				<thead><tr><th><?php esc_html_e( 'Hub table', 'drift-surface' ); ?></th><th><?php esc_html_e( 'WordPress', 'drift-surface' ); ?></th><th><?php esc_html_e( 'Live', 'drift-surface' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $map['settings'] ) : ?>
					<tr><td><strong><?php echo esc_html( $map['settings']['table'] ); ?></strong></td><td><?php esc_html_e( 'Site settings', 'drift-surface' ); ?> <code><?php echo esc_html( $map['settings']['option'] ); ?></code></td><td>—</td></tr>
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
								<?php echo esc_html( $entity['post_type'] ); ?> <em><?php esc_html_e( '(not registered)', 'drift-surface' ); ?></em>
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
			<section class="ds-card">
				<h3><?php esc_html_e( 'Site settings in use', 'drift-surface' ); ?></h3>
				<?php if ( ! is_array( $values ) || ! $values ) : ?>
					<p><?php esc_html_e( 'Nothing synced yet.', 'drift-surface' ); ?></p>
				<?php else : ?>
					<table class="widefat striped ds-table">
						<thead><tr><th><?php esc_html_e( 'Hub field', 'drift-surface' ); ?></th><th><?php esc_html_e( 'Key', 'drift-surface' ); ?></th><th><?php esc_html_e( 'Value', 'drift-surface' ); ?></th></tr></thead>
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
										printf( '<span class="ds-swatch" style="background:%1$s"></span> %1$s', esc_attr( (string) $value ) );
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
		$pages    = Drift_Surface_Page_Creator::visible();
		$existing = Drift_Surface_Page_Creator::existing_ids();
		$total    = count( $pages );
		$done     = count( $existing );

		if ( ! $pages ) {
			echo '<section class="ds-card"><p>' . esc_html__( 'This product map defines no pages.', 'drift-surface' ) . '</p></section>';
			return;
		}

		if ( ! Drift_Surface_Theme_Check::satisfied() ) {
			echo '<section class="ds-card"><p>' . esc_html( Drift_Surface_Theme_Check::blocked_message() ) . '</p></section>';
			return;
		}

		if ( ! function_exists( 'update_field' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'ACF Pro isn\'t active — pages will be created without their modules.', 'drift-surface' ) . '</p></div>';
		}
		?>
		<section class="ds-card ds-wizard" data-total="<?php echo esc_attr( (string) $total ); ?>">
			<div class="ds-card__head">
				<div class="ds-progress"><span style="width:<?php echo esc_attr( (string) ( $total ? round( $done / $total * 100 ) : 0 ) ); ?>%"></span></div>
				<span class="ds-progress__label"><?php echo esc_html( sprintf( /* translators: 1: created, 2: total. */ __( '%1$d of %2$d pages created', 'drift-surface' ), $done, $total ) ); ?></span>
			</div>

			<p class="ds-actions">
				<button type="button" class="button button-small" data-select="all"><?php esc_html_e( 'Select all', 'drift-surface' ); ?></button>
				<button type="button" class="button button-small" data-select="required"><?php esc_html_e( 'Required only', 'drift-surface' ); ?></button>
				<button type="button" class="button button-small" data-select="none"><?php esc_html_e( 'Clear', 'drift-surface' ); ?></button>
			</p>

			<ul class="ds-pages">
				<?php foreach ( $pages as $page ) :
					$exists = in_array( $page['id'], $existing, true );
					$post   = $exists ? Drift_Surface_Page_Creator::find_page( $page ) : null;
					?>
					<li class="ds-page<?php echo $exists ? ' is-existing' : ''; ?><?php echo $page['parent'] ? ' is-child' : ''; ?>" data-id="<?php echo esc_attr( $page['id'] ); ?>" data-required="<?php echo $page['required'] ? '1' : '0'; ?>" <?php echo $page['parent'] ? 'data-parent="' . esc_attr( (string) $page['parent'] ) . '"' : ''; ?>>
						<label>
							<input type="checkbox" value="<?php echo esc_attr( $page['id'] ); ?>" <?php disabled( $exists ); ?> <?php checked( $exists || $page['required'] ); ?>>
							<span class="ds-page__body">
								<strong><?php echo esc_html( $page['title'] ); ?></strong>
								<code>/<?php echo esc_html( $page['slug'] ); ?></code>
								<?php if ( $page['required'] ) : ?><span class="ds-tag"><?php esc_html_e( 'Required', 'drift-surface' ); ?></span><?php endif; ?>
								<?php foreach ( (array) $page['tags'] as $tag ) : ?><span class="ds-tag ds-tag--soft"><?php echo esc_html( (string) $tag ); ?></span><?php endforeach; ?>
								<span class="ds-page__desc"><?php echo esc_html( $page['description'] ); ?></span>
							</span>
						</label>
						<span class="ds-page__status">
							<?php if ( $post ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'drift-surface' ); ?></a>
								<a href="<?php echo esc_url( (string) get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'drift-surface' ); ?></a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="ds-actions">
				<button type="button" class="button button-primary ds-create"><?php esc_html_e( 'Create selected pages', 'drift-surface' ); ?></button>
			</p>
			<ol class="ds-wizard__log" hidden></ol>
		</section>
		<?php
	}

	/* ── Helpers ─────────────────────────────────────────────────────── */

	private static function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) || ! Drift_Surface_Admin_Access::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'drift-surface' ), 403 );
		}
		check_admin_referer( $nonce );
	}
}
