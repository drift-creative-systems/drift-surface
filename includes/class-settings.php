<?php
/**
 * class-settings.php — the plugin's one settings option.
 *
 * Connection settings. Everything lives in one option (self::OPTION), secrets
 * encrypted by Drift_Surface_Crypto.
 *
 * wp-config.php overrides (handy for local/staging, and they win over the
 * screen): DRIFT_SURFACE_AIRTABLE_BASE, DRIFT_SURFACE_AIRTABLE_TOKEN. The
 * 2.x ENCORE_WEBSITE_* names still work (drift_surface_constant()).
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Settings {

	const OPTION = 'drift_surface_settings';

	public static function defaults(): array {
		return [
			'api_base'       => '', // Blank = Airtable. A Drift: Surface Hub's API address otherwise.
			'base_id'        => '',
			'token'          => '', // Sealed.
			'publish_secret' => '', // Sealed.
			'product'        => 'surface',
			'daily_check'    => '1',
			'api_budget'     => 1000, // Airtable Free plan: 1,000 API calls / workspace / month.
			'image_batch'    => 20,   // Images imported per sync run before deferring the rest.
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	/**
	 * A plain setting. Secrets are never returned here — use token() /
	 * publish_secret().
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = '' ) {
		if ( in_array( $key, [ 'token', 'publish_secret' ], true ) ) {
			return $default;
		}
		if ( 'api_base' === $key && '' !== drift_surface_constant( 'API_BASE' ) ) {
			return drift_surface_constant( 'API_BASE' );
		}
		if ( 'base_id' === $key && self::base_from_constant() ) {
			return drift_surface_constant( 'AIRTABLE_BASE' );
		}

		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	public static function token(): string {
		if ( self::token_from_constant() ) {
			return drift_surface_constant( 'AIRTABLE_TOKEN' );
		}
		return Drift_Surface_Crypto::unseal( (string) ( self::all()['token'] ?? '' ) );
	}

	public static function has_token(): bool {
		return '' !== self::token();
	}

	public static function is_connected(): bool {
		return self::valid_base_id( (string) self::get( 'base_id' ) ) && self::has_token();
	}

	public static function token_from_constant(): bool {
		return '' !== drift_surface_constant( 'AIRTABLE_TOKEN' );
	}

	public static function base_from_constant(): bool {
		return '' !== drift_surface_constant( 'AIRTABLE_BASE' );
	}

	public static function valid_base_id( string $id ): bool {
		return (bool) preg_match( '/^app[A-Za-z0-9]{14}$/', $id );
	}

	/* ── Publish secret ──────────────────────────────────────────────── */

	public static function publish_secret(): string {
		return Drift_Surface_Crypto::unseal( (string) ( self::all()['publish_secret'] ?? '' ) );
	}

	public static function ensure_publish_secret(): void {
		if ( '' === self::publish_secret() ) {
			self::regenerate_publish_secret();
		}
	}

	public static function regenerate_publish_secret(): string {
		$secret        = wp_generate_password( 40, false, false );
		$all           = self::all();
		$all['publish_secret'] = Drift_Surface_Crypto::seal( $secret );
		update_option( self::OPTION, $all, false );
		return $secret;
	}

	/* ── Save ────────────────────────────────────────────────────────── */

	/**
	 * Sanitises and saves the Connection tab. A blank token field keeps the
	 * stored token; tick "clear_token" to remove it.
	 *
	 * @param array $input Unslashed $_POST['drift_surface'].
	 * @return array{changed_connection: bool} What changed, for the caller.
	 */
	public static function save( array $input ): array {
		$before = self::all();
		$after  = $before;

		$base = strtoupper( substr( trim( (string) ( $input['base_id'] ?? '' ) ), 0, 3 ) ) === 'APP'
			? 'app' . substr( trim( (string) $input['base_id'] ), 3 )
			: trim( (string) ( $input['base_id'] ?? '' ) );
		$after['base_id'] = self::valid_base_id( $base ) ? $base : '';

		$api_base          = esc_url_raw( trim( (string) ( $input['api_base'] ?? '' ) ), [ 'https', 'http' ] );
		$after['api_base'] = $api_base ? trailingslashit( $api_base ) : '';

		$token = trim( (string) ( $input['token'] ?? '' ) );
		if ( ! empty( $input['clear_token'] ) ) {
			$after['token'] = '';
		} elseif ( '' !== $token ) {
			$after['token'] = Drift_Surface_Crypto::seal( preg_replace( '/[^A-Za-z0-9._-]/', '', $token ) );
		}

		$products         = array_keys( Drift_Surface_Map::available() );
		$product          = sanitize_key( (string) ( $input['product'] ?? '' ) );
		$after['product'] = in_array( $product, $products, true ) ? $product : ( $products[0] ?? 'surface' );

		$after['daily_check'] = empty( $input['daily_check'] ) ? '0' : '1';
		$after['api_budget']  = max( 100, min( 1000000, absint( $input['api_budget'] ?? 1000 ) ) );
		$after['image_batch'] = max( 1, min( 200, absint( $input['image_batch'] ?? 20 ) ) );

		update_option( self::OPTION, $after, false );

		if ( '1' === $after['daily_check'] ) {
			Drift_Surface_Publish::schedule_daily_check();
		} else {
			Drift_Surface_Publish::unschedule_daily_check();
		}

		return [
			'changed_connection' => $before['api_base'] !== $after['api_base'] || $before['base_id'] !== $after['base_id'] || $before['token'] !== $after['token'] || $before['product'] !== $after['product'],
		];
	}
}
