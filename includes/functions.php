<?php
/**
 * functions.php — the small public API product themes use.
 *
 * Themes read synced data through these (or plain WordPress functions:
 * get_post_meta(), get_the_post_thumbnail()…). They never call the hub.
 * Every function is guarded with function_exists() so a theme can ship
 * fallbacks for when the plugin is inactive.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'drift_surface_settings' ) ) {
	/**
	 * Every synced site setting (the map's settings table).
	 *
	 * @return array
	 */
	function drift_surface_settings(): array {
		static $cache = null;
		if ( null === $cache ) {
			$map    = Drift_Surface_Map::current();
			$option = $map['settings']['option'] ?? 'surface_site_settings';
			$cache  = get_option( $option, [] );
			$cache  = is_array( $cache ) ? $cache : [];
		}
		return $cache;
	}
}

if ( ! function_exists( 'drift_surface_setting' ) ) {
	/**
	 * One synced site setting, e.g. drift_surface_setting( 'band_name' ).
	 * Image settings return an attachment ID.
	 *
	 * @param string $key     Key from the map ('to').
	 * @param mixed  $default Fallback when empty.
	 * @return mixed
	 */
	function drift_surface_setting( string $key, $default = '' ) {
		$value = drift_surface_settings()[ $key ] ?? null;
		return ( null === $value || '' === $value || [] === $value ) ? $default : $value;
	}
}

if ( ! function_exists( 'drift_surface_setting_image' ) ) {
	/**
	 * <img> for an image setting, e.g. drift_surface_setting_image( 'logo', 'medium' ).
	 *
	 * @param string       $key  Setting key.
	 * @param string|int[] $size Image size.
	 * @param array        $attr Extra attributes.
	 */
	function drift_surface_setting_image( string $key, $size = 'full', array $attr = [] ): string {
		$id = (int) drift_surface_setting( $key, 0 );
		return $id ? (string) wp_get_attachment_image( $id, $size, false, $attr ) : '';
	}
}

if ( ! function_exists( 'drift_surface_linked_posts' ) ) {
	/**
	 * Posts linked from a 'link' field, in the hub's order.
	 * e.g. drift_surface_linked_posts( get_the_ID(), 'tracks' ).
	 *
	 * @return WP_Post[]
	 */
	function drift_surface_linked_posts( int $post_id, string $key ): array {
		$ids = array_filter( array_map( 'intval', (array) get_post_meta( $post_id, $key, true ) ) );
		if ( ! $ids ) {
			return [];
		}
		// Not 'any': WordPress's 'any' skips non-public types (e.g. surface_track).
		$types = array_values( array_unique( array_merge(
			wp_list_pluck( Drift_Surface_Map::current()['entities'], 'post_type' ),
			[ 'post', 'page' ]
		) ) );
		return get_posts( [
			'post_type'      => $types,
			'post__in'       => $ids,
			'orderby'        => 'post__in',
			'posts_per_page' => count( $ids ),
			'no_found_rows'  => true,
		] );
	}
}

if ( ! function_exists( 'drift_surface_is_synced' ) ) {
	/** Whether a post is owned by the hub sync. */
	function drift_surface_is_synced( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, Drift_Surface_Sync_Engine::META_ID, true );
	}
}

if ( ! function_exists( 'drift_surface_last_synced' ) ) {
	/** Timestamp of the last sync run, 0 if never. */
	function drift_surface_last_synced(): int {
		return (int) ( Drift_Surface_Sync_Engine::status()['last_run'] ?? 0 );
	}
}

if ( ! function_exists( 'drift_surface_form_hidden_fields' ) ) {
	/**
	 * The hidden inputs a Drift: Surface form needs (action, form key, nonce,
	 * honeypot). Put inside the theme's <form>, which posts to
	 * admin_url( 'admin-ajax.php' ).
	 *
	 * @param string $form Form key from the map, e.g. 'enquiry'.
	 */
	function drift_surface_form_hidden_fields( string $form ): void {
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( Drift_Surface_Forms::ACTION ) );
		printf( '<input type="hidden" name="form" value="%s">', esc_attr( $form ) );
		printf( '<input type="hidden" name="nonce" value="%s">', esc_attr( wp_create_nonce( Drift_Surface_Forms::ACTION ) ) );
		printf(
			'<div aria-hidden="true" style="position:absolute;left:-9999px;"><label>%1$s <input type="text" name="%2$s" tabindex="-1" autocomplete="off"></label></div>',
			esc_html__( 'Leave this empty', 'drift-surface' ),
			esc_attr( Drift_Surface_Forms::HONEYPOT )
		);
	}
}
