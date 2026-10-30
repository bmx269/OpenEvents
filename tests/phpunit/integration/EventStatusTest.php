<?php
/**
 * Event status: cancelled and postponed events and dates.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Guard;
use OpenEvents\Content\EventStatus;
use OpenEvents\Frontend\CalendarView;
use OpenEvents\Frontend\EventDetails;
use OpenEvents\Import\Tec;
use OpenEvents\Modules\ICal\Writer;
use OpenEvents\Modules\Recurrence\Recurrence;
use OpenEvents\Modules\Rsvp\Service as Rsvp;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Tests\TestCase;

final class EventStatusTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
	}

	public function tear_down(): void {
		unset( $_GET['oe_occurrence'] );
		parent::tear_down();
	}

	/**
	 * An event in 5 days; weekly for three dates when $repeats.
	 */
	private function upcoming( bool $repeats = false ): int {
		$day  = current_datetime()->modify( '+5 days' )->format( 'Y-m-d' );
		$meta = array(
			'_oe_start' => "{$day} 19:00:00",
			'_oe_end'   => "{$day} 21:00:00",
		);
		if ( $repeats ) {
			$meta[ Recurrence::META_KEY ] = array_merge( Recurrence::empty_meta(), array( 'rrule' => 'FREQ=WEEKLY;COUNT=3' ) );
		}
		$id = $this->event( $meta, array( 'post_title' => 'Storytime' ) );
		update_post_meta( $id, Rsvp::META_KEY, array( 'enabled' => true ) );
		return $id;
	}

	/** @return array<string, string> Local start => event_status. */
	private function statuses( int $event_id ): array {
		$out = array();
		foreach ( $this->rows( $event_id ) as $row ) {
			$out[ $row->start_local ] = $row->event_status;
		}
		ksort( $out );
		return $out;
	}

	public function test_sanitize_dates(): void {
		$this->assertSame(
			array(
				array(
					'date'   => '2030-05-01',
					'status' => 'postponed',
					'reason' => 'Storm',
				),
				array(
					'date'   => '2030-05-08',
					'status' => 'cancelled',
					'reason' => '',
				),
			),
			EventStatus::sanitize_dates(
				array(
					array(
						'date'   => '2030-05-08',
						'status' => 'cancelled',
					),
					array(
						'date'   => '2030-05-01',
						'status' => 'postponed',
						'reason' => '<b>Storm</b>',
					),
					array(
						'date'   => '2030-05-15',
						'status' => 'scheduled',
					),
					array(
						'date'   => 'soon',
						'status' => 'cancelled',
					),
				)
			),
			'Valid dates only, in order, without "scheduled" entries; reasons are plain text.'
		);
	}

	public function test_cancelling_the_event_marks_every_date_and_closes_bookings(): void {
		$id = $this->upcoming( true );
		$this->assertSame( array( 'scheduled' ), array_values( array_unique( $this->statuses( $id ) ) ) );

		$this->assertTrue( EventStatus::set( $id, EventStatus::CANCELLED, 'The library is closed for repairs.' ) );
		$this->assertSame( array( 'cancelled' ), array_values( array_unique( $this->statuses( $id ) ) ) );

		$result = ( new Rsvp() )->respond(
			$id,
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'ts'    => Guard::sign_timestamp( time() - 10 ),
				'ip'    => '203.0.113.9',
			)
		);
		$this->assertSame( 'closed', $result->code );
		$this->assertSame( 'This event has been cancelled.', $result->message );

		update_post_meta(
			$id,
			\OpenEvents\Modules\Tickets\Module::META_KEY,
			array(
				array(
					'key'   => 'seat',
					'name'  => 'Seat',
					'price' => '0',
				),
			)
		);
		$checkout = new Checkout();
		$type     = current( $checkout->ticket_types( $id ) );
		$this->assertSame( 'cancelled', $checkout->sale_state( $type ) );

		$html = EventDetails::render( $id );
		$this->assertStringContainsString( '<dt>Status</dt><dd><span class="oe-status is-cancelled">Cancelled</span> The library is closed for repairs.</dd>', $html );
		$this->assertStringContainsString( 'This event has been cancelled.', $html );

		$occurrence = openevents()->occurrences()->for_event( $id );
		$card       = CalendarView::list_item( $occurrence );
		$this->assertStringContainsString( 'oe-event-card is-called-off', $card );
		$this->assertStringContainsString( '<span class="oe-status is-cancelled">Cancelled</span>', $card );

		$ics = Writer::calendar( array( $occurrence ) );
		$this->assertStringContainsString( "STATUS:CANCELLED\r\n", $ics );
		$this->assertStringContainsString( 'SUMMARY:Cancelled: Storytime', $ics );

		$request = new \WP_REST_Request( 'GET', '/openevents/v1/occurrences' );
		$request->set_param( 'event', $id );
		$item = rest_do_request( $request )->get_data()[0];
		$this->assertSame( 'cancelled', $item['status'] );
		$this->assertSame( 'The library is closed for repairs.', $item['status_reason'] );

		$this->assertTrue( EventStatus::set( $id, EventStatus::SCHEDULED ) );
		$this->assertSame( array( 'scheduled' ), array_values( array_unique( $this->statuses( $id ) ) ), 'Back on.' );
		$this->assertSame( '', get_post_meta( $id, '_oe_status_reason', true ) );
	}

	public function test_one_date_can_be_called_off_on_its_own(): void {
		$id    = $this->upcoming( true );
		$dates = array_keys( $this->statuses( $id ) );
		$day   = substr( $dates[1], 0, 10 );

		$this->assertTrue( EventStatus::set( $id, EventStatus::POSTPONED, 'Moved because of the storm.', $day ) );
		$this->assertSame( array( 'scheduled', 'postponed', 'scheduled' ), array_values( $this->statuses( $id ) ) );

		$second = openevents()->occurrences()->on_date( $id, $day );
		$this->assertSame( 'Moved because of the storm.', EventStatus::reason( $second ) );
		$service = new Rsvp();
		$this->assertSame( 'postponed', $service->closed_reason( $id, $service->ticket_type( $id, $second->sequence ) ) );
		$this->assertNull( $service->closed_reason( $id, $service->ticket_type( $id ) ), 'Other dates stay open.' );

		$_GET['oe_occurrence'] = $day;
		$this->assertStringContainsString( 'is-postponed">Postponed</span> Moved because of the storm.', EventDetails::render( $id ) );

		$this->assertInstanceOf( \WP_Error::class, EventStatus::set( $id, EventStatus::CANCELLED, '', '2001-01-01' ), 'Only the event’s own dates.' );
		$this->assertTrue( EventStatus::set( $id, EventStatus::SCHEDULED, '', $day ) );
		$this->assertSame( array(), get_post_meta( $id, '_oe_date_status', true ) );
		$this->assertSame( array( 'scheduled' ), array_values( array_unique( $this->statuses( $id ) ) ) );
	}

	public function test_ability_needs_edit_rights(): void {
		$id      = $this->upcoming();
		$ability = wp_get_ability( 'openevents/set-event-status' );
		$this->assertNotNull( $ability );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $ability->execute( array( 'event_id' => $id, 'status' => 'cancelled' ) ) );
		$this->assertSame( array( 'scheduled' ), array_values( $this->statuses( $id ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$result = $ability->execute(
			array(
				'event_id' => $id,
				'status'   => 'cancelled',
				'reason'   => 'Snow day.',
			)
		);
		$this->assertSame( 'cancelled', $result['status'] );
		$this->assertSame( array( 'cancelled' ), array_values( $this->statuses( $id ) ) );
	}

	/**
	 * @dataProvider tec_statuses
	 */
	public function test_tec_status_import( string $tec, string $title, string $expected ): void {
		$this->assertSame( $expected, Tec::status( $tec, $title ) );
	}

	public static function tec_statuses(): array {
		return array(
			'TEC canceled'      => array( 'canceled', 'Storytime', 'cancelled' ),
			'TEC postponed'     => array( 'postponed', 'Concert', 'postponed' ),
			'TEC scheduled'     => array( 'scheduled', 'Storytime', 'scheduled' ),
			'bracketed title'   => array( '', '[CANCELLED] Storytime', 'cancelled' ),
			'US spelling'       => array( '', 'Canceled: Book Club', 'cancelled' ),
			'postponed title'   => array( '', 'POSTPONED - Concert', 'postponed' ),
			'word in the title' => array( '', 'Storytime (cancelled last week)', 'scheduled' ),
		);
	}
}
