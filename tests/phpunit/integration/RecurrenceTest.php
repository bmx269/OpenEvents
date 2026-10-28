<?php
/**
 * Recurrence module: expansion into occurrences, stable ids, editing scopes, REST.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\Event;
use OpenEvents\Frontend\EventDetails;
use OpenEvents\Modules\Recurrence\Module;
use OpenEvents\Modules\Recurrence\Recurrence;
use OpenEvents\Modules\Recurrence\Series;
use OpenEvents\Tests\TestCase;

final class RecurrenceTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		add_filter( 'openevents/calendar/cache', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		unset( $_GET['oe_date'] );
		parent::tear_down();
	}

	/**
	 * A repeating event starting on a date relative to today.
	 *
	 * @param array<string, mixed> $recurrence Recurrence meta.
	 * @param array<string, mixed> $meta       Other meta.
	 */
	private function series( array $recurrence, int $days = 1, array $meta = array() ): int {
		$day = current_datetime()->modify( "+{$days} days" )->format( 'Y-m-d' );
		return $this->event(
			array_merge(
				array(
					'_oe_start'           => "{$day} 19:00:00",
					'_oe_end'             => "{$day} 21:00:00",
					Recurrence::META_KEY  => array_merge( Recurrence::empty_meta(), $recurrence ),
				),
				$meta
			)
		);
	}

	/**
	 * @return array<string, int> Start (local) => occurrence id.
	 */
	private function ids( int $event_id ): array {
		$ids = array();
		foreach ( $this->rows( $event_id ) as $row ) {
			$ids[ $row->start_local ] = (int) $row->id;
		}
		ksort( $ids );
		return $ids;
	}

	public function test_weekly_rule_creates_one_row_per_date_with_the_same_times(): void {
		$id   = $this->series( array( 'rrule' => 'FREQ=WEEKLY;COUNT=4' ) );
		$rows = $this->rows( $id );

		$this->assertCount( 4, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( '19:00:00', substr( $row->start_local, 11 ) );
			$this->assertSame( '21:00:00', substr( $row->end_local, 11 ) );
		}
		$starts = array_keys( $this->ids( $id ) );
		$this->assertSame( '+7 days', '+' . ( new \DateTime( $starts[0] ) )->diff( new \DateTime( $starts[1] ) )->days . ' days' );
	}

	public function test_first_date_keeps_sequence_zero(): void {
		$id   = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=3' ) );
		$rows = $this->rows( $id );
		$this->assertSame( '0', (string) $rows[0]->sequence );
		$this->assertSame( get_post_meta( $id, '_oe_start', true ), $rows[0]->start_local );
	}

	public function test_skipping_a_date_keeps_the_other_ids(): void {
		$id     = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=5' ) );
		$before = $this->ids( $id );
		$skip   = array_keys( $before )[2];

		update_post_meta( $id, Recurrence::META_KEY, array_merge( Recurrence::empty_meta(), array( 'rrule' => 'FREQ=DAILY;COUNT=5', 'exdates' => array( substr( $skip, 0, 10 ) ) ) ) );
		$after = $this->ids( $id );

		unset( $before[ $skip ] );
		$this->assertSame( $before, $after );
	}

	public function test_changing_the_start_time_keeps_ids(): void {
		$id     = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=3' ) );
		$before = array_values( $this->ids( $id ) );

		$day = substr( (string) get_post_meta( $id, '_oe_start', true ), 0, 10 );
		update_post_meta( $id, '_oe_start', "{$day} 18:00:00" );
		update_post_meta( $id, '_oe_end', "{$day} 20:00:00" );

		$this->assertSame( $before, array_values( $this->ids( $id ) ) );
		$this->assertSame( '18:00:00', substr( (string) array_keys( $this->ids( $id ) )[1], 11 ) );
	}

	public function test_extra_dates_are_added(): void {
		$day = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$id  = $this->series( array( 'rdates' => array( "{$day} 10:00:00" ) ) );
		$this->assertSame( array( "{$day} 10:00:00" ), array_slice( array_keys( $this->ids( $id ) ), 1 ) );
		$this->assertSame( "{$day} 12:00:00", $this->rows( $id )[1]->end_local );
	}

	public function test_all_day_multi_day_dates_keep_their_length(): void {
		$day = current_datetime()->modify( '+1 day' )->format( 'Y-m-d' );
		$id  = $this->event(
			array(
				'_oe_start'          => "{$day} 00:00:00",
				'_oe_end'            => current_datetime()->modify( '+2 days' )->format( 'Y-m-d' ) . ' 00:00:00',
				'_oe_all_day'        => true,
				Recurrence::META_KEY => array( 'rrule' => 'FREQ=MONTHLY;COUNT=2' ),
			)
		);
		foreach ( $this->rows( $id ) as $row ) {
			$this->assertSame( '00:00:00', substr( $row->start_local, 11 ) );
			$this->assertSame( '23:59:59', substr( $row->end_local, 11 ) );
			$this->assertSame( 1, ( new \DateTime( substr( $row->start_local, 0, 10 ) ) )->diff( new \DateTime( substr( $row->end_local, 0, 10 ) ) )->days );
		}
	}

	public function test_open_ended_rules_stop_at_the_horizon(): void {
		$horizon = static fn() => '+3 weeks';
		add_filter( 'openevents/recurrence/horizon', $horizon );
		$id = $this->series( array( 'rrule' => 'FREQ=WEEKLY' ) );
		// It starts tomorrow, so the horizon runs from then: the start plus 3 weeks.
		$this->assertCount( 4, $this->rows( $id ) );

		// The daily job extends the series when the horizon moves.
		remove_filter( 'openevents/recurrence/horizon', $horizon );
		add_filter( 'openevents/recurrence/horizon', static fn() => '+6 weeks' );
		Module::extend_all();
		$this->assertCount( 7, $this->rows( $id ) );
	}

	public function test_invalid_rule_falls_back_to_a_single_date(): void {
		$id = $this->series( array( 'rrule' => 'FREQ=HOURLY' ) );
		$this->assertCount( 1, $this->rows( $id ) );
		$this->assertSame( Recurrence::empty_meta(), get_post_meta( $id, Recurrence::META_KEY, true ) );
	}

	public function test_listings_and_calendar_links_point_at_each_date(): void {
		$id    = $this->series( array( 'rrule' => 'FREQ=WEEKLY;COUNT=3' ) );
		$items = openevents_get_upcoming( 10, array( 'event_ids' => array( $id ) ) );

		$this->assertCount( 3, $items );
		$this->assertSame( get_permalink( $id ), openevents_occurrence_url( $items[0] ) );
		$this->assertSame( add_query_arg( 'oe_date', $items[1]->start->format( 'Y-m-d' ), get_permalink( $id ) ), openevents_occurrence_url( $items[1] ) );
	}

	public function test_event_page_shows_the_requested_date_and_the_rule(): void {
		$id    = $this->series( array( 'rrule' => 'FREQ=WEEKLY;COUNT=3' ) );
		$items = openevents_get_upcoming( 10, array( 'event_ids' => array( $id ) ) );

		$_GET['oe_date'] = $items[1]->start->format( 'Y-m-d' );
		$html            = EventDetails::render( $id );

		$this->assertStringContainsString( 'datetime="' . $items[1]->start->format( 'Y-m-d\TH:i' ), $html );
		$this->assertStringContainsString( 'Every week on ' . $items[0]->start->format( 'l' ) . ', 3 times', $html );
		// The other dates are listed, not the one shown.
		$this->assertStringContainsString( 'oe_date=' . $items[2]->start->format( 'Y-m-d' ), $html );
		$this->assertStringNotContainsString( 'oe_date=' . $items[1]->start->format( 'Y-m-d' ) . '"', $html );
	}

	public function test_detach_makes_one_date_its_own_event(): void {
		$id    = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=4' ), 1, array( '_oe_cost' => '12' ) );
		$term  = self::factory()->term->create( array( 'taxonomy' => \OpenEvents\Content\PostTypes::CATEGORY ) );
		wp_set_object_terms( $id, array( $term ), \OpenEvents\Content\PostTypes::CATEGORY );
		$items = openevents_get_upcoming( 10, array( 'event_ids' => array( $id ) ) );

		$new = Series::detach( Event::get( $id ), $items[2] );

		$this->assertIsInt( $new );
		$this->assertCount( 3, $this->rows( $id ) );
		$this->assertCount( 1, $this->rows( $new ) );
		$this->assertSame( $items[2]->start->format( 'Y-m-d H:i:s' ), get_post_meta( $new, '_oe_start', true ) );
		$this->assertSame( '12', get_post_meta( $new, '_oe_cost', true ) );
		$this->assertSame( $id, (int) get_post_meta( $new, Series::PARENT_KEY, true ) );
		$this->assertSame( array( $term ), wp_get_object_terms( $new, \OpenEvents\Content\PostTypes::CATEGORY, array( 'fields' => 'ids' ) ) );
	}

	public function test_split_ends_the_series_and_continues_it_in_a_new_event(): void {
		$id    = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=5' ) );
		$items = openevents_get_upcoming( 10, array( 'event_ids' => array( $id ) ) );

		$new = Series::split( Event::get( $id ), $items[2] );

		$this->assertIsInt( $new );
		$this->assertCount( 2, $this->rows( $id ) );
		$this->assertCount( 3, $this->rows( $new ) );
		$this->assertSame( 'FREQ=DAILY;COUNT=3', get_post_meta( $new, Recurrence::META_KEY, true )['rrule'] );
		$this->assertSame( 'FREQ=DAILY;UNTIL=' . $items[1]->start->format( 'Ymd' ), get_post_meta( $id, Recurrence::META_KEY, true )['rrule'] );
	}

	public function test_split_on_the_first_date_is_refused(): void {
		$id    = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=3' ) );
		$first = openevents()->occurrences()->for_event( $id );
		$this->assertWPError( Series::split( Event::get( $id ), $first ) );
	}

	public function test_rest_preview_returns_dates_and_a_description(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new \WP_REST_Request( 'POST', '/openevents/v1/recurrence/preview' );
		$request->set_body_params(
			array(
				'start'    => '2030-01-07 18:00:00',
				'end'      => '2030-01-07 19:30:00',
				'rrule'    => 'FREQ=MONTHLY;BYDAY=1MO;COUNT=3',
				'timezone' => 'America/Toronto',
			)
		);
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 'Every month on the first Monday, 3 times', $data['description'] );
		$this->assertSame(
			array(
				array( 'start' => '2030-01-07 18:00:00', 'end' => '2030-01-07 19:30:00' ),
				array( 'start' => '2030-02-04 18:00:00', 'end' => '2030-02-04 19:30:00' ),
				array( 'start' => '2030-03-04 18:00:00', 'end' => '2030-03-04 19:30:00' ),
			),
			$data['dates']
		);
		$this->assertFalse( $data['more'] );
	}

	public function test_rest_preview_rejects_a_bad_rule(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new \WP_REST_Request( 'POST', '/openevents/v1/recurrence/preview' );
		$request->set_body_params(
			array(
				'start' => '2030-01-07 18:00:00',
				'rrule' => 'FREQ=MINUTELY',
			)
		);
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_rest_skip_and_permissions(): void {
		$id    = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=3' ) );
		$items = openevents_get_upcoming( 10, array( 'event_ids' => array( $id ) ) );
		$route = "/openevents/v1/events/{$id}/occurrences/{$items[1]->id}/skip";

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $route ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $route ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $this->rows( $id ) );
	}

	public function test_rest_rejects_an_invalid_rule_on_save(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id      = $this->series( array( 'rrule' => 'FREQ=DAILY;COUNT=2' ) );
		$request = new \WP_REST_Request( 'POST', rest_get_route_for_post( $id ) );
		$request->set_body_params( array( 'meta' => array( Recurrence::META_KEY => array( 'rrule' => 'FREQ=DAILY;BYWEEKNO=3' ) ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'openevents_invalid_rrule', $response->get_data()['code'] );
	}
}
