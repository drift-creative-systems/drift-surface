<?php
/**
 * class-log.php — a small rolling activity log (last 100 entries) shown on the
 * Drift: Surface → Sync tab, so "why didn't my gig appear?" can be answered without
 * server log access. Errors also go to the PHP error log.
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Log {

	const OPTION = 'drift_surface_log';
	const LIMIT  = 100;

	public static function info( string $message, string $source = 'sync' ): void {
		self::add( 'info', $message, $source );
	}

	public static function warning( string $message, string $source = 'sync' ): void {
		self::add( 'warning', $message, $source );
	}

	public static function error( string $message, string $source = 'sync' ): void {
		self::add( 'error', $message, $source );
		error_log( 'Drift: Surface [' . $source . ']: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private static function add( string $level, string $message, string $source ): void {
		$log   = self::entries();
		$log[] = [
			'time'    => time(),
			'level'   => $level,
			'source'  => sanitize_key( $source ),
			'message' => wp_strip_all_tags( $message ),
		];

		if ( count( $log ) > self::LIMIT ) {
			$log = array_slice( $log, -self::LIMIT );
		}

		update_option( self::OPTION, $log, false );
	}

	/** @return array<int, array{time:int, level:string, source:string, message:string}> Oldest first. */
	public static function entries(): array {
		$log = get_option( self::OPTION, [] );
		return is_array( $log ) ? array_values( $log ) : [];
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
