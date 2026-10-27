<?php
/**
 * WP-CLI commands.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Cli;

use OpenEvents\Content\PostTypes;
use OpenEvents\Installer;
use OpenEvents\Occurrences\Repository;
use OpenEvents\Occurrences\Sync;
use WP_CLI;

/**
 * Manage Open Events Calendar data.
 */
final class Command {

	/**
	 * Rebuilds the occurrences table from event data, in batches.
	 *
	 * Run after bulk imports done with syncing suspended, or to repair the table.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<size>]
	 * : Events per batch.
	 * ---
	 * default: 500
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp open-events rebuild --batch=1000
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		$start = microtime( true );
		$done  = Sync::instance()->rebuild_all(
			max( 1, (int) ( $assoc_args['batch'] ?? 500 ) ),
			static fn( int $done, int $total ) => WP_CLI::log( sprintf( '%d / %d', $done, $total ) )
		);
		WP_CLI::success( sprintf( 'Rebuilt %d events in %.1fs.', $done, microtime( true ) - $start ) );
	}

	/**
	 * Shows table size and how the main listing queries use indexes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp open-events stats
	 */
	public function stats(): void {
		global $wpdb;
		$table = Installer::occurrences_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		WP_CLI::log( sprintf( 'Occurrences: %d', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ) );
		WP_CLI::log( sprintf( 'Published:   %d', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'publish'" ) ) );
		WP_CLI::log( sprintf( 'Longest occurrence: %.1f days', Repository::max_duration() / DAY_IN_SECONDS ) );

		$now     = Repository::now();
		$month   = current_datetime()->modify( 'first day of this month' )->format( 'Y-m-d 00:00:00' );
		$floor   = gmdate( 'Y-m-d H:i:s', strtotime( $month . ' UTC' ) - Repository::max_duration() - DAY_IN_SECONDS );
		$queries = array(
			'upcoming' => "SELECT id FROM {$table} WHERE status = 'publish' AND end_utc >= '{$now}' AND hide_from_listings = 0 ORDER BY start_utc LIMIT 10",
			'month'    => "SELECT id FROM {$table} WHERE status = 'publish' AND start_local BETWEEN '{$floor}' AND DATE_ADD('{$month}', INTERVAL 42 DAY) AND end_local >= '{$month}'",
		);
		foreach ( $queries as $label => $sql ) {
			$plan = $wpdb->get_results( "EXPLAIN {$sql}", ARRAY_A );
			WP_CLI::log( "\n{$label}:" );
			if ( $plan ) {
				WP_CLI\Utils\format_items( 'table', $plan, array_keys( $plan[0] ) );
			}
		}
		// phpcs:enable
	}

	/**
	 * Generates sample events for load testing. Never run on production.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<n>]
	 * : Number of events.
	 * ---
	 * default: 5000
	 * ---
	 *
	 * [--days=<n>]
	 * : Spread events over this many days, centred on today.
	 * ---
	 * default: 1460
	 * ---
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function generate( array $args, array $assoc_args ): void {
		$count = max( 1, (int) ( $assoc_args['count'] ?? 5000 ) );
		$start = microtime( true );
		self::generate_events( $count, max( 1, (int) ( $assoc_args['days'] ?? 1460 ) ) );
		WP_CLI::success( sprintf( 'Generated %d events in %.1fs.', $count, microtime( true ) - $start ) );
	}

	/**
	 * Inserts events with syncing suspended, then rebuilds occurrences in batches.
	 * Public so test environments without WP-CLI (Playground) can call it.
	 */
	public static function generate_events( int $count, int $days ): void {
		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );
		Sync::instance()->suspend();

		$type  = PostTypes::EVENT;
		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$terms = is_array( $terms ) ? $terms : array();
		$today = current_datetime()->setTime( 0, 0 );

		for ( $i = 0; $i < $count; $i++ ) {
			$offset = wp_rand( -intdiv( $days, 2 ), intdiv( $days, 2 ) );
			$start  = $today->modify( "{$offset} days" )->setTime( wp_rand( 9, 20 ), 0 === wp_rand( 0, 1 ) ? 0 : 30 );
			$end    = $start->modify( '+' . wp_rand( 1, 3 ) . ' hours' );
			$id     = wp_insert_post(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'post_title'   => sprintf( 'Generated event %d', $i + 1 ),
					'post_excerpt' => 'Load-test event.',
					'meta_input'   => array(
						'_oe_start' => $start->format( 'Y-m-d H:i:s' ),
						'_oe_end'   => $end->format( 'Y-m-d H:i:s' ),
					),
				)
			);
			if ( $terms && ! is_wp_error( $id ) ) {
				wp_set_object_terms( $id, array( (int) $terms[ array_rand( $terms ) ] ), PostTypes::CATEGORY );
			}
			if ( 0 === $i % 500 ) {
				wp_cache_flush_runtime();
			}
		}

		Sync::instance()->resume();
		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		Sync::instance()->rebuild_all();
	}
}
