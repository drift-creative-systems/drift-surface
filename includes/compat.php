<?php
/**
 * compat.php — 1.x (Drift Website) names, kept so nothing breaks mid-rename.
 *
 * - drift_* theme functions → their encore_website_* replacements. Encore
 *   theme 1.2.x and earlier, and any child theme, call these.
 * - Drift_Website_* class names → aliases of the Encore_Website_* classes.
 * - drift_website_* filters and actions → still fire (with a deprecation
 *   notice under WP_DEBUG) when something is hooked to them.
 *
 * Stored data is renamed once by class-migrate.php; constants, the REST
 * namespace, the publish link and the form action have their own fallbacks
 * where they're read. Remove this file in 3.0.
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Classes ─────────────────────────────────────────────────────────── */

foreach ( [
	'Crypto', 'Settings', 'Log', 'Airtable', 'Media', 'Map', 'Content_Types', 'Sync_Engine', 'Publish',
	'Forms', 'Admin_Access', 'White_Label', 'Page_Creator', 'Theme_Check', 'Admin_Page', 'Plugin',
] as $encore_website_class ) {
	if ( class_exists( 'Encore_Website_' . $encore_website_class ) && ! class_exists( 'Drift_Website_' . $encore_website_class, false ) ) {
		class_alias( 'Encore_Website_' . $encore_website_class, 'Drift_Website_' . $encore_website_class );
	}
}
unset( $encore_website_class );

/* ── Theme functions ─────────────────────────────────────────────────── */

if ( ! function_exists( 'drift_settings' ) ) {
	/** @deprecated 2.0.0 Use encore_website_settings(). */
	function drift_settings(): array {
		return encore_website_settings();
	}
}

if ( ! function_exists( 'drift_setting' ) ) {
	/** @deprecated 2.0.0 Use encore_website_setting(). */
	function drift_setting( string $key, $default = '' ) {
		return encore_website_setting( $key, $default );
	}
}

if ( ! function_exists( 'drift_setting_image' ) ) {
	/** @deprecated 2.0.0 Use encore_website_setting_image(). */
	function drift_setting_image( string $key, $size = 'full', array $attr = [] ): string {
		return encore_website_setting_image( $key, $size, $attr );
	}
}

if ( ! function_exists( 'drift_linked_posts' ) ) {
	/** @deprecated 2.0.0 Use encore_website_linked_posts(). */
	function drift_linked_posts( int $post_id, string $key ): array {
		return encore_website_linked_posts( $post_id, $key );
	}
}

if ( ! function_exists( 'drift_is_synced' ) ) {
	/** @deprecated 2.0.0 Use encore_website_is_synced(). */
	function drift_is_synced( int $post_id ): bool {
		return encore_website_is_synced( $post_id );
	}
}

if ( ! function_exists( 'drift_last_synced' ) ) {
	/** @deprecated 2.0.0 Use encore_website_last_synced(). */
	function drift_last_synced(): int {
		return encore_website_last_synced();
	}
}

if ( ! function_exists( 'drift_form_hidden_fields' ) ) {
	/** @deprecated 2.0.0 Use encore_website_form_hidden_fields(). */
	function drift_form_hidden_fields( string $form ): void {
		encore_website_form_hidden_fields( $form );
	}
}

/* ── Hooks ───────────────────────────────────────────────────────────── */

/**
 * Old filter name => [ new filter name, number of arguments ].
 */
const ENCORE_WEBSITE_LEGACY_FILTERS = [
	'drift_website_maps'            => [ 'encore_website_maps', 1 ],
	'drift_website_map'             => [ 'encore_website_map', 2 ],
	'drift_website_admin_tabs'      => [ 'encore_website_admin_tabs', 1 ],
	'drift_website_post_type_args'  => [ 'encore_website_post_type_args', 2 ],
	'drift_website_taxonomy_args'   => [ 'encore_website_taxonomy_args', 3 ],
	'drift_website_form_values'     => [ 'encore_website_form_values', 3 ],
];

/**
 * Old action name => [ new action name, number of arguments ].
 */
const ENCORE_WEBSITE_LEGACY_ACTIONS = [
	'drift_website_loaded'          => [ 'encore_website_loaded', 0 ],
	'drift_website_synced'          => [ 'encore_website_synced', 1 ],
	'drift_website_form_submitted'  => [ 'encore_website_form_submitted', 3 ],
];

foreach ( ENCORE_WEBSITE_LEGACY_FILTERS as $encore_website_old => [ $encore_website_new, $encore_website_args ] ) {
	add_filter(
		$encore_website_new,
		static function ( ...$args ) use ( $encore_website_old, $encore_website_new ) {
			return has_filter( $encore_website_old ) ? apply_filters_deprecated( $encore_website_old, $args, '2.0.0', $encore_website_new ) : $args[0];
		},
		1,
		$encore_website_args
	);
}

foreach ( ENCORE_WEBSITE_LEGACY_ACTIONS as $encore_website_old => [ $encore_website_new, $encore_website_args ] ) {
	add_action(
		$encore_website_new,
		static function ( ...$args ) use ( $encore_website_old, $encore_website_new ) {
			if ( has_action( $encore_website_old ) ) {
				do_action_deprecated( $encore_website_old, $args, '2.0.0', $encore_website_new );
			}
		},
		1,
		$encore_website_args
	);
}
unset( $encore_website_old, $encore_website_new, $encore_website_args );
