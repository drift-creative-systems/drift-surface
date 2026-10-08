<?php
/**
 * compat.php — 2.x (Encore Website) names, kept so the Encore theme and any
 * child theme keep working after the 3.0 rename to Drift: Surface.
 *
 * - encore_website_* theme functions → their drift_surface_* replacements.
 * - Encore_Website_* class names → aliases of the Drift_Surface_* classes.
 * - encore_website_* filters and actions → still fire (with a deprecation
 *   notice under WP_DEBUG) when something is hooked to them.
 *
 * Stored data is renamed once by class-migrate.php, and ENCORE_WEBSITE_*
 * constants are still read by drift_surface_constant(). The 1.x (Drift
 * Website) names were removed in 3.0. Remove this file in 4.0.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Classes ─────────────────────────────────────────────────────────── */

foreach ( [
	'Crypto', 'Settings', 'Log', 'Airtable', 'Media', 'Map', 'Content_Types', 'Sync_Engine', 'Publish',
	'Forms', 'Admin_Access', 'White_Label', 'Page_Creator', 'Theme_Check', 'Admin_Page', 'Migrate', 'Plugin',
] as $drift_surface_class ) {
	if ( class_exists( 'Drift_Surface_' . $drift_surface_class ) && ! class_exists( 'Encore_Website_' . $drift_surface_class, false ) ) {
		class_alias( 'Drift_Surface_' . $drift_surface_class, 'Encore_Website_' . $drift_surface_class );
	}
}
unset( $drift_surface_class );

/* ── Theme functions ─────────────────────────────────────────────────── */

if ( ! function_exists( 'encore_website_constant' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_constant(). */
	function encore_website_constant( string $name ): string {
		return drift_surface_constant( $name );
	}
}

if ( ! function_exists( 'encore_website_settings' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_settings(). */
	function encore_website_settings(): array {
		return drift_surface_settings();
	}
}

if ( ! function_exists( 'encore_website_setting' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_setting(). */
	function encore_website_setting( string $key, $default = '' ) {
		return drift_surface_setting( $key, $default );
	}
}

if ( ! function_exists( 'encore_website_setting_image' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_setting_image(). */
	function encore_website_setting_image( string $key, $size = 'full', array $attr = [] ): string {
		return drift_surface_setting_image( $key, $size, $attr );
	}
}

if ( ! function_exists( 'encore_website_linked_posts' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_linked_posts(). */
	function encore_website_linked_posts( int $post_id, string $key ): array {
		return drift_surface_linked_posts( $post_id, $key );
	}
}

if ( ! function_exists( 'encore_website_is_synced' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_is_synced(). */
	function encore_website_is_synced( int $post_id ): bool {
		return drift_surface_is_synced( $post_id );
	}
}

if ( ! function_exists( 'encore_website_last_synced' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_last_synced(). */
	function encore_website_last_synced(): int {
		return drift_surface_last_synced();
	}
}

if ( ! function_exists( 'encore_website_form_hidden_fields' ) ) {
	/** @deprecated 3.0.0 Use drift_surface_form_hidden_fields(). */
	function encore_website_form_hidden_fields( string $form ): void {
		drift_surface_form_hidden_fields( $form );
	}
}

/* ── Hooks ───────────────────────────────────────────────────────────── */

/**
 * Old filter name => [ new filter name, number of arguments ].
 */
const DRIFT_SURFACE_LEGACY_FILTERS = [
	'encore_website_maps'           => [ 'drift_surface_maps', 1 ],
	'encore_website_map'            => [ 'drift_surface_map', 2 ],
	'encore_website_admin_tabs'     => [ 'drift_surface_admin_tabs', 1 ],
	'encore_website_post_type_args' => [ 'drift_surface_post_type_args', 2 ],
	'encore_website_taxonomy_args'  => [ 'drift_surface_taxonomy_args', 3 ],
	'encore_website_form_values'    => [ 'drift_surface_form_values', 3 ],
];

/**
 * Old action name => [ new action name, number of arguments ].
 */
const DRIFT_SURFACE_LEGACY_ACTIONS = [
	'encore_website_loaded'         => [ 'drift_surface_loaded', 0 ],
	'encore_website_synced'         => [ 'drift_surface_synced', 1 ],
	'encore_website_form_submitted' => [ 'drift_surface_form_submitted', 3 ],
];

foreach ( DRIFT_SURFACE_LEGACY_FILTERS as $drift_surface_old => [ $drift_surface_new, $drift_surface_args ] ) {
	add_filter(
		$drift_surface_new,
		static function ( ...$args ) use ( $drift_surface_old, $drift_surface_new ) {
			return has_filter( $drift_surface_old ) ? apply_filters_deprecated( $drift_surface_old, $args, '3.0.0', $drift_surface_new ) : $args[0];
		},
		1,
		$drift_surface_args
	);
}

foreach ( DRIFT_SURFACE_LEGACY_ACTIONS as $drift_surface_old => [ $drift_surface_new, $drift_surface_args ] ) {
	add_action(
		$drift_surface_new,
		static function ( ...$args ) use ( $drift_surface_old, $drift_surface_new ) {
			if ( has_action( $drift_surface_old ) ) {
				do_action_deprecated( $drift_surface_old, $args, '3.0.0', $drift_surface_new );
			}
		},
		1,
		$drift_surface_args
	);
}
unset( $drift_surface_old, $drift_surface_new, $drift_surface_args );
