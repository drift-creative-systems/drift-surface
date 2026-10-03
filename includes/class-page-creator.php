<?php
/**
 * class-page-creator.php — the Site Setup Wizard's engine: creates a
 * product's standard pages with their page-builder rows pre-filled.
 *
 * Ported from vision-website's TTNG_Setup_Page_Creator / _Ajax_Handler. The
 * page list now comes from the product map ('pages'), so the same wizard
 * sets up an Encore band site or any later Drift product.
 *
 * Page definition (map 'pages' entries):
 *   id, title, slug ('' = front page), description, tags[], required (bool),
 *   rows[] (page_builder rows: 'acf_fc_layout' + sub-field values),
 *   template (optional), parent (optional page id), in_menu (bool),
 *   companions[] / companion_of (detail pages created with a listing page).
 *
 * Use self::IMAGE_PLACEHOLDER as an image sub-field value to get the shared
 * placeholder image.
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Website_Page_Creator {

	const IMAGE_PLACEHOLDER = '__drift_placeholder_image__';
	const PLACEHOLDER_META  = '_drift_placeholder';
	const NONCE             = 'drift_setup_nonce';
	const MENU_NAME         = 'Main Menu';

	public static function init(): void {
		add_action( 'wp_ajax_drift_setup_create_page', [ __CLASS__, 'ajax_create_page' ] );
	}

	/* ── Definitions ─────────────────────────────────────────────────── */

	/** @return array[] Every page definition in the active map. */
	public static function definitions(): array {
		$pages = [];
		foreach ( (array) Drift_Website_Map::current()['pages'] as $page ) {
			if ( ! is_array( $page ) || empty( $page['id'] ) || empty( $page['title'] ) ) {
				continue;
			}
			$pages[] = array_merge(
				[
					'slug'        => '',
					'description' => '',
					'tags'        => [],
					'required'    => false,
					'rows'        => [],
					'template'    => null,
					'parent'      => null,
					'in_menu'     => false,
					'companions'  => [],
				],
				$page
			);
		}
		return $pages;
	}

	/** Definitions shown in the wizard (detail pages ride along with their listing page). */
	public static function visible(): array {
		return array_values( array_filter( self::definitions(), static fn( $p ) => empty( $p['companion_of'] ) ) );
	}

	public static function get( string $id ): ?array {
		foreach ( self::definitions() as $page ) {
			if ( $page['id'] === $id ) {
				return $page;
			}
		}
		return null;
	}

	/* ── AJAX ────────────────────────────────────────────────────────── */

	public static function ajax_create_page(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'drift-website' ) ], 403 );
		}

		$page_id = sanitize_key( (string) wp_unslash( $_POST['page_id'] ?? '' ) );
		if ( '' === $page_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing page.', 'drift-website' ) ], 400 );
		}

		$result = self::create( $page_id );

		if ( ! $result['ok'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ], 500 );
		}

		wp_send_json_success( [
			'message'  => $result['message'],
			'page_id'  => $result['page_id'],
			'skipped'  => ! empty( $result['skipped'] ),
			'edit_url' => $result['page_id'] ? get_edit_post_link( $result['page_id'], 'raw' ) : '',
			'view_url' => $result['page_id'] ? get_permalink( $result['page_id'] ) : '',
		] );
	}

	/* ── Creation ────────────────────────────────────────────────────── */

	/**
	 * Creates a page and its companions. Existing pages are skipped, so
	 * re-running is safe.
	 *
	 * @return array{ok: bool, page_id: int|null, message: string, skipped?: bool}
	 */
	public static function create( string $id ): array {
		$result = self::create_single( $id );
		if ( ! $result['ok'] ) {
			return $result;
		}

		foreach ( (array) ( self::get( $id )['companions'] ?? [] ) as $companion ) {
			$c                  = self::create_single( (string) $companion );
			$result['message'] .= ' ' . $c['message'];
			if ( $c['ok'] && empty( $c['skipped'] ) ) {
				$result['skipped'] = false;
			}
		}

		return $result;
	}

	private static function create_single( string $id ): array {
		$def = self::get( $id );
		if ( ! $def ) {
			/* translators: %s: page id. */
			return [ 'ok' => false, 'page_id' => null, 'message' => sprintf( __( 'Unknown page "%s".', 'drift-website' ), $id ) ];
		}

		$existing = self::find_page( $def );
		if ( $existing ) {
			return [
				'ok'      => true,
				'page_id' => $existing->ID,
				/* translators: 1: title, 2: post ID. */
				'message' => sprintf( __( '"%1$s" already exists (ID %2$d) — skipped.', 'drift-website' ), $def['title'], $existing->ID ),
				'skipped' => true,
			];
		}

		$postarr = [
			'post_title'   => wp_strip_all_tags( $def['title'] ),
			'post_name'    => $def['slug'] ?: sanitize_title( $def['title'] ),
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => get_current_user_id(),
		];

		$note = '';
		if ( $def['parent'] ) {
			$parent_def  = self::get( (string) $def['parent'] );
			$parent_page = $parent_def ? self::find_page( $parent_def ) : null;
			if ( $parent_page ) {
				$postarr['post_parent'] = $parent_page->ID;
			} else {
				$note = ' ' . __( 'Its parent page doesn\'t exist yet, so it was created top-level.', 'drift-website' );
			}
		}

		if ( $def['template'] && file_exists( get_theme_file_path( (string) $def['template'] ) ) ) {
			$postarr['page_template'] = (string) $def['template'];
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return [ 'ok' => false, 'page_id' => null, 'message' => $post_id->get_error_message() ];
		}

		if ( $def['rows'] && function_exists( 'update_field' ) ) {
			$rows           = $def['rows'];
			$placeholder_id = null;

			array_walk_recursive(
				$rows,
				static function ( &$value ) use ( &$placeholder_id ) {
					if ( self::IMAGE_PLACEHOLDER !== $value ) {
						return;
					}
					if ( null === $placeholder_id ) {
						$placeholder_id = self::placeholder_image();
					}
					$value = $placeholder_id ?: '';
				}
			);

			update_field( self::page_builder_key(), $rows, $post_id );
		} elseif ( $def['rows'] ) {
			$note .= ' ' . __( 'ACF Pro isn\'t active, so its modules weren\'t added.', 'drift-website' );
		}

		if ( '' === $def['slug'] ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $post_id );
		}

		if ( $def['in_menu'] ) {
			self::add_to_menu( (int) $post_id, (int) ( $postarr['post_parent'] ?? 0 ) );
		}

		flush_rewrite_rules( false );

		return [
			'ok'      => true,
			'page_id' => (int) $post_id,
			/* translators: 1: title, 2: ID. */
			'message' => sprintf( __( '"%1$s" created (ID %2$d).', 'drift-website' ), $def['title'], $post_id ) . $note,
			'skipped' => false,
		];
	}

	/**
	 * The page_builder field's key. update_field() needs the key on a page
	 * that has never been saved; the map names the field, the active theme's
	 * ACF JSON supplies the key.
	 */
	private static function page_builder_key(): string {
		$name = (string) Drift_Website_Map::current()['page_builder_field'];
		if ( 0 === strpos( $name, 'field_' ) || ! function_exists( 'acf_get_field' ) ) {
			return $name;
		}
		$field = acf_get_field( $name );
		return is_array( $field ) && ! empty( $field['key'] ) ? (string) $field['key'] : $name;
	}

	/** Which visible definitions already exist (and their companions). */
	public static function existing_ids(): array {
		$out = [];
		foreach ( self::visible() as $def ) {
			if ( ! self::exists( $def ) ) {
				continue;
			}
			foreach ( (array) $def['companions'] as $companion ) {
				$c = self::get( (string) $companion );
				if ( $c && ! self::exists( $c ) ) {
					continue 2;
				}
			}
			$out[] = $def['id'];
		}
		return $out;
	}

	public static function exists( array $def ): bool {
		$page = self::find_page( $def );
		return $page && 'publish' === $page->post_status;
	}

	/** @return WP_Post|null */
	public static function find_page( array $def ) {
		if ( '' === $def['slug'] ) {
			$front = (int) get_option( 'page_on_front' );
			return 'page' === get_option( 'show_on_front' ) && $front ? get_post( $front ) : null;
		}

		if ( $def['parent'] ) {
			$parent = self::get( (string) $def['parent'] );
			if ( $parent && '' !== $parent['slug'] ) {
				$page = get_page_by_path( $parent['slug'] . '/' . $def['slug'] );
				if ( $page ) {
					return $page;
				}
			}
		}

		return get_page_by_path( $def['slug'] );
	}

	/**
	 * Adds a page to "Main Menu", creating the menu (and assigning it to the
	 * theme's first menu location) if needed.
	 */
	private static function add_to_menu( int $page_id, int $parent_page = 0 ): void {
		$menu = wp_get_nav_menu_object( self::MENU_NAME );
		if ( ! $menu ) {
			$menu_id = wp_create_nav_menu( self::MENU_NAME );
			if ( is_wp_error( $menu_id ) ) {
				return;
			}
			$locations = get_registered_nav_menus();
			$assigned  = get_theme_mod( 'nav_menu_locations', [] );
			$first     = (string) array_key_first( $locations );
			if ( $first && empty( $assigned[ $first ] ) ) {
				$assigned[ $first ] = $menu_id;
				set_theme_mod( 'nav_menu_locations', $assigned );
			}
		} else {
			$menu_id = (int) $menu->term_id;
		}

		$parent_item = 0;
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			if ( 'page' === $item->object && (int) $item->object_id === $page_id ) {
				return; // Already there.
			}
			if ( $parent_page && 'page' === $item->object && (int) $item->object_id === $parent_page ) {
				$parent_item = (int) $item->ID;
			}
		}

		wp_update_nav_menu_item( $menu_id, 0, [
			'menu-item-object-id' => $page_id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_item,
		] );
	}

	/** The shared 1600×900 placeholder image, created once. */
	private static function placeholder_image(): int {
		$existing = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_key'       => self::PLACEHOLDER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		if ( $existing ) {
			return (int) $existing[0];
		}

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return 0;
		}

		$w      = 1600;
		$h      = 900;
		$canvas = imagecreatetruecolor( $w, $h );
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 226, 236, 243 ) ); // #e2ecf3
		$fg    = imagecolorallocate( $canvas, 120, 130, 140 );
		$label = 'Image placeholder — replace in Airtable';
		imagestring( $canvas, 5, (int) ( ( $w - imagefontwidth( 5 ) * strlen( $label ) ) / 2 ), (int) ( ( $h - imagefontheight( 5 ) ) / 2 ), $label, $fg );

		$upload = wp_upload_dir();
		$file   = trailingslashit( $upload['path'] ) . 'drift-placeholder.png';
		imagepng( $canvas, $file );
		imagedestroy( $canvas );

		if ( ! file_exists( $file ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Drift placeholder', 'post_status' => 'inherit' ], $file );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		update_post_meta( $id, self::PLACEHOLDER_META, '1' );

		return (int) $id;
	}
}
