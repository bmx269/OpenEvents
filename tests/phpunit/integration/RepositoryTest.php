<?php
/**
 * Occurrence queries.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\PostTypes;
use OpenEvents\Occurrences\Repository;
use OpenEvents\Tests\TestCase;

final class RepositoryTest extends TestCase {

	private function day( int $offset, string $time = '10:00:00' ): string {
		return current_datetime()->modify( "{$offset} days" )->format( 'Y-m-d' ) . ' ' . $time;
	}

	private function simple( string $title, int $offset, array $meta = array(), array $post = array() ): int {
		return $this->event(
			array_merge(
				array(
					'_oe_start' => $this->day( $offset, '10:00:00' ),
					'_oe_end'   => $this->day( $offset, '11:00:00' ),
				),
				$meta
			),
			array_merge( array( 'post_title' => $title ), $post )
		);
	}

	public function test_upcoming_excludes_past_and_sorts_soonest_first(): void {
		$this->simple( 'Past', -3 );
		$this->simple( 'Later', 9 );
		$this->simple( 'Soon', 2 );

		$result = openevents_get_occurrences( array( 'ends_after' => Repository::now() ) );

		$this->assertSame( array( 'Soon', 'Later' ), $this->titles( $result ) );
	}

	public function test_past_sorts_newest_first(): void {
		$this->simple( 'Older', -9 );
		$this->simple( 'Recent', -2 );
		$this->simple( 'Future', 4 );

		$result = openevents_get_occurrences(
			array(
				'ends_before' => Repository::now(),
				'order'       => 'DESC',
			)
		);

		$this->assertSame( array( 'Recent', 'Older' ), $this->titles( $result ) );
	}

	public function test_unpublished_events_are_excluded(): void {
		$this->simple( 'Draft', 2, array(), array( 'post_status' => 'draft' ) );
		$this->simple( 'Private', 2, array(), array( 'post_status' => 'private' ) );
		$this->simple( 'Public', 2 );

		$this->assertSame( array( 'Public' ), $this->titles( openevents_get_occurrences( array( 'ends_after' => Repository::now() ) ) ) );
	}

	public function test_hidden_events_need_include_hidden(): void {
		$this->simple( 'Hidden', 2, array( '_oe_hide_from_listings' => true ) );

		$this->assertSame( array(), $this->titles( openevents_get_occurrences() ) );
		$this->assertSame( array( 'Hidden' ), $this->titles( openevents_get_occurrences( array( 'include_hidden' => true ) ) ) );
	}

	public function test_local_range_includes_events_that_overlap_it(): void {
		$this->event(
			array(
				'_oe_start' => '2030-04-28 10:00:00',
				'_oe_end'   => '2030-05-02 10:00:00',
			),
			array( 'post_title' => 'Spans in' )
		);
		$this->event(
			array(
				'_oe_start' => '2030-05-15 10:00:00',
				'_oe_end'   => '2030-05-15 11:00:00',
			),
			array( 'post_title' => 'Inside' )
		);
		$this->event(
			array(
				'_oe_start' => '2030-06-02 10:00:00',
				'_oe_end'   => '2030-06-02 11:00:00',
			),
			array( 'post_title' => 'After' )
		);

		$result = openevents_get_occurrences(
			array(
				'local_from' => '2030-05-01 00:00:00',
				'local_to'   => '2030-05-31 23:59:59',
				'per_page'   => -1,
			)
		);

		$this->assertSame( array( 'Spans in', 'Inside' ), $this->titles( $result ) );
	}

	public function test_long_events_still_found_by_bounded_range_scan(): void {
		// Starts 60 days before the range: only found if the scan floor accounts for duration.
		$this->event(
			array(
				'_oe_start'   => '2030-03-01 00:00:00',
				'_oe_end'     => '2030-05-10 00:00:00',
				'_oe_all_day' => true,
			),
			array( 'post_title' => 'Exhibition' )
		);

		$result = openevents_get_occurrences(
			array(
				'local_from' => '2030-05-01 00:00:00',
				'local_to'   => '2030-05-31 23:59:59',
				'per_page'   => -1,
			)
		);

		$this->assertSame( array( 'Exhibition' ), $this->titles( $result ) );
	}

	public function test_category_filter_includes_child_categories(): void {
		$parent = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY ) );
		$child  = self::factory()->term->create(
			array(
				'taxonomy' => PostTypes::CATEGORY,
				'parent'   => $parent,
			)
		);
		$a      = $this->simple( 'In child', 2 );
		$this->simple( 'Uncategorised', 3 );
		wp_set_object_terms( $a, array( $child ), PostTypes::CATEGORY );

		$this->assertSame( array( 'In child' ), $this->titles( openevents_get_occurrences( array( 'categories' => array( $parent ) ) ) ) );
	}

	public function test_venue_filter(): void {
		$venue = self::factory()->post->create( array( 'post_type' => PostTypes::VENUE ) );
		$this->simple( 'At venue', 2, array( '_oe_venue_id' => $venue ) );
		$this->simple( 'Elsewhere', 3 );

		$this->assertSame( array( 'At venue' ), $this->titles( openevents_get_occurrences( array( 'venue' => $venue ) ) ) );
	}

	public function test_search_matches_title_and_content(): void {
		$this->simple( 'Jazz night', 2 );
		$this->simple( 'Story time', 3, array(), array( 'post_content' => 'Bring your jazz hands' ) );
		$this->simple( 'Book club', 4 );

		$this->assertSame( array( 'Jazz night', 'Story time' ), $this->titles( openevents_get_occurrences( array( 'search' => 'jazz' ) ) ) );
	}

	public function test_pagination_totals_and_count_opt_out(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->simple( "E{$i}", $i );
		}

		$page = openevents_get_occurrences(
			array(
				'per_page' => 3,
				'page'     => 3,
			)
		);
		$this->assertSame( 7, $page['total'] );
		$this->assertSame( 3, $page['pages'] );
		$this->assertSame( array( 'E7' ), $this->titles( $page ) );

		$uncounted = openevents_get_occurrences(
			array(
				'per_page' => 3,
				'count'    => false,
			)
		);
		$this->assertSame( 3, $uncounted['total'], 'Without counting, total is the page size.' );
	}

	public function test_post_types_argument_and_disabled_types(): void {
		$this->settings( array( 'event_post_types' => array( 'post' ) ) );
		$this->simple( 'Event type', 2 );
		$this->simple( 'Blog event', 3, array(), array( 'post_type' => 'post' ) );

		$this->assertSame( array( 'Event type', 'Blog event' ), $this->titles( openevents_get_occurrences() ) );
		$this->assertSame( array( 'Blog event' ), $this->titles( openevents_get_occurrences( array( 'post_types' => array( 'post' ) ) ) ) );

		// Turning a type off hides its events without deleting anything.
		$this->settings( array( 'event_post_types' => array() ) );
		$this->assertSame( array( 'Event type' ), $this->titles( openevents_get_occurrences() ) );
	}

	public function test_for_event_prefers_next_unfinished_occurrence(): void {
		$past   = $this->simple( 'Was', -2 );
		$future = $this->simple( 'Will be', 2 );

		$this->assertSame( $this->day( -2, '10:00:00' ), openevents()->occurrences()->for_event( $past )->start->format( 'Y-m-d H:i:s' ) );
		$batch = openevents()->occurrences()->for_events( array( $past, $future, 999999 ) );
		$this->assertNotNull( $batch[ $future ] );
		$this->assertNull( $batch[999999] );
	}

	public function test_query_args_filter_applies_everywhere(): void {
		$this->simple( 'Keep', 2 );
		$this->simple( 'Drop', 3 );
		$only_keep = static fn( array $args ) => array_merge( $args, array( 'search' => 'Keep' ) );
		add_filter( 'openevents/occurrences/query_args', $only_keep );

		$this->assertSame( array( 'Keep' ), $this->titles( openevents_get_occurrences() ) );

		remove_filter( 'openevents/occurrences/query_args', $only_keep );
	}
}
