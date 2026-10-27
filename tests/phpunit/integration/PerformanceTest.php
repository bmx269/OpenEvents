<?php
/**
 * Performance budgets: rendering cost must not grow with the number of events.
 *
 * These guard the contract in docs/ARCHITECTURE.md ("Performance"). If one
 * fails, something reintroduced a per-event query (N+1).
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\PostTypes;
use OpenEvents\Frontend\CalendarView;
use OpenEvents\Occurrences\Repository;
use OpenEvents\Rest\OccurrencesController;
use OpenEvents\Tests\TestCase;

final class PerformanceTest extends TestCase {

	private int $venue;
	private int $organizer;
	/** @var int[] */
	private array $categories;

	public function set_up(): void {
		parent::set_up();
		// Measure the real query path, not the HTML fragment cache.
		add_filter( 'openevents/calendar/cache', '__return_false' );

		$this->venue      = self::factory()->post->create( array( 'post_type' => PostTypes::VENUE ) );
		$this->organizer  = self::factory()->post->create( array( 'post_type' => PostTypes::ORGANIZER ) );
		$this->categories = self::factory()->term->create_many( 3, array( 'taxonomy' => PostTypes::CATEGORY ) );
		foreach ( $this->categories as $i => $term ) {
			update_term_meta( $term, 'oe_color', array( '#ff0000', '#00ff00', '#0000ff' )[ $i ] );
		}
	}

	public function tear_down(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		unset( $_GET['oe_month'] );
		parent::tear_down();
	}

	/**
	 * Creates events this month, fully dressed (venue, organizer, category, image)
	 * so every lookup a template might do is exercised.
	 */
	private function seed( int $count ): void {
		// From tomorrow, so the same events appear in "upcoming" lists and REST ranges.
		$first = current_datetime()->modify( '+1 day' );
		for ( $i = 0; $i < $count; $i++ ) {
			$day = $first->modify( '+' . ( $i % 28 ) . ' days' )->format( 'Y-m-d' );
			$id  = $this->event(
				array(
					'_oe_start'         => "{$day} 10:00:00",
					'_oe_end'           => "{$day} 11:00:00",
					'_oe_venue_id'      => $this->venue,
					'_oe_organizer_ids' => array( $this->organizer ),
					'_oe_cost'          => '10',
				)
			);
			wp_set_object_terms( $id, array( $this->categories[ $i % 3 ] ), PostTypes::CATEGORY );
			$attachment = self::factory()->attachment->create( array( 'post_parent' => $id ) );
			set_post_thumbnail( $id, $attachment );
		}
		openevents()->occurrences()->query( array( 'per_page' => 1 ) ); // Flush syncs outside the measurement.
	}

	/**
	 * Clears caches so each measurement starts cold, as a real request would.
	 */
	private function cold(): void {
		wp_cache_flush();
		openevents()->occurrences()->forget_all();
		Repository::bump_cache();
	}

	private function cost( int $events, callable $render ): int {
		$this->seed( $events );
		$this->cold();
		return $this->count_queries( $render );
	}

	public function test_month_view_cost_is_independent_of_event_count(): void {
		$_GET['oe_month'] = current_datetime()->modify( '+1 day' )->format( 'Y-m' );
		$render           = static fn() => ( new CalendarView( array( 'views' => array( 'month' ) ) ) )->render();

		$few  = $this->cost( 5, $render );
		$many = $this->cost( 55, $render ); // 60 total.

		$this->assertSame( $few, $many, "Month view: {$few} queries for 5 events vs {$many} for 60." );
		$this->assertLessThanOrEqual( 15, $many, 'Month view query budget.' );
	}

	public function test_list_view_cost_is_independent_of_event_count(): void {
		$render = static fn() => ( new CalendarView(
			array(
				'views'   => array( 'list' ),
				'perPage' => 50,
			)
		) )->render();

		$few  = $this->cost( 5, $render );
		$many = $this->cost( 55, $render );

		$this->assertSame( $few, $many, "List view: {$few} queries for 5 events vs {$many} for 60." );
		$this->assertLessThanOrEqual( 15, $many, 'List view query budget.' );
	}

	public function test_rest_cost_is_independent_of_item_count(): void {
		$controller = new OccurrencesController( openevents()->occurrences() );
		$render     = static function () use ( $controller ): void {
			$request = new \WP_REST_Request( 'GET', '/openevents/v1/occurrences' );
			$request->set_param( 'per_page', 100 );
			$request->set_param( 'start', current_datetime()->modify( '+1 day' )->format( 'Y-m-d' ) );
			$request->set_param( 'end', current_datetime()->modify( '+30 days' )->format( 'Y-m-d' ) );
			$request->set_param( 'categories', array() );
			$request->set_param( 'venue', 0 );
			$request->set_param( 'event', 0 );
			$request->set_param( 'search', '' );
			$request->set_param( 'post_type', array() );
			$request->set_param( 'page', 1 );
			$controller->list_items( $request );
		};

		$few  = $this->cost( 5, $render );
		$many = $this->cost( 55, $render );

		$this->assertSame( $few, $many, "REST: {$few} queries for 5 items vs {$many} for 60." );
	}

	public function test_admin_dates_load_in_one_query(): void {
		$this->seed( 20 );
		$this->cold();
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::EVENT,
				'fields'         => 'ids',
				'posts_per_page' => 20,
			)
		);

		$queries = $this->count_queries( static fn() => openevents()->occurrences()->for_events( $ids ) );
		$this->assertSame( 1, $queries );

		$again = $this->count_queries(
			static function () use ( $ids ): void {
				foreach ( $ids as $id ) {
					openevents()->occurrences()->for_event( $id );
				}
			}
		);
		$this->assertSame( 0, $again, 'Per-row lookups are served from the batch.' );
	}

	public function test_month_query_uses_a_bounded_range(): void {
		$this->seed( 3 );
		$sql     = '';
		$capture = static function ( string $query ) use ( &$sql ): string {
			if ( str_contains( $query, 'oe_occurrences' ) && str_contains( $query, 'start_local' ) ) {
				$sql = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );
		$this->cold();
		( new CalendarView( array( 'views' => array( 'month' ) ) ) )->render();
		remove_filter( 'query', $capture );

		$this->assertStringContainsString( 'start_local BETWEEN', $sql );
		$this->assertStringNotContainsString( 'wp_posts', str_replace( 'wptests_posts', 'wp_posts', $sql ), 'Listings must not join wp_posts.' );
	}

	public function test_rendered_calendar_is_served_from_cache(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		$this->seed( 5 );
		$this->cold();
		$view = static fn() => ( new CalendarView( array( 'views' => array( 'month' ) ) ) )->render();

		$this->reset_instance_counter();
		$first = $view();
		// The instance counter is part of the key; rewind it as a new request would.
		$this->reset_instance_counter();
		$queries = $this->count_queries( static function () use ( $view, &$second ): void {
			$second = $view();
		} );

		$this->assertSame( $first, $second );
		$this->assertSame( 0, $queries );
	}

	public function test_cache_is_invalidated_by_edits(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		$this->seed( 1 );
		$this->cold();
		$id = get_posts(
			array(
				'post_type' => PostTypes::EVENT,
				'fields'    => 'ids',
			)
		)[0];
		$view = static fn() => ( new CalendarView( array( 'views' => array( 'month' ) ) ) )->render();
		$view();

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Renamed event',
			)
		);
		$this->reset_instance_counter();

		$this->assertStringContainsString( 'Renamed event', $view() );
	}

	private function reset_instance_counter(): void {
		$property = new \ReflectionProperty( CalendarView::class, 'instance' );
		$property->setValue( null, 0 );
	}
}
