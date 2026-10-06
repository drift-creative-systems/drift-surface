<?php
/**
 * class-airtable.php — Airtable Web API client.
 *
 * Deliberately small. Reads (paginated list), one write (create record, for
 * forms) and the schema endpoint (connection check). Every request is counted
 * against a monthly budget, because on Airtable's Free plan the whole
 * workspace gets 1,000 API calls a month — see CLAUDE.md "API budget".
 *
 * Airtable also enforces 5 requests/second per base and answers 429 with a
 * mandatory 30-second back-off. Paged reads are throttled to stay under the
 * first; a 429 sets a pause transient that every request respects.
 *
 * Token scopes needed: data.records:read (sync), data.records:write (forms,
 * optional), schema.bases:read (connection check, optional).
 *
 * @package Encore_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Encore_Website_Airtable {

	const API          = 'https://api.airtable.com/v0/';
	const USAGE_OPTION = 'encore_website_api_usage';
	const PAUSE_KEY    = 'encore_website_airtable_pause';
	const PAGE_SIZE    = 100;
	const MAX_PAGES    = 60;     // 6,000 rows — a runaway guard, not a real limit (Free plan caps a base at 1,000).
	const THROTTLE_US  = 220000; // ~4.5 req/s, under Airtable's 5/s per base.

	/* ── Requests ────────────────────────────────────────────────────── */

	/**
	 * One API request.
	 *
	 * @param string     $method GET|POST|PATCH.
	 * @param string     $path   Path under /v0/, already URL-encoded per segment.
	 * @param array      $query  Query args. List values become key[]=…; 'sort' is expanded.
	 * @param array|null $body   JSON body for writes.
	 * @return array|WP_Error Decoded body, or WP_Error.
	 */
	public static function request( string $method, string $path, array $query = [], ?array $body = null ) {
		$token = Encore_Website_Settings::token();
		if ( '' === $token ) {
			return new WP_Error( 'encore_not_connected', __( 'No Airtable token saved.', 'encore-website' ) );
		}

		$paused_until = (int) get_transient( self::PAUSE_KEY );
		if ( $paused_until > time() ) {
			return new WP_Error(
				'encore_rate_limited',
				/* translators: %d: seconds. */
				sprintf( __( 'Airtable asked us to slow down. Retrying is allowed in %d seconds.', 'encore-website' ), $paused_until - time() )
			);
		}

		$url = self::API . ltrim( $path, '/' );
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

		self::record_call();
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			set_transient( self::PAUSE_KEY, time() + 35, 35 );
			return new WP_Error( 'encore_rate_limited', __( 'Airtable rate limit hit (HTTP 429). Paused for 35 seconds.', 'encore-website' ) );
		}

		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			$message = is_array( $decoded ) && isset( $decoded['error'] )
				? ( is_array( $decoded['error'] ) ? (string) ( $decoded['error']['message'] ?? $decoded['error']['type'] ?? '' ) : (string) $decoded['error'] )
				: '';
			$type    = is_array( $decoded ) && is_array( $decoded['error'] ?? null ) ? (string) ( $decoded['error']['type'] ?? '' ) : '';
			return new WP_Error(
				'encore_airtable_http',
				/* translators: 1: HTTP status, 2: Airtable's message. */
				trim( sprintf( __( 'Airtable returned HTTP %1$d. %2$s', 'encore-website' ), $code, $message ) ),
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
	 * @param string $table Table name or ID.
	 * @param array  $args  Optional: view, filterByFormula, fields (list), sort (list of [field, direction]).
	 * @return array|WP_Error
	 */
	public static function list_records( string $table, array $args = [] ) {
		$base = (string) Encore_Website_Settings::get( 'base_id' );
		if ( ! Encore_Website_Settings::valid_base_id( $base ) ) {
			return new WP_Error( 'encore_not_connected', __( 'No valid Airtable base ID saved.', 'encore-website' ) );
		}

		$query = [ 'pageSize' => self::PAGE_SIZE ];
		foreach ( [ 'view', 'filterByFormula', 'fields', 'sort', 'maxRecords' ] as $key ) {
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
				usleep( self::THROTTLE_US );
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
	 * list_records(), but a field Airtable doesn't know (deleted, renamed, or
	 * not added to this base yet) is dropped from the request and the call
	 * retried, rather than failing the whole table. Each retry is one more
	 * API call, and only happens while a field is missing.
	 *
	 * Fields in $protected are never dropped: without them the rows can't be
	 * read correctly (title, status field, sort), so the table fails as
	 * before and its posts are left alone.
	 *
	 * @param string   $table     Table name or ID.
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
			usleep( self::THROTTLE_US );
		}

		return $records;
	}

	/**
	 * The field name from Airtable's 422 UNKNOWN_FIELD_NAME error
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
	 * Creates one record. typecast lets Airtable coerce strings into select
	 * options, dates and so on.
	 *
	 * @return array|WP_Error The created record.
	 */
	public static function create_record( string $table, array $fields ) {
		$base = (string) Encore_Website_Settings::get( 'base_id' );
		if ( ! Encore_Website_Settings::valid_base_id( $base ) ) {
			return new WP_Error( 'encore_not_connected', __( 'No valid Airtable base ID saved.', 'encore-website' ) );
		}

		return self::request(
			'POST',
			rawurlencode( $base ) . '/' . rawurlencode( $table ),
			[],
			[ 'fields' => $fields, 'typecast' => true ]
		);
	}

	/**
	 * Table/field schema for the connection check. Needs schema.bases:read.
	 *
	 * @return array|WP_Error [ table name => [ field name => type ] ].
	 */
	public static function schema() {
		$base = (string) Encore_Website_Settings::get( 'base_id' );
		if ( ! Encore_Website_Settings::valid_base_id( $base ) ) {
			return new WP_Error( 'encore_not_connected', __( 'No valid Airtable base ID saved.', 'encore-website' ) );
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
	 * Airtable's array query syntax: fields[]=A&fields[]=B and
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

	/* ── API budget ──────────────────────────────────────────────────── */

	private static function month(): string {
		return gmdate( 'Y-m' );
	}

	private static function record_call(): void {
		$usage = self::usage();
		$usage['calls']++;
		update_option( self::USAGE_OPTION, $usage, false );
	}

	/** @return array{month: string, calls: int} This calendar month (UTC), as Airtable counts it. */
	public static function usage(): array {
		$usage = get_option( self::USAGE_OPTION, [] );
		if ( ! is_array( $usage ) || ( $usage['month'] ?? '' ) !== self::month() ) {
			$usage = [ 'month' => self::month(), 'calls' => 0 ];
		}
		$usage['calls'] = (int) $usage['calls'];
		return $usage;
	}

	public static function budget(): int {
		return (int) Encore_Website_Settings::get( 'api_budget', 1000 );
	}

	public static function over_budget(): bool {
		return self::usage()['calls'] >= self::budget();
	}
}
