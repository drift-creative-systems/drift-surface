<?php
/**
 * class-sync-engine.php — one-way Airtable → WordPress sync, driven entirely
 * by the product map.
 *
 * Generalised from vision-website's Team sync (the pattern every one of its
 * five per-table sync classes repeated). The rules carried over unchanged:
 *
 *   - Fetch every page of a table, or nothing: a failed page aborts that
 *     table, never acts on a partial list (which would bin everything on the
 *     missing pages).
 *   - Airtable owns the posts it creates (tagged with META_ID). Posts made by
 *     hand in wp-admin are never touched.
 *   - A content hash per post skips rows that haven't changed.
 *   - Rows that disappear (deleted, filtered out, or hidden via the entity's
 *     status field) go to the bin; rows that come back are restored.
 *   - A failed or deferred image leaves the hash blank so the next run retries.
 *
 * New here:
 *   - One generic engine for every table in the map, plus one "settings"
 *     table → an option array.
 *   - Linked records → WordPress post IDs (resolve_links()).
 *   - Image imports are capped per run; the remainder continues a minute
 *     later from cached records, so it costs no extra API calls.
 *   - A run lock, run status, activity log and page-cache purge.
 *
 * Templates never call Airtable. They read normal WordPress data that this
 * class wrote at sync time (theme CLAUDE.md rule: Airtable stays out of the
 * render path).
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Website_Sync_Engine {

	const META_ID      = '_drift_airtable_id';
	const META_HASH    = '_drift_airtable_hash';
	const META_ENTITY  = '_drift_entity';
	const LINKS_PREFIX = '_drift_links_';

	const LOCK          = 'drift_website_sync_lock';
	const CACHE         = 'drift_website_sync_records';
	const STATUS_OPTION = 'drift_website_sync_status';

	const HOOK_RUN      = 'drift_website_run_sync';
	const HOOK_CONTINUE = 'drift_website_continue_sync';

	public static function init(): void {
		add_action( self::HOOK_RUN, [ __CLASS__, 'cron_run' ] );
		add_action( self::HOOK_CONTINUE, [ __CLASS__, 'cron_continue' ] );
	}

	/* ── Entry points ────────────────────────────────────────────────── */

	/** @param string $trigger Why this run was queued (publish, daily, manual…). */
	public static function cron_run( $trigger = 'scheduled' ): void {
		self::run( (string) $trigger );
	}

	public static function cron_continue(): void {
		self::run( 'continue', [ 'use_cache' => true ] );
	}

	/**
	 * Queues a run in the background and pokes WP-Cron so it starts now.
	 * WordPress ignores a duplicate if one is already queued, which is what
	 * we want for a double-clicked Publish.
	 */
	public static function queue( string $trigger ): void {
		if ( ! wp_next_scheduled( self::HOOK_RUN, [ $trigger ] ) ) {
			wp_schedule_single_event( time(), self::HOOK_RUN, [ $trigger ] );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Runs a sync now.
	 *
	 * @param string $trigger Label for the log/status (manual, publish, daily, continue…).
	 * @param array  $opts    force (bool): ignore hashes. use_cache (bool): reuse the
	 *                        records fetched by the previous run instead of calling Airtable.
	 * @return array{ok: bool, messages: string[]}
	 */
	public static function run( string $trigger = 'manual', array $opts = [] ): array {
		$force     = ! empty( $opts['force'] );
		$use_cache = ! empty( $opts['use_cache'] );

		if ( ! Drift_Website_Settings::is_connected() ) {
			return self::finish_early( __( 'Not connected to Airtable — add the base ID and token on Drift → Connection.', 'drift-website' ) );
		}

		if ( get_transient( self::LOCK ) ) {
			// Someone else is mid-sync. A publish shouldn't be lost, so try again shortly.
			if ( 'publish' === $trigger && ! wp_next_scheduled( self::HOOK_RUN, [ 'publish-retry' ] ) ) {
				wp_schedule_single_event( time() + 120, self::HOOK_RUN, [ 'publish-retry' ] );
			}
			return self::finish_early( __( 'A sync is already running. This one has been skipped.', 'drift-website' ), false );
		}

		set_transient( self::LOCK, time(), 10 * MINUTE_IN_SECONDS );

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$started  = microtime( true );
		$map      = Drift_Website_Map::current();
		$messages = [];
		$ok       = true;
		$calls    = Drift_Website_Airtable::usage()['calls'];

		Drift_Website_Media::reset_run();

		$records = $use_cache ? get_transient( self::CACHE ) : false;
		if ( ! is_array( $records ) ) {
			$records = self::fetch( $map );
			set_transient( self::CACHE, $records, 30 * MINUTE_IN_SECONDS );
		}

		// Settings record.
		if ( $map['settings'] && $map['settings']['table'] ) {
			$result     = self::sync_settings( $map, $records['__settings'] ?? null );
			$messages[] = $result['message'];
			$ok         = $ok && $result['ok'];
		}

		// Entities.
		foreach ( $map['entities'] as $key => $entity ) {
			$result     = self::sync_entity( $entity, $records[ $key ] ?? null, $force );
			$messages[] = $result['message'];
			$ok         = $ok && $result['ok'];
		}

		self::resolve_links( $map );

		$deferred = Drift_Website_Media::was_deferred();
		if ( $deferred ) {
			$messages[] = __( 'More images to import — continuing automatically in about a minute.', 'drift-website' );
			if ( ! wp_next_scheduled( self::HOOK_CONTINUE ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_CONTINUE );
			}
		} else {
			delete_transient( self::CACHE );
		}

		$calls_used = Drift_Website_Airtable::usage()['calls'] - $calls;

		$status = array_merge( self::status(), [
			'last_run'       => time(),
			'last_ok'        => $ok ? time() : ( self::status()['last_ok'] ?? 0 ),
			'trigger'        => $trigger,
			'ok'             => $ok,
			'messages'       => $messages,
			'duration'       => round( microtime( true ) - $started, 1 ),
			'api_calls'      => $calls_used,
			'images'         => Drift_Website_Media::imported_count(),
			'pending_images' => $deferred,
		] );
		update_option( self::STATUS_OPTION, $status, false );

		delete_transient( self::LOCK );

		self::purge_page_caches();

		$summary = sprintf(
			/* translators: 1: trigger, 2: seconds, 3: API calls, 4: images imported. */
			__( 'Sync (%1$s) finished in %2$ss — %3$d API calls, %4$d images imported.', 'drift-website' ),
			$trigger,
			$status['duration'],
			$calls_used,
			$status['images']
		);
		if ( $ok ) {
			Drift_Website_Log::info( $summary . ' ' . implode( ' ', $messages ) );
		} else {
			Drift_Website_Log::warning( $summary . ' ' . implode( ' ', $messages ) );
		}

		/**
		 * Fires after every sync run.
		 *
		 * @param array $status Run status (ok, messages, duration, api_calls…).
		 */
		do_action( 'drift_website_synced', $status );

		return [ 'ok' => $ok, 'messages' => $messages ];
	}

	private static function finish_early( string $message, bool $log = true ): array {
		if ( $log ) {
			Drift_Website_Log::warning( $message );
		}
		return [ 'ok' => false, 'messages' => [ $message ] ];
	}

	/** @return array Last run status. */
	public static function status(): array {
		$status = get_option( self::STATUS_OPTION, [] );
		return is_array( $status ) ? $status : [];
	}

	/* ── Fetch ───────────────────────────────────────────────────────── */

	/**
	 * Every table the map needs. A failed table is stored as a WP_Error so
	 * its sync is skipped (existing posts left alone) while others continue.
	 *
	 * @return array<string, array|WP_Error|null> Keyed by entity key, plus '__settings'.
	 */
	private static function fetch( array $map ): array {
		$out = [];

		if ( $map['settings'] && $map['settings']['table'] ) {
			$fields = array_keys( $map['settings']['fields'] );
			if ( ! empty( $map['publish']['field'] ) ) {
				$fields[] = (string) $map['publish']['field'];
			}
			$out['__settings'] = Drift_Website_Airtable::list_records(
				$map['settings']['table'],
				[ 'maxRecords' => 1, 'fields' => array_values( array_unique( $fields ) ) ]
			);
		}

		foreach ( $map['entities'] as $key => $entity ) {
			usleep( Drift_Website_Airtable::THROTTLE_US );
			$out[ $key ] = Drift_Website_Airtable::list_records(
				$entity['table'],
				[
					'view'            => $entity['view'],
					'filterByFormula' => $entity['filter'],
					'sort'            => $entity['sort'],
					'fields'          => Drift_Website_Map::requested_fields( $entity ),
				]
			);
		}

		return $out;
	}

	/* ── Settings table → option ─────────────────────────────────────── */

	/**
	 * @param array             $map     Product map.
	 * @param array|WP_Error|null $records Fetched settings records.
	 */
	private static function sync_settings( array $map, $records ): array {
		$spec  = $map['settings'];
		$label = $spec['table'];

		if ( is_wp_error( $records ) || ! is_array( $records ) ) {
			$error = is_wp_error( $records ) ? $records->get_error_message() : __( 'not fetched', 'drift-website' );
			Drift_Website_Log::error( $label . ': ' . $error );
			/* translators: 1: table, 2: error. */
			return [ 'ok' => false, 'message' => sprintf( __( '%1$s: failed — %2$s', 'drift-website' ), $label, $error ) ];
		}

		if ( ! $records ) {
			/* translators: %s: table. */
			return [ 'ok' => false, 'message' => sprintf( __( '%s: the table is empty — add one row.', 'drift-website' ), $label ) ];
		}

		$record   = $records[0];
		$fields   = (array) ( $record['fields'] ?? [] );
		$previous = get_option( $spec['option'], [] );
		$previous = is_array( $previous ) ? $previous : [];
		$values   = [ '_airtable_id' => (string) $record['id'] ];

		foreach ( $spec['fields'] as $name => $field ) {
			$key = (string) $field['to'];
			$raw = $fields[ $name ] ?? null;

			if ( 'image' === $field['type'] ) {
				$first          = is_array( $raw ) && is_array( $raw[0] ?? null ) ? $raw[0] : null;
				$id             = $first ? Drift_Website_Media::attachment_id( $first ) : 0;
				$values[ $key ] = $id ?: ( $first ? (int) ( $previous[ $key ] ?? 0 ) : 0 ); // Keep the old image while a new one is pending.
				continue;
			}
			if ( 'gallery' === $field['type'] ) {
				$values[ $key ] = self::gallery_ids( $raw, 0 )['ids'];
				continue;
			}

			$values[ $key ] = self::convert( $raw, $field['type'] );
		}

		if ( ! empty( $map['publish']['field'] ) ) {
			$values['_last_published'] = Drift_Website_Media::plain( $fields[ $map['publish']['field'] ] ?? '' );
		}

		update_option( $spec['option'], $values, true );

		/* translators: %s: table. */
		return [ 'ok' => true, 'message' => sprintf( __( '%s: updated.', 'drift-website' ), $label ) ];
	}

	/* ── Entity table → posts ────────────────────────────────────────── */

	/**
	 * @param array               $entity  Entity spec from the map.
	 * @param array|WP_Error|null $records Fetched records.
	 * @param bool                $force   Ignore hashes.
	 */
	private static function sync_entity( array $entity, $records, bool $force ): array {
		$label = (string) ( $entity['register']['label'] ?? $entity['table'] );

		if ( ! post_type_exists( $entity['post_type'] ) ) {
			/* translators: 1: label, 2: post type. */
			$message = sprintf( __( '%1$s: post type "%2$s" is not registered.', 'drift-website' ), $label, $entity['post_type'] );
			Drift_Website_Log::error( $message );
			return [ 'ok' => false, 'message' => $message ];
		}

		if ( is_wp_error( $records ) || ! is_array( $records ) ) {
			$error = is_wp_error( $records ) ? $records->get_error_message() : __( 'not fetched', 'drift-website' );
			Drift_Website_Log::error( $label . ': ' . $error . ' — existing items left as they are.' );
			/* translators: 1: label, 2: error. */
			return [ 'ok' => false, 'message' => sprintf( __( '%1$s: failed — %2$s (nothing changed).', 'drift-website' ), $label, $error ) ];
		}

		$existing = self::existing_posts( $entity['post_type'] );
		$seen     = [];
		$counts   = [ 'added' => 0, 'updated' => 0, 'removed' => 0 ];
		$row      = 0;

		foreach ( $records as $record ) {
			$record_id = (string) $record['id'];
			$fields    = (array) ( $record['fields'] ?? [] );
			$title     = Drift_Website_Media::plain( $fields[ $entity['title'] ] ?? '' );

			if ( '' === $title ) {
				continue; // Blank row — no unnamed posts.
			}
			if ( $entity['status_field'] && ! self::truthy( $fields[ $entity['status_field'] ] ?? null ) ) {
				continue; // Hidden in Airtable — treated as removed below.
			}

			$row++;
			$seen[ $record_id ] = true;

			$order = 'row' === $entity['order'] ? $row : (int) Drift_Website_Media::plain( $fields[ $entity['order'] ] ?? 0 );
			$hash  = md5( (string) wp_json_encode( [ DRIFT_WEBSITE_VERSION, $title, $order, self::hashable( $fields ), $entity['fields'] ] ) );
			$post  = $existing[ $record_id ] ?? null;

			if ( ! $force && $post && in_array( $post->post_status, [ 'publish', 'future' ], true ) && get_post_meta( $post->ID, self::META_HASH, true ) === $hash ) {
				continue;
			}

			$result = self::save_post( $entity, $record_id, $title, $order, $fields, $post );
			if ( ! $result['post_id'] ) {
				continue;
			}

			if ( $post ) {
				$counts['updated']++;
			} else {
				$counts['added']++;
			}
			update_post_meta( $result['post_id'], self::META_HASH, $result['complete'] ? $hash : '' );
		}

		foreach ( $existing as $record_id => $post ) {
			if ( isset( $seen[ $record_id ] ) || 'trash' === $post->post_status ) {
				continue;
			}
			switch ( $entity['on_remove'] ) {
				case 'delete':
					wp_delete_post( $post->ID, true );
					break;
				case 'draft':
					if ( 'draft' !== $post->post_status ) {
						wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ] );
					}
					break;
				default:
					wp_trash_post( $post->ID );
			}
			$counts['removed']++;
		}

		return [
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: label, 2: live count, 3: added, 4: updated, 5: removed. */
				__( '%1$s: %2$d live (%3$d added, %4$d updated, %5$d removed).', 'drift-website' ),
				$label,
				count( $seen ),
				$counts['added'],
				$counts['updated'],
				$counts['removed']
			),
		];
	}

	/**
	 * Creates or updates one post from one Airtable row.
	 *
	 * @return array{post_id: int, complete: bool} complete=false means an image
	 *                                             is still pending, so don't store the hash.
	 */
	private static function save_post( array $entity, string $record_id, string $title, int $order, array $fields, ?WP_Post $post ): array {
		$postarr = [
			'post_type'   => $entity['post_type'],
			'post_status' => 'publish',
			'post_title'  => $title,
			'menu_order'  => $order,
		];

		if ( $entity['slug'] ) {
			$slug = sanitize_title( Drift_Website_Media::plain( $fields[ $entity['slug'] ] ?? '' ) );
			if ( '' !== $slug ) {
				$postarr['post_name'] = $slug;
			}
		}

		$meta       = [];
		$terms      = [];
		$thumbnail  = null;
		$images     = [];
		$galleries  = [];
		$links      = [];

		foreach ( $entity['fields'] as $name => $field ) {
			$to  = (string) $field['to'];
			$raw = $fields[ $name ] ?? null;

			if ( 'thumbnail' === $to ) {
				$thumbnail = is_array( $raw ) && is_array( $raw[0] ?? null ) ? $raw[0] : false;
				continue;
			}

			if ( in_array( $to, [ 'content', 'excerpt' ], true ) ) {
				$postarr[ 'post_' . $to ] = 'html' === $field['type'] && 'content' === $to
					? Drift_Website_Media::markdown_to_html( Drift_Website_Media::plain( $raw ) )
					: Drift_Website_Media::plain( $raw );
				continue;
			}

			if ( 'post_date' === $to ) {
				$date = self::convert( $raw, 'datetime' );
				if ( '' !== $date ) {
					$postarr['post_date']     = $date;
					$postarr['post_date_gmt'] = get_gmt_from_date( $date );
					$postarr['edit_date']     = true;
				}
				continue;
			}

			if ( 0 === strpos( $to, 'tax:' ) ) {
				$terms[ substr( $to, 4 ) ] = (array) self::convert( $raw, 'list' );
				continue;
			}

			if ( 0 === strpos( $to, 'meta:' ) ) {
				$key = substr( $to, 5 );
				switch ( $field['type'] ) {
					case 'image':
						$images[ $key ] = is_array( $raw ) && is_array( $raw[0] ?? null ) ? $raw[0] : false;
						break;
					case 'gallery':
						$galleries[ $key ] = $raw;
						break;
					case 'link':
						$links[ $key ] = array_values( array_filter( array_map( 'strval', (array) $raw ), static fn( $id ) => 0 === strpos( $id, 'rec' ) ) );
						break;
					default:
						$meta[ $key ] = self::convert( $raw, $field['type'] );
				}
			}
		}

		if ( $post ) {
			if ( 'trash' === $post->post_status ) {
				wp_untrash_post( $post->ID );
			}
			$postarr['ID'] = $post->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			Drift_Website_Log::error( sprintf( 'Could not save "%s" (%s) — %s', $title, $record_id, is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown error' ) );
			return [ 'post_id' => 0, 'complete' => false ];
		}

		$post_id  = (int) $post_id;
		$complete = true;

		update_post_meta( $post_id, self::META_ID, $record_id );
		update_post_meta( $post_id, self::META_ENTITY, $entity['key'] );

		foreach ( $meta as $key => $value ) {
			if ( '' === $value || [] === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		foreach ( $terms as $taxonomy => $names ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_set_object_terms( $post_id, array_values( array_filter( $names ) ), $taxonomy, false );
			}
		}

		if ( null !== $thumbnail ) {
			if ( false === $thumbnail ) {
				delete_post_thumbnail( $post_id );
			} else {
				$attachment_id = Drift_Website_Media::attachment_id( $thumbnail, $post_id );
				if ( $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
				} else {
					$complete = false;
				}
			}
		}

		foreach ( $images as $key => $attachment ) {
			if ( false === $attachment ) {
				delete_post_meta( $post_id, $key );
				continue;
			}
			$attachment_id = Drift_Website_Media::attachment_id( $attachment, $post_id );
			if ( $attachment_id ) {
				update_post_meta( $post_id, $key, $attachment_id );
			} else {
				$complete = false;
			}
		}

		foreach ( $galleries as $key => $raw ) {
			$gallery = self::gallery_ids( $raw, $post_id );
			if ( $gallery['ids'] ) {
				update_post_meta( $post_id, $key, $gallery['ids'] );
			} else {
				delete_post_meta( $post_id, $key );
			}
			$complete = $complete && $gallery['complete'];
		}

		foreach ( $links as $key => $record_ids ) {
			update_post_meta( $post_id, self::LINKS_PREFIX . $key, $record_ids );
		}

		return [ 'post_id' => $post_id, 'complete' => $complete ];
	}

	/**
	 * Synced posts of one type, any status, keyed by Airtable record ID.
	 *
	 * @return array<string, WP_Post>
	 */
	private static function existing_posts( string $post_type ): array {
		$posts = get_posts( [
			'post_type'        => $post_type,
			'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ],
			'posts_per_page'   => -1,
			'meta_key'         => self::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'no_found_rows'    => true,
			'suppress_filters' => true,
		] );

		$by_id = [];
		foreach ( $posts as $post ) {
			$by_id[ (string) get_post_meta( $post->ID, self::META_ID, true ) ] = $post;
		}
		return $by_id;
	}

	/* ── Linked records ──────────────────────────────────────────────── */

	/**
	 * Turns stored Airtable record IDs into WordPress post IDs for every
	 * 'link' field, once all tables have synced (a release can link to tracks
	 * that were only created in this run). Links to rows that aren't on the
	 * site are dropped. Order is kept.
	 */
	private static function resolve_links( array $map ): void {
		global $wpdb;

		$link_keys = [];
		foreach ( $map['entities'] as $entity ) {
			foreach ( $entity['fields'] as $field ) {
				if ( 'link' === $field['type'] && 0 === strpos( (string) $field['to'], 'meta:' ) ) {
					$link_keys[ $entity['post_type'] ][] = substr( (string) $field['to'], 5 );
				}
			}
		}
		if ( ! $link_keys ) {
			return;
		}

		// Record ID → post ID, published posts only.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.meta_value AS record_id, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status IN ('publish','future')",
				self::META_ID
			)
		);
		$lookup = [];
		foreach ( (array) $rows as $row ) {
			$lookup[ (string) $row->record_id ] = (int) $row->post_id;
		}

		foreach ( $link_keys as $post_type => $keys ) {
			$posts = get_posts( [
				'post_type'        => $post_type,
				'post_status'      => [ 'publish', 'future' ],
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => self::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'    => true,
				'suppress_filters' => true,
			] );

			foreach ( $posts as $post_id ) {
				foreach ( array_unique( $keys ) as $key ) {
					$record_ids = (array) get_post_meta( $post_id, self::LINKS_PREFIX . $key, true );
					$ids        = [];
					foreach ( $record_ids as $record_id ) {
						if ( isset( $lookup[ $record_id ] ) ) {
							$ids[] = $lookup[ $record_id ];
						}
					}
					if ( $ids ) {
						update_post_meta( $post_id, $key, $ids );
					} else {
						delete_post_meta( $post_id, $key );
					}
				}
			}
		}
	}

	/* ── Value helpers ───────────────────────────────────────────────── */

	/**
	 * Converts one Airtable value to what WordPress stores.
	 *
	 * @param mixed  $raw  Airtable value.
	 * @param string $type Map field type.
	 * @return mixed
	 */
	public static function convert( $raw, string $type ) {
		switch ( $type ) {
			case 'html':
				return Drift_Website_Media::markdown_to_html( Drift_Website_Media::plain( $raw ) );

			case 'date':
				$text = Drift_Website_Media::plain( is_array( $raw ) ? ( $raw[0] ?? '' ) : $raw );
				if ( '' === $text ) {
					return '';
				}
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
					return $text;
				}
				$ts = strtotime( $text );
				return $ts ? wp_date( 'Y-m-d', $ts ) : '';

			case 'datetime':
				$text = Drift_Website_Media::plain( is_array( $raw ) ? ( $raw[0] ?? '' ) : $raw );
				if ( '' === $text ) {
					return '';
				}
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
					return $text . ' 00:00:00';
				}
				try {
					$date = new DateTimeImmutable( $text, new DateTimeZone( 'UTC' ) );
					return $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
				} catch ( Exception $e ) {
					return '';
				}

			case 'number':
				$text = Drift_Website_Media::plain( is_array( $raw ) ? ( $raw[0] ?? '' ) : $raw );
				return is_numeric( $text ) ? ( 0 + $text ) : '';

			case 'bool':
				return self::truthy( $raw ) ? '1' : '';

			case 'url':
				// Shape check only. wp_http_validate_url() would do a DNS lookup per
				// URL during sync and reject valid links on hosts with flaky DNS.
				$url = esc_url_raw( Drift_Website_Media::plain( is_array( $raw ) ? ( $raw[0] ?? '' ) : $raw ), [ 'http', 'https' ] );
				return ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) ? $url : '';

			case 'email':
				return (string) sanitize_email( Drift_Website_Media::plain( is_array( $raw ) ? ( $raw[0] ?? '' ) : $raw ) );

			case 'list':
				$list = [];
				foreach ( is_array( $raw ) ? $raw : ( null === $raw || '' === $raw ? [] : [ $raw ] ) as $item ) {
					$text = sanitize_text_field( Drift_Website_Media::plain( $item ) );
					if ( '' !== $text ) {
						$list[] = $text;
					}
				}
				return $list;

			case 'json':
				return is_array( $raw ) ? $raw : ( null === $raw ? '' : $raw );

			case 'text':
			default:
				return sanitize_textarea_field( Drift_Website_Media::plain( $raw ) );
		}
	}

	/** @return array{ids: int[], complete: bool} */
	private static function gallery_ids( $raw, int $parent_id ): array {
		$ids      = [];
		$complete = true;
		foreach ( is_array( $raw ) ? $raw : [] as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$id = Drift_Website_Media::attachment_id( $attachment, $parent_id );
			if ( $id ) {
				$ids[] = $id;
			} else {
				$complete = false;
			}
		}
		return [ 'ids' => $ids, 'complete' => $complete ];
	}

	private static function truthy( $value ): bool {
		if ( is_array( $value ) ) {
			return [] !== $value && self::truthy( $value[0] ?? null );
		}
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), [ '', '0', 'no', 'false', 'off' ], true );
		}
		return (bool) $value;
	}

	/**
	 * Field values with attachments reduced to their stable IDs — attachment
	 * URLs change on every API response, so hashing them would re-save every
	 * row on every run.
	 */
	private static function hashable( array $fields ): array {
		ksort( $fields );
		foreach ( $fields as $name => $value ) {
			if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) && isset( $value[0]['id'], $value[0]['url'] ) ) {
				$fields[ $name ] = array_map( static fn( $a ) => is_array( $a ) ? ( $a['id'] ?? '' ) : '', $value );
			}
		}
		return $fields;
	}

	/* ── Page caches ─────────────────────────────────────────────────── */

	/**
	 * Clears common page caches after a sync so a published change shows
	 * straight away. Each call is guarded; anything else can hook
	 * `drift_website_synced`.
	 */
	private static function purge_page_caches(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			WpeCommon::purge_varnish_cache();
		}
		do_action( 'litespeed_purge_all' );
		wp_cache_flush();
	}
}
