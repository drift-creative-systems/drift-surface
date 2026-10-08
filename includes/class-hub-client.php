<?php
/**
 * class-hub-client.php — client for a Drift: Surface Hub's website API.
 *
 * Deliberately small. Reads (paginated list), one write (create record, for
 * forms) and the schema endpoint (connection check). Talks to the hub's
 * `drift-hub/v0` API (drift-hub/includes/class-api.php), whose contract is:
 *
 *   GET  {hub}/meta/bases/{base}/tables   schema
 *   GET  {hub}/{base}/{table}             list: pageSize, offset, fields[],
 *                                         sort[n][field|direction], maxRecords
 *   POST {hub}/{base}/{table}             create (writable tables only)
 *
 * Auth is "Authorization: Bearer hub_…", the artist's token. Errors come back
 * as { error: { type, message } }, including 422 UNKNOWN_FIELD_NAME.
 *
 * The hub has no monthly call limit, but the sync still costs about one
 * request per table: keep it that way (no per-record or per-page-view calls).
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Hub_Client {

	const PAGE_SIZE = 100;
	const MAX_PAGES = 60; // 6,000 rows — a runaway guard, not a real limit.

	/** @var int Requests made during this PHP request, for the sync log. */
	private static $calls = 0;

	/* ── Requests ────────────────────────────────────────────────────── */

	/**
	 * One API request.
	 *
	 * @param string     $method GET|POST.
	 * @param string     $path   Path under the hub address, already URL-encoded per segment.
	 * @param array      $query  Query args. List values become key[]=…; 'sort' is expanded.
	 * @param array|null $body   JSON body for writes.
	 * @return array|WP_Error Decoded body, or WP_Error.
	 */
	public static function request( string $method, string $path, array $query = [], ?array $body = null ) {
		$hub = self::api_base();
		if ( '' === $hub ) {
			return new WP_Error( 'drift_surface_not_connected', __( 'No Drift: Surface Hub address saved.', 'drift-surface' ) );
		}

		$token = Drift_Surface_Settings::token();
		if ( '' === $token ) {
			return new WP_Error( 'drift_surface_not_connected', __( 'No hub token saved.', 'drift-surface' ) );
		}

		$url = $hub . ltrim( $path, '/' );
		if ( $query ) {
			$url .= '?' . self::build_query( $query );
		}

		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		self::$calls++;
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			$message = is_array( $decoded ) && isset( $decoded['error'] )
				? ( is_array( $decoded['error'] ) ? (string) ( $decoded['error']['message'] ?? $decoded['error']['type'] ?? '' ) : (string) $decoded['error'] )
				: '';
			$type    = is_array( $decoded ) && is_array( $decoded['error'] ?? null ) ? (string) ( $decoded['error']['type'] ?? '' ) : '';

			if ( 401 === $code ) {
				// The usual cause besides a wrong token: the hub's server strips the
				// Authorization header (the hub README has the .htaccess fix).
				$message = trim( $message . ' ' . __( 'Check the Base ID and token. If both are right, the hub\'s server may be dropping the Authorization header.', 'drift-surface' ) );
			}

			return new WP_Error(
				'drift_surface_hub_http',
				/* translators: 1: HTTP status, 2: the hub's message. */
				trim( sprintf( __( 'The hub returned HTTP %1$d. %2$s', 'drift-surface' ), $code, $message ) ),
				[ 'status' => $code, 'type' => $type, 'message' => $message ]
			);
		}

		return $decoded;
	}

	/**
	 * Every record in a table, following pagination. Returns WP_Error rather
	 * than a partial list if any page fails — a partial list would make the
	 * sync trash everything on the missing pages.
	 *
	 * @param string $table Table name.
	 * @param array  $args  Optional: fields (list), sort (list of [field, direction]), maxRecords.
	 * @return array|WP_Error
	 */
	public static function list_records( string $table, array $args = [] ) {
		$base = (string) Drift_Surface_Settings::get( 'base_id' );
		if ( ! Drift_Surface_Settings::valid_base_id( $base ) ) {
			return new WP_Error( 'drift_surface_not_connected', __( 'No valid Base ID saved.', 'drift-surface' ) );
		}

		$query = [ 'pageSize' => self::PAGE_SIZE ];
		foreach ( [ 'fields', 'sort', 'maxRecords' ] as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$query[ $key ] = $args[ $key ];
			}
		}

		$records = [];
		$offset  = '';
		$pages   = 0;

		do {
			if ( '' !== $offset ) {
				$query['offset'] = $offset;
			}

			$body = self::request( 'GET', rawurlencode( $base ) . '/' . rawurlencode( $table ), $query );
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			foreach ( (array) ( $body['records'] ?? [] ) as $record ) {
				if ( is_array( $record ) && ! empty( $record['id'] ) ) {
					$records[] = $record;
				}
			}

			$offset = (string) ( $body['offset'] ?? '' );
			$pages++;
		} while ( '' !== $offset && $pages < self::MAX_PAGES && empty( $args['maxRecords'] ) );

		return $records;
	}

	/**
	 * list_records(), but a field the hub doesn't know (renamed, or added to
	 * the map before the hub's schema) is dropped from the request and the
	 * call retried, rather than failing the whole table. Each retry is one
	 * more request, and only happens while a field is missing.
	 *
	 * Fields in $protected are never dropped: without them the rows can't be
	 * read correctly (title, status field, sort), so the table fails as
	 * before and its posts are left alone.
	 *
	 * @param string   $table     Table name.
	 * @param array    $args      As list_records(); 'fields' should be set.
	 * @param string[] $protected Fields that must exist.
	 * @return array{records: array, missing: string[]}|WP_Error
	 */
	public static function list_records_lenient( string $table, array $args, array $protected = [] ) {
		$missing = [];

		for ( $attempt = 0; $attempt <= 10; $attempt++ ) {
			$records = self::list_records( $table, $args );
			if ( ! is_wp_error( $records ) ) {
				return [ 'records' => $records, 'missing' => $missing ];
			}

			$field = self::unknown_field( $records );
			if ( '' === $field || in_array( $field, $protected, true ) || ! in_array( $field, (array) ( $args['fields'] ?? [] ), true ) ) {
				return $records;
			}

			$missing[]      = $field;
			$args['fields'] = array_values( array_diff( (array) $args['fields'], [ $field ] ) );
		}

		return $records;
	}

	/**
	 * The field name from the hub's 422 UNKNOWN_FIELD_NAME error
	 * ('Unknown field name: "Live Embed"'), or '' for any other error.
	 */
	private static function unknown_field( WP_Error $error ): string {
		$data = (array) $error->get_error_data();
		if ( 422 !== (int) ( $data['status'] ?? 0 ) || 'UNKNOWN_FIELD_NAME' !== ( $data['type'] ?? '' ) ) {
			return '';
		}
		return preg_match( '/"(.+)"/', (string) ( $data['message'] ?? '' ), $m ) ? stripcslashes( $m[1] ) : '';
	}

	/**
	 * Creates one record. typecast lets the hub coerce strings into select
	 * options, dates and so on.
	 *
	 * @return array|WP_Error The created record.
	 */
	public static function create_record( string $table, array $fields ) {
		$base = (string) Drift_Surface_Settings::get( 'base_id' );
		if ( ! Drift_Surface_Settings::valid_base_id( $base ) ) {
			return new WP_Error( 'drift_surface_not_connected', __( 'No valid Base ID saved.', 'drift-surface' ) );
		}

		return self::request(
			'POST',
			rawurlencode( $base ) . '/' . rawurlencode( $table ),
			[],
			[ 'fields' => $fields, 'typecast' => true ]
		);
	}

	/**
	 * Table/field schema for the connection check.
	 *
	 * @return array|WP_Error [ table name => [ field name => type ] ].
	 */
	public static function schema() {
		$base = (string) Drift_Surface_Settings::get( 'base_id' );
		if ( ! Drift_Surface_Settings::valid_base_id( $base ) ) {
			return new WP_Error( 'drift_surface_not_connected', __( 'No valid Base ID saved.', 'drift-surface' ) );
		}

		$body = self::request( 'GET', 'meta/bases/' . rawurlencode( $base ) . '/tables' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$out = [];
		foreach ( (array) ( $body['tables'] ?? [] ) as $table ) {
			$name = (string) ( $table['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$out[ $name ] = [];
			foreach ( (array) ( $table['fields'] ?? [] ) as $field ) {
				$out[ $name ][ (string) ( $field['name'] ?? '' ) ] = (string) ( $field['type'] ?? '' );
			}
		}

		return $out;
	}

	/**
	 * The hub's array query syntax: fields[]=A&fields[]=B and
	 * sort[0][field]=X&sort[0][direction]=asc. http_build_query() would write
	 * fields[0]=…, so this builds it by hand.
	 */
	public static function build_query( array $query ): string {
		$parts = [];

		foreach ( $query as $key => $value ) {
			if ( 'sort' === $key && is_array( $value ) ) {
				foreach ( array_values( $value ) as $i => $sort ) {
					$field     = is_array( $sort ) ? (string) ( $sort['field'] ?? $sort[0] ?? '' ) : (string) $sort;
					$direction = is_array( $sort ) ? strtolower( (string) ( $sort['direction'] ?? $sort[1] ?? 'asc' ) ) : 'asc';
					if ( '' === $field ) {
						continue;
					}
					$parts[] = rawurlencode( "sort[$i][field]" ) . '=' . rawurlencode( $field );
					$parts[] = rawurlencode( "sort[$i][direction]" ) . '=' . ( 'desc' === $direction ? 'desc' : 'asc' );
				}
				continue;
			}

			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$parts[] = rawurlencode( $key . '[]' ) . '=' . rawurlencode( (string) $item );
				}
				continue;
			}

			$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $parts );
	}

	/* ── Hub address ─────────────────────────────────────────────────── */

	/**
	 * The hub's API address, with a trailing slash, or '' when none is set.
	 * Set on Drift: Surface → Connection, or with DRIFT_SURFACE_HUB_URL in
	 * wp-config.php.
	 */
	public static function api_base(): string {
		$base = (string) Drift_Surface_Settings::get( 'api_base', '' );
		return Drift_Surface_Settings::valid_hub_url( $base ) ? trailingslashit( $base ) : '';
	}

	/** Requests made so far in this PHP request. */
	public static function calls(): int {
		return self::$calls;
	}
}
