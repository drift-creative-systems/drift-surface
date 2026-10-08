<?php
/**
 * class-media.php — Airtable attachments into the media library, and
 * Airtable rich text into safe HTML.
 *
 * The sideload is keyed by
 * Airtable's stable attachment ID, never its URL: attachment URLs are signed,
 * change on every API response and expire a couple of hours after issue, so
 * hotlinking them breaks pages and page caches.
 *
 * Imports are capped per sync run (Settings → image_batch) so a first sync of
 * a 200-photo gallery can't time out. Anything over the cap is reported back
 * as "deferred" and the sync engine schedules a continuation run.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Media {

	const MAP_OPTION = 'drift_surface_media_map';
	const META_ID    = '_drift_surface_airtable_attachment_id';

	/** @var int Imports made in this request. */
	private static $imported = 0;

	/** @var bool Whether any import was skipped because the batch cap was hit. */
	private static $deferred = false;

	public static function reset_run(): void {
		self::$imported = 0;
		self::$deferred = false;
	}

	public static function was_deferred(): bool {
		return self::$deferred;
	}

	public static function imported_count(): int {
		return self::$imported;
	}

	/**
	 * Attachment ID for an Airtable attachment, importing it if needed.
	 *
	 * @param array $attachment One item from an Airtable attachment field.
	 * @param int   $parent_id  Post to attach to, 0 for none.
	 * @return int Attachment ID, or 0 (failed or deferred — the caller should retry next run).
	 */
	public static function attachment_id( array $attachment, int $parent_id = 0 ): int {
		$airtable_id = (string) ( $attachment['id'] ?? '' );
		$source_url  = (string) ( $attachment['url'] ?? '' );

		if ( '' === $airtable_id || '' === $source_url ) {
			return 0;
		}

		$map = get_option( self::MAP_OPTION, [] );
		$map = is_array( $map ) ? $map : [];

		if ( isset( $map[ $airtable_id ] ) && wp_get_attachment_url( (int) $map[ $airtable_id ] ) ) {
			return (int) $map[ $airtable_id ];
		}

		$cap = max( 1, (int) Drift_Surface_Settings::get( 'image_batch', 20 ) );
		if ( self::$imported >= $cap ) {
			self::$deferred = true;
			return 0;
		}

		$type = strtolower( (string) ( $attachment['type'] ?? '' ) );
		if ( $type && ! in_array( $type, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'application/pdf' ], true ) ) {
			Drift_Surface_Log::warning( sprintf( 'Skipped attachment "%s": file type %s is not allowed.', (string) ( $attachment['filename'] ?? $airtable_id ), $type ) );
			return 0;
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		self::$imported++;

		$tmp = download_url( $source_url, 30 );
		if ( is_wp_error( $tmp ) ) {
			Drift_Surface_Log::error( 'Image download failed — ' . $tmp->get_error_message() );
			return 0;
		}

		$attachment_id = media_handle_sideload(
			[
				'name'     => sanitize_file_name( (string) ( $attachment['filename'] ?? $airtable_id ) ),
				'tmp_name' => $tmp,
			],
			$parent_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			Drift_Surface_Log::error( 'Media import failed — ' . $attachment_id->get_error_message() );
			return 0;
		}

		update_post_meta( $attachment_id, self::META_ID, $airtable_id );

		$map[ $airtable_id ] = $attachment_id;
		update_option( self::MAP_OPTION, $map, false );

		return (int) $attachment_id;
	}

	/**
	 * Airtable rich text (Markdown) → safe HTML. Escapes first, so nothing in
	 * the source can inject markup. Handles **bold**, _italic_, [links](…),
	 * # headings (h3 at most — the page owns h1/h2), lists and paragraphs.
	 */
	public static function markdown_to_html( string $markdown ): string {
		$text = esc_html( str_replace( "\r\n", "\n", trim( $markdown ) ) );

		if ( '' === $text ) {
			return '';
		}

		$text = preg_replace_callback(
			'/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES ) ) . '">' . $m[1] . '</a>';
			},
			$text
		);

		$text = preg_replace( '/\*\*([^*\n]+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<![\w*])[*_]([^*_\n]+?)[*_](?![\w*])(?![^<]*>)/', '<em>$1</em>', $text );

		$html = [];
		$list = '';
		foreach ( explode( "\n", $text ) as $line ) {
			$level = preg_match( '/^\s*(#{1,6})\s+(.+)$/', $line, $h ) ? max( 3, strlen( $h[1] ) ) : 0;

			if ( preg_match( '/^\s*[-*]\s+(.+)$/', $line, $m ) ) {
				$type = 'ul';
			} elseif ( preg_match( '/^\s*\d+\.\s+(.+)$/', $line, $m ) ) {
				$type = 'ol';
			} else {
				$type = '';
			}

			if ( $list && $type !== $list ) {
				$html[] = '</' . $list . '>';
				$list   = '';
			}

			if ( $type ) {
				if ( ! $list ) {
					$html[] = '<' . $type . '>';
					$list   = $type;
				}
				$html[] = '<li>' . $m[1] . '</li>';
			} elseif ( $level ) {
				$html[] = "\n<h" . $level . '>' . $h[2] . '</h' . $level . ">\n";
			} else {
				$html[] = $line;
			}
		}
		if ( $list ) {
			$html[] = '</' . $list . '>';
		}

		return wpautop( implode( "\n", $html ) );
	}

	/**
	 * Iframe-only embed code (store widgets, tour-date players) from an
	 * Airtable Long text field. Everything except <iframe> is stripped, so a
	 * pasted <script> widget comes through as nothing rather than running on
	 * the site. Only https sources survive, and srcdoc is never allowed (it
	 * would run in the site's own origin). Text around the iframes is dropped.
	 */
	public static function embed( string $code ): string {
		$allowed = [
			'iframe' => [
				'src'             => true,
				'title'           => true,
				'width'           => true,
				'height'          => true,
				'style'           => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'frameborder'     => true,
				'scrolling'       => true,
				'loading'         => true,
				'referrerpolicy'  => true,
				'name'            => true,
			],
		];

		// With https as the only protocol, an http:// src is reduced to //…
		// by kses and then fails the https match below.
		$html = wp_kses( $code, $allowed, [ 'https' ] );

		if ( ! preg_match_all( '~<iframe\b[^>]*\bsrc="https://[^"]+"[^>]*>.*?</iframe>~is', $html, $m ) ) {
			return '';
		}

		return implode( "\n", $m[0] );
	}

	/**
	 * Plain text from any Airtable value — strings, numbers, booleans,
	 * lookups/rollups (arrays), AI-field envelopes ({value: …}), collaborators.
	 */
	public static function plain( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'value', $value ) && ! isset( $value[0] ) ) {
				return self::plain( $value['value'] );
			}
			if ( isset( $value['name'] ) && ! isset( $value[0] ) ) {
				return trim( (string) $value['name'] );
			}
			$parts = [];
			foreach ( $value as $item ) {
				$text = self::plain( $item );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
			return implode( ', ', $parts );
		}
		return '';
	}
}
