<?php
/**
 * class-map.php — loads the product map: the one file that says which
 * Airtable tables and fields become which WordPress content for a Drift
 * product (maps/encore.php, later maps/cardiotrack.php …).
 *
 * The sync engine, content types, forms and setup wizard are all generic and
 * read everything product-specific from here. A new product is a new map
 * file and a theme — no new PHP classes. See docs/MAP-REFERENCE.md.
 *
 * Extra maps can be registered from a theme or mu-plugin:
 *   add_filter( 'drift_website_maps', fn( $maps ) => $maps + [ 'myproduct' => __DIR__ . '/map.php' ] );
 * and the loaded map adjusted with the `drift_website_map` filter.
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Website_Map {

	/** Field types the engine understands. */
	const TYPES = [ 'text', 'html', 'date', 'datetime', 'number', 'bool', 'url', 'email', 'image', 'gallery', 'link', 'list', 'json' ];

	/** @var array|null */
	private static $current = null;

	/**
	 * Every available map, slug => file path.
	 *
	 * @return array<string, string>
	 */
	public static function available(): array {
		$maps = [];
		foreach ( (array) glob( DRIFT_WEBSITE_DIR . 'maps/*.php' ) as $file ) {
			$maps[ sanitize_key( basename( (string) $file, '.php' ) ) ] = (string) $file;
		}
		return (array) apply_filters( 'drift_website_maps', $maps );
	}

	/** Human label for a map slug, without loading the whole thing twice. */
	public static function label( string $slug ): string {
		$file = self::available()[ $slug ] ?? '';
		if ( ! $file || ! is_readable( $file ) ) {
			return $slug;
		}
		$map = include $file;
		return is_array( $map ) && ! empty( $map['label'] ) ? (string) $map['label'] : $slug;
	}

	/** The active product map, normalised. Empty-but-valid shape if none. */
	public static function current(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}

		$slug = (string) Drift_Website_Settings::get( 'product', 'encore' );
		$file = self::available()[ $slug ] ?? '';
		$map  = ( $file && is_readable( $file ) ) ? include $file : [];
		$map  = (array) apply_filters( 'drift_website_map', is_array( $map ) ? $map : [], $slug );

		self::$current = self::normalise( $map, $slug );
		return self::$current;
	}

	/** Forget the cached map (after the product setting changes). */
	public static function flush(): void {
		self::$current = null;
	}

	private static function normalise( array $map, string $slug ): array {
		$map = array_merge(
			[
				'slug'               => $slug,
				'label'              => $slug,
				'description'        => '',
				'settings'           => null,
				'entities'           => [],
				'taxonomies'         => [],
				'forms'              => [],
				'pages'              => [],
				'page_builder_field' => 'page_builder',
				'publish'            => [],
			],
			$map
		);

		if ( is_array( $map['settings'] ) ) {
			$map['settings'] = array_merge( [ 'table' => '', 'option' => 'drift_site_settings', 'fields' => [] ], $map['settings'] );
			$map['settings']['fields'] = self::normalise_fields( (array) $map['settings']['fields'] );
		}

		$entities = [];
		foreach ( (array) $map['entities'] as $key => $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['table'] ) || empty( $entity['post_type'] ) ) {
				continue;
			}
			$entity = array_merge(
				[
					'key'          => sanitize_key( (string) $key ),
					'table'        => '',
					'post_type'    => '',
					'register'     => false,
					'view'         => '',
					'filter'       => '',
					'sort'         => [],
					'title'        => 'Name',
					'slug'         => '',
					'order'        => 'row',
					'status_field' => '',
					'on_remove'    => 'trash',
					'fields'       => [],
				],
				$entity
			);
			$entity['fields']             = self::normalise_fields( (array) $entity['fields'] );
			$entities[ $entity['key'] ]   = $entity;
		}
		$map['entities'] = $entities;

		return $map;
	}

	/**
	 * 'Field' => 'content' shorthand becomes [ 'to' => 'content', 'type' => … ].
	 */
	private static function normalise_fields( array $fields ): array {
		$out = [];
		foreach ( $fields as $name => $spec ) {
			if ( is_string( $spec ) ) {
				$spec = [ 'to' => $spec ];
			}
			if ( ! is_array( $spec ) || empty( $spec['to'] ) ) {
				continue;
			}
			if ( empty( $spec['type'] ) ) {
				$spec['type'] = self::default_type( (string) $spec['to'] );
			}
			if ( ! in_array( $spec['type'], self::TYPES, true ) ) {
				$spec['type'] = 'text';
			}
			$out[ (string) $name ] = $spec;
		}
		return $out;
	}

	private static function default_type( string $to ): string {
		switch ( $to ) {
			case 'content':
				return 'html';
			case 'thumbnail':
				return 'image';
			case 'post_date':
				return 'datetime';
			default:
				return 0 === strpos( $to, 'tax:' ) ? 'list' : 'text';
		}
	}

	/* ── Lookups ─────────────────────────────────────────────────────── */

	public static function entity( string $key ): ?array {
		return self::current()['entities'][ $key ] ?? null;
	}

	/** Entity whose post type this is, if Drift syncs it. */
	public static function entity_for_post_type( string $post_type ): ?array {
		foreach ( self::current()['entities'] as $entity ) {
			if ( $entity['post_type'] === $post_type ) {
				return $entity;
			}
		}
		return null;
	}

	/**
	 * The Airtable fields an entity needs, so list requests ask for exactly
	 * those (smaller payloads; hidden/private columns never leave Airtable).
	 *
	 * @return string[]
	 */
	public static function requested_fields( array $entity ): array {
		$fields = array_keys( $entity['fields'] );
		foreach ( [ 'title', 'slug', 'status_field' ] as $key ) {
			if ( ! empty( $entity[ $key ] ) ) {
				$fields[] = (string) $entity[ $key ];
			}
		}
		if ( ! empty( $entity['order'] ) && 'row' !== $entity['order'] ) {
			$fields[] = (string) $entity['order'];
		}
		return array_values( array_unique( $fields ) );
	}

	/**
	 * Everything the map expects in Airtable: [ table => [ field, … ] ].
	 * Used by the Connection tab's schema check.
	 */
	public static function expected_schema(): array {
		$map = self::current();
		$out = [];

		if ( $map['settings'] && $map['settings']['table'] ) {
			$fields = array_keys( $map['settings']['fields'] );
			if ( ! empty( $map['publish']['field'] ) ) {
				$fields[] = (string) $map['publish']['field'];
			}
			$out[ $map['settings']['table'] ] = $fields;
		}

		foreach ( $map['entities'] as $entity ) {
			$out[ $entity['table'] ] = array_merge( $out[ $entity['table'] ] ?? [], self::requested_fields( $entity ) );
		}

		foreach ( $map['forms'] as $form ) {
			if ( ! empty( $form['table'] ) ) {
				$out[ $form['table'] ] = array_merge( $out[ $form['table'] ] ?? [], array_values( (array) ( $form['fields'] ?? [] ) ) );
			}
		}

		return array_map( static fn( $f ) => array_values( array_unique( $f ) ), $out );
	}
}
