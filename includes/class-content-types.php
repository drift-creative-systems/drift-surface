<?php
/**
 * class-content-types.php — registers the post types and taxonomies a
 * product map declares (entities with a 'register' block, plus 'taxonomies').
 *
 * They live in the plugin rather than the theme so synced content survives a
 * theme switch. Themes adjust registration with the
 * `drift_surface_post_type_args` / `drift_surface_taxonomy_args` filters.
 *
 * Also shows a "managed in the hub" notice on synced items' edit screens,
 * since anything edited in wp-admin is overwritten by the next sync.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Content_Types {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
		add_action( 'edit_form_after_title', [ __CLASS__, 'synced_notice' ] );
	}

	public static function register(): void {
		$map = Drift_Surface_Map::current();

		foreach ( $map['entities'] as $entity ) {
			if ( empty( $entity['register'] ) || ! is_array( $entity['register'] ) || post_type_exists( $entity['post_type'] ) ) {
				continue;
			}
			register_post_type( $entity['post_type'], self::post_type_args( $entity ) );
		}

		foreach ( (array) $map['taxonomies'] as $taxonomy => $spec ) {
			if ( ! is_array( $spec ) || taxonomy_exists( (string) $taxonomy ) ) {
				continue;
			}
			register_taxonomy( (string) $taxonomy, (array) ( $spec['object_types'] ?? [] ), self::taxonomy_args( (string) $taxonomy, $spec ) );
		}
	}

	private static function post_type_args( array $entity ): array {
		$r        = $entity['register'];
		$plural   = (string) ( $r['label'] ?? ucfirst( $entity['key'] ) );
		$singular = (string) ( $r['singular'] ?? $plural );
		$public   = (bool) ( $r['public'] ?? true );

		$args = [
			'labels'        => [
				'name'               => $plural,
				'singular_name'      => $singular,
				'add_new_item'       => sprintf( /* translators: %s: singular label. */ __( 'Add %s', 'drift-surface' ), $singular ),
				'edit_item'          => sprintf( /* translators: %s: singular label. */ __( 'Edit %s', 'drift-surface' ), $singular ),
				'all_items'          => $plural,
				'search_items'       => sprintf( /* translators: %s: plural label. */ __( 'Search %s', 'drift-surface' ), $plural ),
				'not_found'          => sprintf( /* translators: %s: plural label. */ __( 'No %s found.', 'drift-surface' ), strtolower( $plural ) ),
				'not_found_in_trash' => sprintf( /* translators: %s: plural label. */ __( 'No %s in the bin.', 'drift-surface' ), strtolower( $plural ) ),
			],
			'public'              => $public,
			'publicly_queryable'  => $public,
			'exclude_from_search' => ! $public,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'has_archive'         => (bool) ( $r['has_archive'] ?? false ),
			'rewrite'             => $public ? [ 'slug' => (string) ( $r['rewrite'] ?? $entity['key'] ), 'with_front' => false ] : false,
			'menu_icon'           => (string) ( $r['menu_icon'] ?? 'dashicons-admin-post' ),
			'menu_position'       => (int) ( $r['menu_position'] ?? 25 ),
			'supports'            => (array) ( $r['supports'] ?? [ 'title', 'editor', 'thumbnail', 'page-attributes' ] ),
			'hierarchical'        => false,
		];

		return (array) apply_filters( 'drift_surface_post_type_args', $args, $entity );
	}

	private static function taxonomy_args( string $taxonomy, array $spec ): array {
		$plural   = (string) ( $spec['label'] ?? $taxonomy );
		$singular = (string) ( $spec['singular'] ?? $plural );
		$public   = (bool) ( $spec['public'] ?? false );

		$args = [
			'labels'            => [
				'name'          => $plural,
				'singular_name' => $singular,
			],
			'public'            => $public,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'hierarchical'      => (bool) ( $spec['hierarchical'] ?? false ),
			'rewrite'           => $public ? [ 'slug' => (string) ( $spec['rewrite'] ?? $taxonomy ), 'with_front' => false ] : false,
		];

		return (array) apply_filters( 'drift_surface_taxonomy_args', $args, $taxonomy, $spec );
	}

	/**
	 * "This item is managed in the hub" on synced posts' edit screens.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function synced_notice( $post ): void {
		if ( ! $post instanceof WP_Post || ! get_post_meta( $post->ID, Drift_Surface_Sync_Engine::META_ID, true ) ) {
			return;
		}

		$entity = Drift_Surface_Map::entity_for_post_type( $post->post_type );
		$table  = $entity['table'] ?? __( 'hub', 'drift-surface' );
		?>
		<div class="notice notice-info inline ds-synced-notice" style="margin:12px 0;">
			<p>
				<strong><?php esc_html_e( 'Managed in the Drift: Surface Hub.', 'drift-surface' ); ?></strong>
				<?php
				printf(
					/* translators: %s: hub table name. */
					esc_html__( 'This item comes from the "%s" table. Changes made here are replaced the next time the site publishes — edit it in the hub instead.', 'drift-surface' ),
					esc_html( $table )
				);
				?>
			</p>
		</div>
		<?php
	}
}
