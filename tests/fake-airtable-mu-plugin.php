<?php
/* Test-only mu-plugin: fakes api.airtable.com and attachment downloads so the sync can be exercised locally. Copy to wp-content/mu-plugins/. update_option( "fa_state", "v2" ) switches to a second data set (gig removed, status changed, hidden gig shown). NEVER deploy. */
function fa_att( $id, $name ) { return [ 'id' => $id, 'url' => 'https://dl.airtable.test/' . $id . '/' . $name . '?sig=' . wp_generate_password( 8, false ), 'filename' => $name, 'type' => 'image/png' ]; }
function fa_data() {
	$state = get_option( 'fa_state', 'v1' );
	$t = [];
	$t['Site Settings'] = [ [ 'id' => 'recSET', 'fields' => [ 'Artist Name' => 'The Velvet Tides', 'Tagline' => 'Coastal indie from Devon', 'Full Bio' => "**The Velvet Tides** formed in Exeter.\n\n- Four piece\n- Two albums", 'Logo' => [ fa_att( 'attLOGO', 'logo.png' ) ], 'Primary Colour' => '#ee4367', 'Booking Email' => 'bookings@velvettides.test', 'Spotify' => 'https://open.spotify.com/artist/abc', 'Last Published' => $state === 'v1' ? '2026-10-03T10:00:00.000Z' : '2026-10-03T12:00:00.000Z' ] ] ];
	$t['Gigs'] = [
		[ 'id' => 'recG1', 'fields' => [ 'Gig' => 'Phoenix, Exeter', 'Date' => '2026-11-14', 'Venue' => 'Phoenix', 'City' => 'Exeter', 'Status' => 'On Sale', 'Ticket URL' => 'https://tickets.test/1', 'Show on Site' => true ] ],
		[ 'id' => 'recG2', 'fields' => [ 'Gig' => 'The Fleece, Bristol', 'Date' => '2026-11-20', 'Venue' => 'The Fleece', 'City' => 'Bristol', 'Status' => 'Sold Out', 'Show on Site' => true, 'Festival' => false ] ],
		[ 'id' => 'recG3', 'fields' => [ 'Gig' => 'Secret show', 'Date' => '2026-12-01', 'Show on Site' => $state !== 'v1' ] ],
	];
	if ( $state === 'v2' ) { unset( $t['Gigs'][1] ); $t['Gigs'] = array_values( $t['Gigs'] ); $t['Gigs'][0]['fields']['Status'] = 'Few Left'; }
	$t['Releases'] = [
		[ 'id' => 'recR1', 'fields' => [ 'Title' => 'Low Tide', 'Type' => 'Album', 'Release Date' => '2026-03-01', 'Artwork' => [ fa_att( 'attR1', 'lowtide.png' ) ], 'Tracks' => [ 'recT2', 'recT1' ], 'Spotify URL' => 'https://open.spotify.com/album/x', 'Featured' => true ] ],
		[ 'id' => 'recR2', 'fields' => [ 'Title' => 'Harbour Lights', 'Type' => 'Single', 'Release Date' => '2026-09-01', 'Tracks' => [ 'recT3' ] ] ],
	];
	$t['Tracks'] = [
		[ 'id' => 'recT1', 'fields' => [ 'Title' => 'Undertow', 'Track Number' => 1, 'Release' => [ 'recR1' ], 'Duration' => '3:41' ] ],
		[ 'id' => 'recT2', 'fields' => [ 'Title' => 'Salt', 'Track Number' => 2, 'Release' => [ 'recR1' ] ] ],
		[ 'id' => 'recT3', 'fields' => [ 'Title' => 'Harbour Lights', 'Track Number' => 1, 'Release' => [ 'recR2' ] ] ],
	];
	$t['Members'] = [
		[ 'id' => 'recM1', 'fields' => [ 'Name' => 'Ella Marsh', 'Role' => 'Vocals', 'Photo' => [ fa_att( 'attM1', 'ella.png' ) ], 'Show on Site' => true ] ],
		[ 'id' => 'recM2', 'fields' => [ 'Name' => 'Tom Hale', 'Role' => 'Guitar', 'Show on Site' => true ] ],
	];
	$t['News'] = [
		[ 'id' => 'recN1', 'fields' => [ 'Headline' => 'Tour announced', 'Date' => '2026-09-20T09:00:00.000Z', 'Body' => 'We are going on tour!', 'Published' => true ] ],
		[ 'id' => 'recN2', 'fields' => [ 'Headline' => 'Draft post', 'Published' => false ] ],
	];
	$t['Gallery'] = [];
	for ( $i = 1; $i <= 5; $i++ ) { $t['Gallery'][] = [ 'id' => 'recP' . $i, 'fields' => [ 'Title' => 'Photo ' . $i, 'Photo' => [ fa_att( 'attP' . $i, 'p' . $i . '.png' ) ], 'Album' => [ 'Live' ], 'Show on Site' => true ] ]; }
	$t['Videos'] = [ [ 'id' => 'recV1', 'fields' => [ 'Title' => 'Low Tide (Official Video)', 'Video URL' => 'https://www.youtube.com/watch?v=aBcDeFgHiJk', 'Featured' => true, 'Show on Site' => true ] ] ];
	$t['Press'] = [ [ 'id' => 'recPR1', 'fields' => [ 'Publication' => 'DIY', 'Quote' => 'Glorious.', 'Rating' => 4, 'Show on Site' => true ] ] ];
	$t['Merch'] = [ [ 'id' => 'recMR1', 'fields' => [ 'Item' => 'Tour tee', 'Price' => 20, 'Store URL' => 'https://shop.test/tee', 'Show on Site' => true ] ] ];
	return $t;
}
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( 0 === strpos( $url, 'https://dl.airtable.test/' ) ) {
		$img = imagecreatetruecolor( 40, 40 ); imagefill( $img, 0, 0, imagecolorallocate( $img, 238, 67, 103 ) );
		ob_start(); imagepng( $img ); $png = ob_get_clean();
		if ( ! empty( $args['filename'] ) ) { file_put_contents( $args['filename'], $png ); }
		return [ 'headers' => [ 'content-type' => 'image/png' ], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => $args['filename'] ?? null ];
	}
	if ( 0 !== strpos( $url, 'https://api.airtable.com/v0/' ) ) { return $pre; }
	$log = get_option( 'fa_log', [] ); $log[] = $args['method'] . ' ' . rawurldecode( $url ); update_option( 'fa_log', $log, false );
	$path = parse_url( $url, PHP_URL_PATH ); parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	$resp = function ( $body, $code = 200 ) { return [ 'headers' => [], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [] ]; };
	if ( false !== strpos( $path, '/meta/bases/' ) ) {
		$tables = [];
		foreach ( fa_data() as $name => $rows ) { $fields = []; foreach ( $rows as $r ) foreach ( array_keys( $r['fields'] ) as $f ) $fields[ $f ] = [ 'name' => $f, 'type' => 'singleLineText' ]; $tables[] = [ 'name' => $name, 'fields' => array_values( $fields ) ]; }
		return $resp( [ 'tables' => $tables ] );
	}
	$parts = explode( '/', trim( $path, '/' ) ); $table = rawurldecode( end( $parts ) );
	if ( 'POST' === $args['method'] ) { $b = json_decode( $args['body'], true ); update_option( 'fa_created', $b, false ); return $resp( [ 'id' => 'recNEW', 'fields' => $b['fields'] ] ); }
	$data = fa_data();
	if ( ! isset( $data[ $table ] ) ) { return $resp( [ 'error' => [ 'type' => 'TABLE_NOT_FOUND', 'message' => 'Could not find table ' . $table ] ], 404 ); }
	$rows = $data[ $table ];
	if ( ! empty( $q['maxRecords'] ) ) { $rows = array_slice( $rows, 0, (int) $q['maxRecords'] ); }
	// Page Gallery 2 at a time to exercise pagination.
	$size = 'Gallery' === $table ? 2 : 100; $off = (int) ( $q['offset'] ?? 0 );
	$page = array_slice( $rows, $off, $size ); $body = [ 'records' => $page ];
	if ( $off + $size < count( $rows ) ) { $body['offset'] = (string) ( $off + $size ); }
	return $resp( $body );
}, 10, 3 );
