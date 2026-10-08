<?php
/**
 * maps/surface.php — Surface (band & artist websites).
 *
 * The contract between the Drift: Surface Hub and the Surface theme. Table
 * and field names here must match the hub's schema exactly
 * (drift-hub/schemas/surface.php; the Connection tab's "Check connection"
 * compares them). Keep both in step.
 *
 * Post types (surface_*), taxonomies (surface_*) and the settings option
 * (surface_site_settings) are the Surface theme's contract: it queries them
 * directly, so rename them only together with the theme.
 *
 * Map reference: docs/MAP-REFERENCE.md.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$placeholder = Drift_Surface_Page_Creator::IMAGE_PLACEHOLDER;

return [
	'label'              => 'Surface',
	'description'        => 'Band and artist websites — gigs, releases, members, media, press and merch, managed in a Drift: Surface Hub.',
	'page_builder_field' => 'page_builder',

	/*
	 * The settings row's "Last Published" is stamped by the hub's Publish
	 * button; the daily check compares it to the last synced value.
	 */
	'publish'            => [ 'field' => 'Last Published' ],

	/*
	 * The theme Surface renders with. Until it (or a child of it) is active,
	 * Drift: Surface shows an install/activate notice and the Setup Wizard is off.
	 * The theme refuses to render without this plugin, so the two always
	 * ship together.
	 */
	'theme'              => [
		'slug' => 'surface-theme',
		'name' => 'Drift: Surface Theme',
		'zip'  => 'https://github.com/drift-creative-systems/surface-theme/releases/latest/download/surface-theme.zip',
	],

	/* ── One-row table → drift_surface_setting( key ) ───────────────────────── */
	'settings'           => [
		'table'  => 'Site Settings',
		'option' => 'surface_site_settings',
		'fields' => [
			'Artist Name'      => [ 'to' => 'name', 'type' => 'text', 'wp_option' => 'blogname' ],           // Also Settings → General → Site Title.
			'Tagline'          => [ 'to' => 'tagline', 'type' => 'text', 'wp_option' => 'blogdescription' ], // Also Settings → General → Tagline.
			'Genre'            => [ 'to' => 'genre', 'type' => 'text' ],
			'Hometown'         => [ 'to' => 'hometown', 'type' => 'text' ],
			'Short Bio'        => [ 'to' => 'bio_short', 'type' => 'text' ],
			'Full Bio'         => [ 'to' => 'bio', 'type' => 'html' ],
			'Logo'             => [ 'to' => 'logo', 'type' => 'image' ],
			'Logo (Light)'     => [ 'to' => 'logo_light', 'type' => 'image' ],
			'Hero Image'       => [ 'to' => 'hero_image', 'type' => 'image' ],
			'Hero Video URL'   => [ 'to' => 'hero_video', 'type' => 'url' ],
			'Primary Colour'   => [ 'to' => 'colour_primary', 'type' => 'text' ],
			'Secondary Colour' => [ 'to' => 'colour_secondary', 'type' => 'text' ],
			'Booking Email'    => [ 'to' => 'booking_email', 'type' => 'email' ],
			'Management Email' => [ 'to' => 'management_email', 'type' => 'email' ],
			'Press Email'      => [ 'to' => 'press_email', 'type' => 'email' ],
			'Mailing List URL' => [ 'to' => 'mailing_list_url', 'type' => 'url' ],
			'Press Kit PDF'    => [ 'to' => 'press_kit', 'type' => 'image' ], // Any allowed attachment; stored as an attachment ID.
			'Instagram'        => [ 'to' => 'instagram', 'type' => 'url' ],
			'Facebook'         => [ 'to' => 'facebook', 'type' => 'url' ],
			'TikTok'           => [ 'to' => 'tiktok', 'type' => 'url' ],
			'YouTube'          => [ 'to' => 'youtube', 'type' => 'url' ],
			'X'                => [ 'to' => 'x', 'type' => 'url' ],
			'Spotify'          => [ 'to' => 'spotify', 'type' => 'url' ],
			'Apple Music'      => [ 'to' => 'apple_music', 'type' => 'url' ],
			'Bandcamp'         => [ 'to' => 'bandcamp', 'type' => 'url' ],
			'SoundCloud'       => [ 'to' => 'soundcloud', 'type' => 'url' ],
			'SEO Description'  => [ 'to' => 'seo_description', 'type' => 'text' ],
			'Live Embed'       => [ 'to' => 'live_embed', 'type' => 'embed' ],  // Replaces the synced gig list when set.
			'Merch Embed'      => [ 'to' => 'merch_embed', 'type' => 'embed' ], // Replaces the synced merch grid when set.
		],
	],

	/* ── Tables → posts ─────────────────────────────────────────────── */
	'entities'           => [

		'gigs'     => [
			'table'        => 'Gigs',
			'post_type'    => 'surface_gig',
			'register'     => [ 'label' => 'Gigs', 'singular' => 'Gig', 'public' => true, 'rewrite' => 'live', 'menu_icon' => 'dashicons-tickets-alt', 'menu_position' => 20, 'supports' => [ 'title', 'editor' ] ],
			'title'        => 'Gig',          // Formula: {Venue} & ", " & {City}.
			'status_field' => 'Show on Site',
			'sort'         => [ [ 'field' => 'Date', 'direction' => 'asc' ] ],
			'fields'       => [
				'Date'       => [ 'to' => 'meta:gig_date', 'type' => 'date' ],
				'Doors'      => [ 'to' => 'meta:doors', 'type' => 'text' ],
				'Venue'      => [ 'to' => 'meta:venue', 'type' => 'text' ],
				'City'       => [ 'to' => 'meta:city', 'type' => 'text' ],
				'Country'    => [ 'to' => 'meta:country', 'type' => 'text' ],
				'Ticket URL' => [ 'to' => 'meta:ticket_url', 'type' => 'url' ],
				'Status'     => [ 'to' => 'meta:status', 'type' => 'text' ], // On Sale / Sold Out / Few Left / Free / Cancelled / Announced.
				'Support'    => [ 'to' => 'meta:support', 'type' => 'text' ],
				'Festival'   => [ 'to' => 'meta:is_festival', 'type' => 'bool' ],
				'Notes'      => [ 'to' => 'content', 'type' => 'html' ],
			],
		],

		'releases' => [
			'table'     => 'Releases',
			'post_type' => 'surface_release',
			'register'  => [ 'label' => 'Releases', 'singular' => 'Release', 'public' => true, 'rewrite' => 'music', 'menu_icon' => 'dashicons-album', 'menu_position' => 21, 'supports' => [ 'title', 'editor', 'thumbnail' ] ],
			'title'     => 'Title',
			'sort'      => [ [ 'field' => 'Release Date', 'direction' => 'desc' ] ],
			'fields'    => [
				'Type'             => [ 'to' => 'tax:surface_release_type', 'type' => 'list' ],
				'Release Date'     => [ 'to' => 'meta:release_date', 'type' => 'date' ],
				'Artwork'          => [ 'to' => 'thumbnail', 'type' => 'image' ],
				'Label'            => [ 'to' => 'meta:label', 'type' => 'text' ],
				'Description'      => [ 'to' => 'content', 'type' => 'html' ],
				'Spotify URL'      => [ 'to' => 'meta:spotify_url', 'type' => 'url' ],
				'Apple Music URL'  => [ 'to' => 'meta:apple_music_url', 'type' => 'url' ],
				'Bandcamp URL'     => [ 'to' => 'meta:bandcamp_url', 'type' => 'url' ],
				'YouTube URL'      => [ 'to' => 'meta:youtube_url', 'type' => 'url' ],
				'Pre-save URL'     => [ 'to' => 'meta:presave_url', 'type' => 'url' ],
				'Featured'         => [ 'to' => 'meta:featured', 'type' => 'bool' ],
				'Tracks'           => [ 'to' => 'meta:tracks', 'type' => 'link', 'entity' => 'tracks' ],
			],
		],

		'tracks'   => [
			'table'     => 'Tracks',
			'post_type' => 'surface_track',
			'register'  => [ 'label' => 'Tracks', 'singular' => 'Track', 'public' => false, 'menu_icon' => 'dashicons-format-audio', 'menu_position' => 22, 'supports' => [ 'title', 'editor' ] ],
			'title'     => 'Title',
			'sort'      => [ [ 'field' => 'Track Number', 'direction' => 'asc' ] ],
			'order'     => 'Track Number',
			'fields'    => [
				'Release'      => [ 'to' => 'meta:release', 'type' => 'link', 'entity' => 'releases' ],
				'Track Number' => [ 'to' => 'meta:track_number', 'type' => 'number' ],
				'Duration'     => [ 'to' => 'meta:duration', 'type' => 'text' ],
				'Writers'      => [ 'to' => 'meta:writers', 'type' => 'text' ],
				'Preview URL'  => [ 'to' => 'meta:preview_url', 'type' => 'url' ],
				'Lyrics'       => [ 'to' => 'content', 'type' => 'html' ],
			],
		],

		'members'  => [
			'table'        => 'Members',
			'post_type'    => 'surface_member',
			'register'     => [ 'label' => 'Members', 'singular' => 'Member', 'public' => false, 'menu_icon' => 'dashicons-groups', 'menu_position' => 23, 'supports' => [ 'title', 'editor', 'thumbnail', 'page-attributes' ] ],
			'title'        => 'Name',
			'status_field' => 'Show on Site',
			'sort'         => [ [ 'field' => 'Order', 'direction' => 'asc' ] ],
			'fields'       => [
				'Role'      => [ 'to' => 'meta:role', 'type' => 'text' ],
				'Bio'       => [ 'to' => 'content', 'type' => 'html' ],
				'Photo'     => [ 'to' => 'thumbnail', 'type' => 'image' ],
				'Instagram' => [ 'to' => 'meta:instagram', 'type' => 'url' ],
			],
		],

		'news'     => [
			'table'        => 'News',
			'post_type'    => 'post',
			'title'        => 'Headline',
			'status_field' => 'Published',
			'sort'         => [ [ 'field' => 'Date', 'direction' => 'desc' ] ],
			'fields'       => [
				'Date'    => [ 'to' => 'post_date', 'type' => 'datetime' ],
				'Summary' => [ 'to' => 'excerpt', 'type' => 'text' ],
				'Body'    => [ 'to' => 'content', 'type' => 'html' ],
				'Image'   => [ 'to' => 'thumbnail', 'type' => 'image' ],
			],
		],

		'photos'   => [
			'table'        => 'Gallery',
			'post_type'    => 'surface_photo',
			'register'     => [ 'label' => 'Gallery', 'singular' => 'Photo', 'public' => false, 'menu_icon' => 'dashicons-format-gallery', 'menu_position' => 24, 'supports' => [ 'title', 'excerpt', 'thumbnail', 'page-attributes' ] ],
			'title'        => 'Title',        // Formula: IF({Caption}, {Caption}, "Photo").
			'status_field' => 'Show on Site',
			'sort'         => [ [ 'field' => 'Order', 'direction' => 'asc' ] ],
			'fields'       => [
				'Photo'   => [ 'to' => 'thumbnail', 'type' => 'image' ],
				'Caption' => [ 'to' => 'excerpt', 'type' => 'text' ],
				'Album'   => [ 'to' => 'tax:surface_album', 'type' => 'list' ],
				'Credit'  => [ 'to' => 'meta:credit', 'type' => 'text' ],
			],
		],

		'videos'   => [
			'table'        => 'Videos',
			'post_type'    => 'surface_video',
			'register'     => [ 'label' => 'Videos', 'singular' => 'Video', 'public' => false, 'menu_icon' => 'dashicons-video-alt3', 'menu_position' => 25, 'supports' => [ 'title', 'thumbnail', 'page-attributes' ] ],
			'title'        => 'Title',
			'status_field' => 'Show on Site',
			'sort'         => [ [ 'field' => 'Date', 'direction' => 'desc' ] ],
			'fields'       => [
				'Video URL' => [ 'to' => 'meta:video_url', 'type' => 'url' ],
				'Thumbnail' => [ 'to' => 'thumbnail', 'type' => 'image' ],
				'Date'      => [ 'to' => 'meta:video_date', 'type' => 'date' ],
				'Featured'  => [ 'to' => 'meta:featured', 'type' => 'bool' ],
			],
		],

		'press'    => [
			'table'        => 'Press',
			'post_type'    => 'surface_press',
			'register'     => [ 'label' => 'Press', 'singular' => 'Press quote', 'public' => false, 'menu_icon' => 'dashicons-format-quote', 'menu_position' => 26, 'supports' => [ 'title', 'editor', 'thumbnail', 'page-attributes' ] ],
			'title'        => 'Publication',
			'status_field' => 'Show on Site',
			'sort'         => [ [ 'field' => 'Order', 'direction' => 'asc' ] ],
			'fields'       => [
				'Quote'  => [ 'to' => 'content', 'type' => 'html' ],
				'Author' => [ 'to' => 'meta:author', 'type' => 'text' ],
				'Link'   => [ 'to' => 'meta:link', 'type' => 'url' ],
				'Logo'   => [ 'to' => 'thumbnail', 'type' => 'image' ],
				'Rating' => [ 'to' => 'meta:rating', 'type' => 'number' ],
				'Date'   => [ 'to' => 'meta:press_date', 'type' => 'date' ],
			],
		],

		'merch'    => [
			'table'        => 'Merch',
			'post_type'    => 'surface_merch',
			'register'     => [ 'label' => 'Merch', 'singular' => 'Merch item', 'public' => false, 'menu_icon' => 'dashicons-cart', 'menu_position' => 27, 'supports' => [ 'title', 'thumbnail', 'page-attributes' ] ],
			'title'        => 'Item',
			'status_field' => 'Show on Site',
			'sort'         => [ [ 'field' => 'Order', 'direction' => 'asc' ] ],
			'fields'       => [
				'Image'     => [ 'to' => 'thumbnail', 'type' => 'image' ],
				'Price'     => [ 'to' => 'meta:price', 'type' => 'number' ],
				'Store URL' => [ 'to' => 'meta:store_url', 'type' => 'url' ],
				'Badge'     => [ 'to' => 'meta:badge', 'type' => 'text' ],
			],
		],
	],

	'taxonomies'         => [
		'surface_release_type' => [ 'label' => 'Release types', 'singular' => 'Release type', 'object_types' => [ 'surface_release' ] ],
		'surface_album'        => [ 'label' => 'Albums', 'singular' => 'Album', 'object_types' => [ 'surface_photo' ] ],
	],

	/* ── Website → hub ──────────────────────────────────────────────── */
	'forms'              => [
		'enquiry'    => [
			'table'    => 'Enquiries',
			'fields'   => [
				'name'         => 'Name',
				'email'        => 'Email',
				'phone'        => 'Phone',
				'enquiry_type' => 'Enquiry Type',
				'event_date'   => 'Event Date',
				'location'     => 'Location',
				'message'      => 'Message',
				'source_page'  => 'Page', // Not 'page': that's a WordPress admin query var, and logged-in AJAX runs admin_init.
			],
			'required' => [ 'name', 'email', 'message' ],
			'email'    => [ 'email' ],
			'notify'   => 'booking_email',
			'subject'  => 'New enquiry from the website',
		],
		'newsletter' => [
			'table'    => 'Subscribers',
			'fields'   => [
				'name'  => 'Name',
				'email' => 'Email',
			],
			'required' => [ 'email' ],
			'email'    => [ 'email' ],
			'notify'   => false, // Only emailed if the hub is unreachable.
			'subject'  => 'New mailing list signup',
		],
	],

	/* ── Setup Wizard pages (layouts provided by the Surface theme) ─── */
	'pages'              => [
		[
			'id'          => 'home',
			'title'       => 'Home',
			'slug'        => '',
			'description' => 'Hero, latest release, upcoming gigs, featured video, press quotes and mailing list. Set as the front page.',
			'required'    => true,
			'in_menu'     => false,
			'rows'        => [
				[ 'acf_fc_layout' => 'hero_module' ],
				[ 'acf_fc_layout' => 'latest_release_module', 'section_title' => 'Out now' ],
				[ 'acf_fc_layout' => 'gigs_module', 'section_title' => 'Live', 'limit' => 5, 'show_past' => 0 ],
				[ 'acf_fc_layout' => 'video_module', 'section_title' => 'Watch', 'featured_only' => 1 ],
				[ 'acf_fc_layout' => 'press_module', 'section_title' => 'What they\'re saying' ],
				[ 'acf_fc_layout' => 'newsletter_module', 'section_title' => 'Stay in the loop' ],
			],
		],
		[
			'id'          => 'live',
			'title'       => 'Live',
			'slug'        => 'live',
			'description' => 'Every upcoming gig, with past shows underneath.',
			'tags'        => [ 'Gigs' ],
			'required'    => true,
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'Live', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'gigs_module', 'section_title' => 'Upcoming', 'limit' => 0, 'show_past' => 1 ],
			],
		],
		[
			'id'          => 'music',
			'title'       => 'Music',
			'slug'        => 'music',
			'description' => 'Discography — every release with artwork, streaming links and tracklists.',
			'tags'        => [ 'Releases' ],
			'required'    => true,
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'Music', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'releases_module', 'section_title' => '', 'display' => 'grid' ],
				[ 'acf_fc_layout' => 'streaming_links_module', 'section_title' => 'Listen everywhere' ],
			],
		],
		[
			'id'          => 'about',
			'title'       => 'About',
			'slug'        => 'about',
			'description' => 'Full bio, band members and press quotes.',
			'tags'        => [ 'Members', 'Press' ],
			'required'    => true,
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'About', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'bio_module', 'section_title' => '' ],
				[ 'acf_fc_layout' => 'members_module', 'section_title' => 'The band' ],
				[ 'acf_fc_layout' => 'press_module', 'section_title' => 'Press' ],
			],
		],
		[
			'id'          => 'videos',
			'title'       => 'Videos',
			'slug'        => 'videos',
			'description' => 'Music videos and live clips.',
			'tags'        => [ 'Videos' ],
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'Videos', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'video_module', 'section_title' => '', 'featured_only' => 0 ],
			],
		],
		[
			'id'          => 'gallery',
			'title'       => 'Gallery',
			'slug'        => 'gallery',
			'description' => 'Photo gallery, filterable by album.',
			'tags'        => [ 'Gallery' ],
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'Gallery', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'gallery_module', 'section_title' => '', 'show_filters' => 1 ],
			],
		],
		[
			'id'          => 'news',
			'title'       => 'News',
			'slug'        => 'news',
			'description' => 'Latest news posts.',
			'tags'        => [ 'News' ],
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'News', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'news_module', 'section_title' => '', 'limit' => 12 ],
			],
		],
		[
			'id'          => 'merch',
			'title'       => 'Merch',
			'slug'        => 'merch',
			'description' => 'Merch grid linking out to the band\'s store.',
			'tags'        => [ 'Merch' ],
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'Merch', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'merch_module', 'section_title' => '' ],
			],
		],
		[
			'id'          => 'press-kit',
			'title'       => 'Press Kit',
			'slug'        => 'press-kit',
			'description' => 'EPK: short bio, press photos, quotes, downloadable press kit and booking contact.',
			'tags'        => [ 'EPK' ],
			'in_menu'     => false,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'Press Kit', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'bio_module', 'section_title' => 'Biography', 'show_download' => 1 ],
				[ 'acf_fc_layout' => 'gallery_module', 'section_title' => 'Press photos', 'album' => 'Press' ],
				[ 'acf_fc_layout' => 'press_module', 'section_title' => 'Quotes' ],
				[ 'acf_fc_layout' => 'contact_module', 'section_title' => 'Booking & press', 'form' => 'enquiry' ],
			],
		],
		[
			'id'          => 'contact',
			'title'       => 'Contact',
			'slug'        => 'contact',
			'description' => 'Booking enquiry form (saved to the hub\'s "Enquiries") and contact emails.',
			'tags'        => [ 'Form' ],
			'required'    => true,
			'in_menu'     => true,
			'rows'        => [
				[ 'acf_fc_layout' => 'page_header_module', 'section_title' => 'Contact', 'background_image' => $placeholder ],
				[ 'acf_fc_layout' => 'contact_module', 'section_title' => 'Get in touch', 'form' => 'enquiry' ],
			],
		],
	],
];
