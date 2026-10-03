<?php
/**
 * class-crypto.php — encrypts secrets (Airtable token, publish secret) at
 * rest in wp_options.
 *
 * AES-256-GCM via OpenSSL. The key comes from DRIFT_WEBSITE_KEY if defined in
 * wp-config.php, otherwise from WordPress's own auth salts — so a database
 * dump on its own doesn't expose the token. Changing the salts (or the
 * constant) makes stored secrets unreadable; the Connection tab then simply
 * asks for the token again.
 *
 * Stored format: "dw1:" + base64( iv[12] . tag[16] . ciphertext ).
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Website_Crypto {

	const PREFIX = 'dw1:';

	private static function key(): string {
		$material = defined( 'DRIFT_WEBSITE_KEY' ) && DRIFT_WEBSITE_KEY
			? (string) DRIFT_WEBSITE_KEY
			: wp_salt( 'auth' ) . wp_salt( 'secure_auth' );

		return hash( 'sha256', 'drift-website|' . $material, true );
	}

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	public static function seal( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::available() ) {
			// Never store a secret we can't protect. The settings screen
			// warns when OpenSSL is missing.
			return '';
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher ) {
			return '';
		}

		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function unseal( string $stored ): string {
		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) || ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

		return false === $plain ? '' : $plain;
	}
}
