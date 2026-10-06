<?php
/**
 * functions.php — the small public API product themes use.
 *
 * Themes read synced data through these (or plain WordPress functions:
 * get_post_meta(), get_the_post_thumbnail()…). They never call Airtable.
 * Every function is guarded with function_exists() so a theme can ship
 * fallbacks for when the plugin is inactive.
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'encore_website_settings' ) ) {
	/**
	 * Every synced site setting (the map's settings table).
	 *
	 * @return array
	 */
	function encore_website_settings(): array {
		static $cache = null;
		if ( null === $cache ) {
			$map    = Encore_Website_Map::current();
			$option = $map['settings']['option'] ?? 'encore_site_settings';
			$cache  = get_option( $option, [] );
			$cache  = is_array( $cache ) ? $cache : [];
		}
		return $cache;
	}
}

if ( ! function_exists( 'encore_website_setting' ) ) {
	/**
	 * One synced site setting, e.g. encore_website_setting( 'band_name' ).
	 * Image settings return an attachment ID.
	 *
	 * @param string $key     Key from the map ('to').
	 * @param mixed  $default Fallback when empty.
	 * @return mixed
	 */
	function encore_website_setting( string $key, $default = '' ) {
		$value = encore_website_settings()[ $key ] ?? null;
		return ( null === $value || '' === $value || [] === $value ) ? $default : $value;
	}
}

if ( ! function_exists( 'encore_website_setting_image' ) ) {
	/**
	 * <img> for an image setting, e.g. encore_website_setting_image( 'logo', 'medium' ).
	 *
	 * @param string       $key  Setting key.
	 * @param string|int[] $size Image size.
	 * @param array        $attr Extra attributes.
	 */
	function encore_website_setting_image( string $key, $size = 'full', array $attr = [] ): string {
		$id = (int) encore_website_setting( $key, 0 );
		return $id ? (string) wp_get_attachment_image( $id, $size, false, $attr ) : '';
	}
}

if ( ! function_exists( 'encore_website_linked_posts' ) ) {
	/**
	 * Posts linked from a 'link' field, in Airtable's order.
	 * e.g. encore_website_linked_posts( get_the_ID(), 'tracks' ).
	 *
	 * @return WP_Post[]
	 */
	function encore_website_linked_posts( int $post_id, string $key ): array {
		$ids = array_filter( array_map( 'intval', (array) get_post_meta( $post_id, $key, true ) ) );
		if ( ! $ids ) {
			return [];
		}
		// Not 'any': WordPress's 'any' skips non-public types (e.g. encore_track).
		$types = array_values( array_unique( array_merge(
			wp_list_pluck( Encore_Website_Map::current()['entities'], 'post_type' ),
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

if ( ! function_exists( 'encore_website_is_synced' ) ) {
	/** Whether a post is owned by the Airtable sync. */
	function encore_website_is_synced( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, Encore_Website_Sync_Engine::META_ID, true );
	}
}

if ( ! function_exists( 'encore_website_last_synced' ) ) {
	/** Timestamp of the last sync run, 0 if never. */
	function encore_website_last_synced(): int {
		return (int) ( Encore_Website_Sync_Engine::status()['last_run'] ?? 0 );
	}
}

if ( ! function_exists( 'encore_website_form_hidden_fields' ) ) {
	/**
	 * The hidden inputs an Encore Website form needs (action, form key, nonce,
	 * honeypot). Put inside the theme's <form>, which posts to
	 * admin_url( 'admin-ajax.php' ).
	 *
	 * @param string $form Form key from the map, e.g. 'enquiry'.
	 */
	function encore_website_form_hidden_fields( string $form ): void {
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( Encore_Website_Forms::ACTION ) );
		printf( '<input type="hidden" name="form" value="%s">', esc_attr( $form ) );
		printf( '<input type="hidden" name="nonce" value="%s">', esc_attr( wp_create_nonce( Encore_Website_Forms::ACTION ) ) );
		printf(
			'<div aria-hidden="true" style="position:absolute;left:-9999px;"><label>%1$s <input type="text" name="%2$s" tabindex="-1" autocomplete="off"></label></div>',
			esc_html__( 'Leave this empty', 'encore-website' ),
			esc_attr( Encore_Website_Forms::HONEYPOT )
		);
	}
}
