<?php
/**
 * class-theme-check.php — pairs a product with its theme.
 *
 * A product map can name the theme it renders with (maps/surface.php →
 * 'theme'). When that theme (or a child of it) isn't the active theme, Drift: Surface
 * shows a persistent notice with a one-click "Install & activate" (or
 * "Activate") button and blocks the Setup Wizard, because the wizard's pages
 * are made of that theme's modules. Maps without a 'theme' key are not
 * affected, so the plugin stays product-agnostic.
 *
 * The matching check on the other side lives in the theme
 * (encore-theme/inc/requirements.php).
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Theme_Check {

	const ACTION = 'drift_surface_install_theme';
	const ERROR  = 'drift_surface_theme_error';

	public static function init(): void {
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_install' ] );
	}

	/**
	 * The theme the current map requires, or null.
	 *
	 * @return array{slug: string, name: string, zip: string}|null
	 */
	public static function required(): ?array {
		$theme = Drift_Surface_Map::current()['theme'] ?? null;
		return is_array( $theme ) && ! empty( $theme['slug'] ) ? $theme : null;
	}

	/** True when the map needs no theme, or its theme (or a child of it) is active. */
	public static function satisfied(): bool {
		$theme = self::required();
		return ! $theme || self::matches( wp_get_theme( get_template() ), $theme );
	}

	/** The installed copy of the required theme, if any (folder name may differ). */
	public static function installed(): ?WP_Theme {
		$theme = self::required();
		if ( ! $theme ) {
			return null;
		}
		$exact = wp_get_theme( $theme['slug'] );
		if ( $exact->exists() ) {
			return $exact;
		}
		foreach ( wp_get_themes() as $candidate ) {
			if ( ! $candidate->parent() && self::matches( $candidate, $theme ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/** Same folder, or a renamed folder carrying the same Theme Name. */
	private static function matches( WP_Theme $candidate, array $theme ): bool {
		return $candidate->exists()
			&& ( $candidate->get_stylesheet() === $theme['slug'] || $candidate->get( 'Name' ) === $theme['name'] );
	}

	/** Explanation shown wherever the wizard is blocked. */
	public static function blocked_message(): string {
		$theme = self::required();
		/* translators: %s: theme name. */
		return sprintf( __( 'The Setup Wizard builds pages from the %s theme\'s modules. Activate the theme, then come back here.', 'drift-surface' ), $theme ? $theme['name'] : '' );
	}

	/* ── Notice ──────────────────────────────────────────────────────── */

	public static function notice(): void {
		if ( self::satisfied() || ! current_user_can( 'switch_themes' ) ) {
			return;
		}

		$theme     = (array) self::required();
		$installed = self::installed();

		$error = get_transient( self::ERROR );
		if ( $error ) {
			delete_transient( self::ERROR );
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( (string) $error ) );
		}

		if ( $installed ) {
			$label = __( 'Activate', 'drift-surface' );
			$url   = wp_nonce_url( add_query_arg( [ 'action' => 'activate', 'stylesheet' => $installed->get_stylesheet() ], admin_url( 'themes.php' ) ), 'switch-theme_' . $installed->get_stylesheet() );
		} elseif ( current_user_can( 'install_themes' ) && ! empty( $theme['zip'] ) ) {
			$label = __( 'Install & activate', 'drift-surface' );
			$url   = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		} else {
			$label = '';
			$url   = '';
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s',
			/* translators: %s: theme name. */
			esc_html( sprintf( __( 'Drift: Surface needs the %s theme.', 'drift-surface' ), $theme['name'] ?? '' ) ),
			esc_html__( 'The plugin and theme work as a pair: until it\'s active, the Setup Wizard is switched off.', 'drift-surface' )
		);
		if ( $url ) {
			printf( ' <a class="button button-primary" href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label . ' ' . ( $theme['name'] ?? '' ) ) );
		}
		echo '</p></div>';
	}

	/* ── One-click install ───────────────────────────────────────────── */

	public static function handle_install(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'install_themes' ) || ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'drift-surface' ), '', [ 'response' => 403 ] );
		}

		$theme = self::required();
		if ( ! $theme || empty( $theme['zip'] ) ) {
			self::fail( __( 'This product map doesn\'t say where to download its theme from.', 'drift-surface' ) );
		}

		$installed = self::installed();
		if ( ! $installed ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			// Needs direct filesystem access; FTP-credential hosts get a clear message instead of a form.
			if ( 'direct' !== get_filesystem_method() ) {
				self::fail( __( 'WordPress can\'t write to the themes folder directly here. Upload the theme zip under Appearance → Themes → Add New instead.', 'drift-surface' ) );
			}

			$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( esc_url_raw( $theme['zip'] ) );

			if ( is_wp_error( $result ) || ! $result ) {
				$message = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', (array) $upgrader->skin->get_error_messages() );
				Drift_Surface_Log::error( 'Theme install failed: ' . $message, 'setup' );
				/* translators: %s: error message. */
				self::fail( sprintf( __( 'The theme couldn\'t be installed: %s', 'drift-surface' ), $message ) );
			}

			wp_clean_themes_cache();
			$installed = self::installed();
			if ( ! $installed ) {
				self::fail( __( 'The theme downloaded but WordPress can\'t find it. Check Appearance → Themes.', 'drift-surface' ) );
			}
		}

		switch_theme( $installed->get_stylesheet() );
		Drift_Surface_Log::info( 'Installed and activated the ' . $installed->get( 'Name' ) . ' theme.', 'setup' );

		wp_safe_redirect( Drift_Surface_Admin_Page::tab_url( 'wizard' ) );
		exit;
	}

	/** Back to where the user came from, with the error shown once in the notice. */
	private static function fail( string $message ): void {
		set_transient( self::ERROR, $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'themes.php' ) );
		exit;
	}
}
