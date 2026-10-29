<?php
/**
 * Series module: membership, series pages, and series passes.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Schema;
use OpenEvents\Content\Event;
use OpenEvents\Frontend\EventDetails;
use OpenEvents\Modules\Recurrence\Recurrence;
use OpenEvents\Modules\Series\Module as Series;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\Module as Tickets;
use OpenEvents\Tests\TestCase;

final class SeriesTest extends TestCase {

	private int $series;

	public function set_up(): void {
		parent::set_up();
		Series::register_meta();
		// SQLite reuses ids after each test's rollback; forget pages rendered by earlier tests.
		( new \ReflectionProperty( Series::class, 'rendered' ) )->setValue( null, array() );
		$this->series = self::factory()->post->create(
			array(
				'post_type'   => Series::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Film season',
			)
		);
		add_filter( 'openevents/calendar/cache', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		parent::tear_down();
	}

	/**
	 * An event in the series, $days from today.
	 */
	private function film( int $days, array $meta = array() ): int {
		$day = current_datetime()->modify( "{$days} days" )->format( 'Y-m-d' );
		$id  = $this->event(
			array_merge(
				array(
					'_oe_start'      => "{$day} 19:00:00",
					'_oe_end'        => "{$day} 21:00:00",
					Series::META_KEY => $this->series,
				),
				$meta
			)
		);
		$this->rows( $id );
		return $id;
	}

	public function test_events_link_to_their_series(): void {
		$id   = $this->film( 3 );
		$html = EventDetails::render( $id );
		$this->assertStringContainsString( '<dt>Series</dt>', $html );
		$this->assertStringContainsString( 'href="' . get_permalink( $this->series ) . '">Film season</a>', $html );
	}

	public function test_listings_can_be_limited_to_a_series(): void {
		$in  = $this->film( 3 );
		$out = $this->event( array( '_oe_start' => current_datetime()->modify( '+3 days' )->format( 'Y-m-d' ) . ' 10:00:00' ) );
		$this->rows( $out );

		$items = openevents_get_upcoming( 10, array( 'series' => $this->series ) );
		$this->assertSame( array( $in ), array_map( static fn( $o ) => $o->event_id, $items ) );
	}

	public function test_series_page_lists_upcoming_and_past_dates(): void {
		$this->film( 5 );
		$this->film( 12 );
		$this->film( -20 );

		$this->go_to( get_permalink( $this->series ) );
		the_post();
		$html = apply_filters( 'the_content', 'About the season.' );

		$this->assertStringContainsString( 'About the season.', $html );
		$this->assertSame( 3, substr_count( $html, '<article class="oe-event-card' ) );
		$this->assertStringContainsString( '<summary>1 past date</summary>', $html );
	}

	public function test_dates_split_off_a_repeating_event_stay_in_the_series(): void {
		$id    = $this->film( 2, array( Recurrence::META_KEY => array( 'rrule' => 'FREQ=WEEKLY;COUNT=3' ) ) );
		$items = openevents_get_upcoming( 10, array( 'event_ids' => array( $id ) ) );
		$new   = \OpenEvents\Modules\Recurrence\Series::split( Event::get( $id ), $items[1] );

		$this->assertSame( $this->series, (int) get_post_meta( $new, Series::META_KEY, true ) );
		$this->assertCount( 3, openevents_get_upcoming( 10, array( 'series' => $this->series ) ) );
	}

	public function test_series_passes_sell_until_the_last_date_starts(): void {
		$first = $this->film( 4 );
		$last  = $this->film( 30 );
		update_post_meta(
			$this->series,
			Tickets::META_KEY,
			array(
				array(
					'name'     => 'Season pass',
					'price'    => '0',
					'capacity' => 20,
				),
			)
		);
		$checkout = new Checkout();
		$types    = $checkout->ticket_types( $this->series );
		$this->assertCount( 1, $types, 'Passes sync into ticket types owned by the series.' );
		$pass = current( $types );
		$this->assertNull( $checkout->sale_state( $pass ), 'On sale while dates remain.' );

		$result = $checkout->place(
			$this->series,
			array(
				'name'    => 'Grace Hopper',
				'email'   => 'grace@example.com',
				'tickets' => array( $pass->id => 1 ),
				'ts'      => Guard::sign_timestamp( time() - 10 ),
				'ip'      => '198.51.100.12',
			)
		);
		$this->assertTrue( $result->ok, $result->message );

		// Once the last date has started, passes stop selling.
		foreach ( array( $first => '-2 days', $last => '-1 hour' ) as $id => $start ) {
			update_post_meta( $id, '_oe_start', current_datetime()->modify( $start )->format( 'Y-m-d H:i:s' ) );
			update_post_meta( $id, '_oe_end', current_datetime()->modify( $start . ' +2 hours' )->format( 'Y-m-d H:i:s' ) );
			$this->rows( $id );
		}
		$this->assertSame( 'event_started', $checkout->sale_state( $pass ) );
	}

	public function test_series_page_sells_passes(): void {
		$this->film( 4 );
		update_post_meta(
			$this->series,
			Tickets::META_KEY,
			array(
				array(
					'name'     => 'Season pass',
					'price'    => '0',
					'capacity' => 20,
				),
			)
		);
		$this->go_to( get_permalink( $this->series ) );
		the_post();
		$html = apply_filters( 'the_content', '' );

		$this->assertStringContainsString( 'class="oe-tickets"', $html );
		$this->assertStringContainsString( 'Season pass', $html );
		$this->assertStringContainsString( 'name="event_id" value="' . $this->series . '"', $html );
	}

	public function test_deleting_a_series_deletes_its_pass_holders(): void {
		global $wpdb;
		$this->film( 4 );
		update_post_meta(
			$this->series,
			Tickets::META_KEY,
			array(
				array(
					'name'     => 'Season pass',
					'price'    => '0',
					'capacity' => 20,
				),
			)
		);
		$pass = current( ( new Checkout() )->ticket_types( $this->series ) );
		( new Checkout() )->place(
			$this->series,
			array(
				'name'    => 'Grace Hopper',
				'email'   => 'grace@example.com',
				'tickets' => array( $pass->id => 1 ),
				'ts'      => Guard::sign_timestamp( time() - 10 ),
				'ip'      => '198.51.100.13',
			)
		);

		wp_delete_post( $this->series, true );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::attendees() . ' WHERE event_id = %d', $this->series ) ) ); // phpcs:ignore WordPress.DB
	}
}
