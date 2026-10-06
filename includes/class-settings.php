<?php
/**
 * class-settings.php — the plugin's one settings option.
 *
 * Connection settings. Everything lives in one option (self::OPTION), secrets
 * encrypted by Encore_Website_Crypto.
 *
 * wp-config.php overrides (handy for local/staging, and they win over the
 * screen): ENCORE_WEBSITE_AIRTABLE_BASE, ENCORE_WEBSITE_AIRTABLE_TOKEN. The
 * 1.x DRIFT_WEBSITE_* names still work (encore_website_constant()).
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Encore_Website_Settings {

	const OPTION = 'encore_website_settings';

	public static function defaults(): array {
		return [
			'base_id'        => '',
			'token'          => '', // Sealed.
			'publish_secret' => '', // Sealed.
			'product'        => 'encore',
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
		if ( 'base_id' === $key && self::base_from_constant() ) {
			return encore_website_constant( 'AIRTABLE_BASE' );
		}

		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	public static function token(): string {
		if ( self::token_from_constant() ) {
			return encore_website_constant( 'AIRTABLE_TOKEN' );
		}
		return Encore_Website_Crypto::unseal( (string) ( self::all()['token'] ?? '' ) );
	}

	public static function has_token(): bool {
		return '' !== self::token();
	}

	public static function is_connected(): bool {
		return self::valid_base_id( (string) self::get( 'base_id' ) ) && self::has_token();
	}

	public static function token_from_constant(): bool {
		return '' !== encore_website_constant( 'AIRTABLE_TOKEN' );
	}

	public static function base_from_constant(): bool {
		return '' !== encore_website_constant( 'AIRTABLE_BASE' );
	}

	public static function valid_base_id( string $id ): bool {
		return (bool) preg_match( '/^app[A-Za-z0-9]{14}$/', $id );
	}

	/* ── Publish secret ──────────────────────────────────────────────── */

	public static function publish_secret(): string {
		return Encore_Website_Crypto::unseal( (string) ( self::all()['publish_secret'] ?? '' ) );
	}

	public static function ensure_publish_secret(): void {
		if ( '' === self::publish_secret() ) {
			self::regenerate_publish_secret();
		}
	}

	public static function regenerate_publish_secret(): string {
		$secret        = wp_generate_password( 40, false, false );
		$all           = self::all();
		$all['publish_secret'] = Encore_Website_Crypto::seal( $secret );
		update_option( self::OPTION, $all, false );
		return $secret;
	}

	/* ── Save ────────────────────────────────────────────────────────── */

	/**
	 * Sanitises and saves the Connection tab. A blank token field keeps the
	 * stored token; tick "clear_token" to remove it.
	 *
	 * @param array $input Unslashed $_POST['encore'].
	 * @return array{changed_connection: bool} What changed, for the caller.
	 */
	public static function save( array $input ): array {
		$before = self::all();
		$after  = $before;

		$base = strtoupper( substr( trim( (string) ( $input['base_id'] ?? '' ) ), 0, 3 ) ) === 'APP'
			? 'app' . substr( trim( (string) $input['base_id'] ), 3 )
			: trim( (string) ( $input['base_id'] ?? '' ) );
		$after['base_id'] = self::valid_base_id( $base ) ? $base : '';

		$token = trim( (string) ( $input['token'] ?? '' ) );
		if ( ! empty( $input['clear_token'] ) ) {
			$after['token'] = '';
		} elseif ( '' !== $token ) {
			$after['token'] = Encore_Website_Crypto::seal( preg_replace( '/[^A-Za-z0-9._-]/', '', $token ) );
		}

		$products         = array_keys( Encore_Website_Map::available() );
		$product          = sanitize_key( (string) ( $input['product'] ?? '' ) );
		$after['product'] = in_array( $product, $products, true ) ? $product : ( $products[0] ?? 'encore' );

		$after['daily_check'] = empty( $input['daily_check'] ) ? '0' : '1';
		$after['api_budget']  = max( 100, min( 1000000, absint( $input['api_budget'] ?? 1000 ) ) );
		$after['image_batch'] = max( 1, min( 200, absint( $input['image_batch'] ?? 20 ) ) );

		update_option( self::OPTION, $after, false );

		if ( '1' === $after['daily_check'] ) {
			Encore_Website_Publish::schedule_daily_check();
		} else {
			Encore_Website_Publish::unschedule_daily_check();
		}

		return [
			'changed_connection' => $before['base_id'] !== $after['base_id'] || $before['token'] !== $after['token'] || $before['product'] !== $after['product'],
		];
	}
}
