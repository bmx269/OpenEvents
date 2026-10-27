<?php
/**
 * Shared test helpers.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests;

use OpenEvents\Installer;
use OpenEvents\Occurrences\Repository;
use OpenEvents\Occurrences\Sync;
use OpenEvents\Settings;

abstract class TestCase extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// WP_UnitTestCase::tear_down() unregisters every meta key after each test;
		// restore the plugin's registrations (sanitisers, REST exposure).
		( new \OpenEvents\Content\Meta( openevents()->settings() ) )->register_meta();
		\OpenEvents\Frontend\CategoryColors::register_meta();
		update_option( 'timezone_string', 'America/Vancouver' );
		update_option( 'start_of_week', 1 );
		delete_option( Settings::OPTION );
		delete_option( Sync::MAX_DURATION_OPTION );
		openevents()->occurrences()->forget_all();
		Repository::bump_cache();
	}

	/**
	 * Creates a published event (or any post type) with schedule meta.
	 *
	 * @param array<string, mixed> $meta Meta, e.g. _oe_start/_oe_end.
	 * @param array<string, mixed> $post wp_insert_post() overrides.
	 */
	protected function event( array $meta = array(), array $post = array() ): int {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => Settings::EVENT_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'Event ' . wp_generate_password( 6, false ),
				),
				$post
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Raw occurrence rows for an event, after pending syncs run.
	 *
	 * @return array<int, object>
	 */
	protected function rows( int $event_id ): array {
		global $wpdb;
		Sync::instance()->flush();
		$table = Installer::occurrences_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY sequence", $event_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param array<string, mixed> $settings Settings to merge over defaults.
	 */
	protected function settings( array $settings ): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), $settings ) );
	}

	/**
	 * Titles of the events in a query result, in order.
	 *
	 * @param array{items: \OpenEvents\Occurrences\Occurrence[]} $result Query result.
	 * @return string[]
	 */
	protected function titles( array $result ): array {
		return array_map( static fn( $o ) => get_the_title( $o->event_id ), $result['items'] );
	}

	/**
	 * Number of database queries a callback runs.
	 */
	protected function count_queries( callable $callback ): int {
		global $wpdb;
		$before = $wpdb->num_queries;
		$callback();
		return $wpdb->num_queries - $before;
	}
}
