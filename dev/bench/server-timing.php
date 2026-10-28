<?php
/**
 * Plugin Name: OpenEvents benchmark timing
 * Description: Dev only. For local requests (127.0.0.1) with the header "X-OE-Bench: 1", adds a Server-Timing header with total PHP time, database query count and time, and peak memory. Copy into wp-content/mu-plugins on a local site to benchmark; never deploy.
 *
 * @package OpenEvents
 */

// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.PHP.DevelopmentFunctions

// Only for requests from the machine itself (the runner calls 127.0.0.1 inside
// the web container), so the header can't be used from outside.
if ( empty( $_SERVER['HTTP_X_OE_BENCH'] ) || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) {
	return;
}

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

// Buffer the whole response so the header can be sent after the work is done.
ob_start();

add_action(
	'shutdown',
	static function (): void {
		global $wpdb;
		$total   = ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000;
		$queries = is_array( $wpdb->queries ) ? $wpdb->queries : array();
		$db_ms   = 0.0;
		foreach ( $queries as $query ) {
			$db_ms += (float) $query[1] * 1000;
		}
		if ( ! headers_sent() ) {
			header(
				sprintf(
					'Server-Timing: total;dur=%.1f, db;dur=%.1f;desc="%d queries", mem;desc="%.1f MB"',
					$total,
					$db_ms,
					count( $queries ),
					memory_get_peak_usage() / 1048576
				)
			);
		}
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	},
	-1000
);
