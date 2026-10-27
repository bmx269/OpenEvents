<?php
/**
 * Development only: appends query count and render time to front-end pages
 * when ?oec_bench=1 is present. Requires SAVEQUERIES. Copy to mu-plugins.
 *
 * @package OpenEvents
 */

add_action(
	'shutdown',
	static function (): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['oec_bench'] ) || is_admin() || wp_doing_ajax() || ! defined( 'SAVEQUERIES' ) ) {
			return;
		}
		global $wpdb;
		$ours = 0;
		$db   = 0.0;
		foreach ( (array) $wpdb->queries as $q ) {
			$db += (float) $q[1];
			if ( str_contains( $q[0], 'oe_occurrences' ) ) {
				++$ours;
			}
		}
		printf(
			"\n<!-- oec-bench queries=%d occurrence_queries=%d db_ms=%.1f total_ms=%.1f -->",
			count( (array) $wpdb->queries ),
			$ours,
			$db * 1000,
			( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000
		);
	},
	PHP_INT_MAX
);
