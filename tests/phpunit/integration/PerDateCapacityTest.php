<?php
/**
 * Per-date RSVP and ticket capacity for repeating events.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Schema;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Attendance\Waitlist;
use OpenEvents\Content\Event;
use OpenEvents\Modules\Recurrence\Recurrence;
use OpenEvents\Modules\Rsvp\Form;
use OpenEvents\Modules\Rsvp\Service as Rsvp;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Tests\TestCase;

final class PerDateCapacityTest extends TestCase {

	private int $event;

	/** @var array<int, int> Sequences in date order. */
	private array $sequences;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$this->event     = $this->weekly( 3 );
		$this->sequences = $this->sequences( $this->event );
		update_post_meta(
			$this->event,
			Rsvp::META_KEY,
			array(
				'enabled'          => true,
				'capacity'         => 2,
				'max_per_response' => 2,
				'waitlist'         => true,
			)
		);
	}

	/** A weekly event of three dates, the first $days from today. */
	private function weekly( int $days ): int {
		$day = current_datetime()->modify( "{$days} days" )->format( 'Y-m-d' );
		return $this->event(
			array(
				'_oe_start'          => "{$day} 19:00:00",
				'_oe_end'            => "{$day} 21:00:00",
				Recurrence::META_KEY => array_merge( Recurrence::empty_meta(), array( 'rrule' => 'FREQ=WEEKLY;COUNT=3' ) ),
			)
		);
	}

	/** @return int[] */
	private function sequences( int $event_id ): array {
		$sequences = array_map( static fn( $row ) => (int) $row->sequence, $this->rows( $event_id ) );
		sort( $sequences );
		return $sequences;
	}

	private function respond( string $email, array $input = array(), ?int $event = null ) {
		return ( new Rsvp() )->respond(
			$event ?? $this->event,
			array_merge(
				array(
					'name'     => ucfirst( strtok( $email, '@' ) ),
					'email'    => $email,
					'status'   => 'going',
					'quantity' => 1,
					'ts'       => Guard::sign_timestamp( time() - 10 ),
					'ip'       => '203.0.113.' . wp_rand( 1, 250 ),
				),
				$input
			)
		);
	}

	private function last_email(): object {
		$mailer = tests_retrieve_phpmailer_instance();
		return $mailer->get_sent( count( $mailer->mock_sent ) - 1 );
	}

	private function type_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %d', Schema::ticket_types(), $this->event ) ); // phpcs:ignore WordPress.DB
	}

	public function test_each_date_gets_its_own_row_once(): void {
		$types = new TicketTypes();
		$base  = $types->find( $this->event, Rsvp::TYPE_KEY );
		$this->assertSame( 0, $base->sequence );

		$second = $types->for_date( $base, $this->sequences[1] );
		$this->assertNotSame( $base->id, $second->id );
		$this->assertSame( $this->sequences[1], $second->sequence );
		$this->assertSame( 2, $second->capacity, 'Copied from the event’s own date.' );
		$this->assertSame( 0, $second->sold );
		$this->assertSame( $second->id, $types->for_date( $base, $this->sequences[1] )->id, 'Created once.' );
		$this->assertSame( $base->id, $types->for_date( $base, 0 )->id );
		$this->assertSame( $this->sequences[1], TicketTypes::occurrence( $second )->sequence );
	}

	public function test_settings_changes_reach_every_date(): void {
		$types  = new TicketTypes();
		$second = $types->for_date( $types->find( $this->event, Rsvp::TYPE_KEY ), $this->sequences[1] );
		$types->reserve( $second->id, 1 );

		update_post_meta( $this->event, Rsvp::META_KEY, array_merge( Rsvp::config( $this->event ), array( 'capacity' => 5 ) ) );

		$second = $types->get( $second->id );
		$this->assertSame( 5, $second->capacity );
		$this->assertSame( 1, $second->sold, 'Seats taken are kept.' );
	}

	public function test_upgrade_drops_the_old_unique_key(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB
		$wpdb->query( 'CREATE UNIQUE INDEX event_type ON ' . Schema::ticket_types() . ' (event_id, type_key)' );
		Schema::install();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', Schema::ticket_types(), 'event_type' ) ) );
		// phpcs:enable
		$types = new TicketTypes();
		$this->assertNotNull( $types->for_date( $types->find( $this->event, Rsvp::TYPE_KEY ), $this->sequences[2] ) );
	}

	public function test_rsvp_capacity_is_per_date(): void {
		$second = array( 'sequence' => $this->sequences[1] );
		$this->assertSame( 'going', $this->respond( 'a@example.com', $second + array( 'quantity' => 2 ) )->code );
		$this->assertSame( 'full', $this->respond( 'b@example.com', $second )->code );
		$this->assertSame( 'going', $this->respond( 'b@example.com' )->code, 'The first date still has room.' );
		$this->assertSame( 'going', $this->respond( 'a@example.com', array( 'sequence' => $this->sequences[2] ) )->code, 'The same person can come on another date.' );

		$types = new TicketTypes();
		$base  = $types->find( $this->event, Rsvp::TYPE_KEY );
		$this->assertSame( 1, $base->sold );
		$this->assertSame( 2, $types->for_date( $base, $this->sequences[1] )->sold );
		$this->assertSame( 1, ( new Attendees() )->counts( $this->event, $types->for_date( $base, $this->sequences[2] )->id )['going'] );
	}

	public function test_a_date_not_on_the_schedule_is_refused_without_creating_rows(): void {
		$before = $this->type_count();

		$this->assertSame( 'disabled', $this->respond( 'a@example.com', array( 'sequence' => $this->sequences[2] + 8 ) )->code );
		$this->assertSame( array(), ( new Checkout() )->ticket_types( $this->event, false, 999999 ) );
		$this->assertSame( $before, $this->type_count() );
	}

	public function test_a_private_link_keeps_its_date(): void {
		$this->respond( 'a@example.com', array( 'sequence' => $this->sequences[1] ) );
		preg_match( '/oe_rsvp_token=([a-f0-9]+)/', $this->last_email()->body, $m );
		$token = $m[1];

		$this->assertStringContainsString( 'oe_occurrence=', $this->last_email()->body, 'Links go to the booked date.' );
		$occurrence = openevents()->occurrences()->by_sequence( $this->event, $this->sequences[1] );
		$this->assertStringContainsString( \OpenEvents\Frontend\Formatter::range( $occurrence ), $this->last_email()->body );

		// Changing the response from another date's page keeps it on its own date.
		$result = $this->respond( 'a@example.com', array( 'token' => $token, 'quantity' => 2, 'sequence' => 0 ) );
		$this->assertTrue( $result->ok, $result->message );
		$people = ( new Attendees() )->group( $result->group );
		$this->assertSame( $this->sequences[1], ( new TicketTypes() )->get( $people[0]->ticket_type_id )->sequence );
		$this->assertSame( 0, ( new TicketTypes() )->find( $this->event, Rsvp::TYPE_KEY )->sold );
	}

	public function test_dates_close_on_their_own(): void {
		$event = $this->weekly( -8 ); // Dates 8 and 1 days ago, and in 6 days.
		update_post_meta( $event, Rsvp::META_KEY, array( 'enabled' => true ) );
		$sequences = $this->sequences( $event );
		$service   = new Rsvp();

		$this->assertSame( 'ended', $service->closed_reason( $event, $service->ticket_type( $event ) ) );
		$this->assertSame( 'ended', $service->closed_reason( $event, $service->ticket_type( $event, $sequences[1] ) ) );
		$this->assertNull( $service->closed_reason( $event, $service->ticket_type( $event, $sequences[2] ) ) );
		$this->assertSame( 'closed', $this->respond( 'a@example.com', array(), $event )->code );
		$this->assertSame( 'going', $this->respond( 'a@example.com', array( 'sequence' => $sequences[2] ), $event )->code );
	}

	public function test_waitlists_are_per_date(): void {
		$second = array( 'sequence' => $this->sequences[1] );
		$this->respond( 'a@example.com', $second + array( 'quantity' => 2 ) );
		preg_match( '/oe_rsvp_token=([a-f0-9]+)/', $this->last_email()->body, $m );
		$this->respond( 'first@example.com', array( 'quantity' => 2 ) ); // Fills the first date too.
		$first_token = preg_match( '/oe_rsvp_token=([a-f0-9]+)/', $this->last_email()->body, $f ) ? $f[1] : '';

		$this->assertSame( 'waitlisted', $this->respond( 'w@example.com', $second + array( 'status' => 'waitlist' ) )->code );
		$this->assertStringContainsString( 'oe_occurrence=', $this->last_email()->body );

		( new Rsvp() )->cancel( $first_token );
		$this->assertSame( 1, ( new Waitlist() )->query( array( 'event_id' => $this->event, 'status' => 'waiting' ) )['total'], 'A place on another date doesn’t promote anyone.' );

		( new Rsvp() )->cancel( $m[1] );
		$promoted = ( new Attendees() )->query( array( 'event_id' => $this->event, 'search' => 'w@example.com' ) )['items'];
		$this->assertCount( 1, $promoted );
		$this->assertSame( 'going', $promoted[0]->status );
		$this->assertSame( $this->sequences[1], ( new TicketTypes() )->get( $promoted[0]->ticket_type_id )->sequence );
	}

	public function test_form_says_which_date_and_posts_it(): void {
		$service = new Rsvp();
		$type    = $service->ticket_type( $this->event, $this->sequences[1] );
		$html    = Form::render( Event::get( $this->event ), $type, $service );

		$occurrence = openevents()->occurrences()->by_sequence( $this->event, $this->sequences[1] );
		$this->assertStringContainsString( '<p class="oe-rsvp__date">For <time datetime="', $html );
		$this->assertStringContainsString( esc_html( \OpenEvents\Frontend\Formatter::range( $occurrence ) ), $html );
		$this->assertStringContainsString( '<input type="hidden" name="sequence" value="' . $this->sequences[1] . '">', $html );
		$this->assertStringContainsString( '2 spots left', $html, 'Counts are for that date.' );
	}

	public function test_tickets_are_per_date(): void {
		update_post_meta(
			$this->event,
			\OpenEvents\Modules\Tickets\Module::META_KEY,
			array(
				array(
					'key'      => 'seat',
					'name'     => 'Seat',
					'price'    => '0',
					'capacity' => 1,
				),
			)
		);
		$checkout = new Checkout();
		$second   = $checkout->ticket_types( $this->event, false, $this->sequences[1] );
		$this->assertCount( 1, $second );
		$type = reset( $second );
		$this->assertSame( $this->sequences[1], $type->sequence );

		$order = fn( string $email, int $sequence, int $type_id ) => $checkout->place(
			$this->event,
			array(
				'name'     => 'Buyer',
				'email'    => $email,
				'sequence' => $sequence,
				'tickets'  => array( $type_id => 1 ),
				'ts'       => Guard::sign_timestamp( time() - 10 ),
				'ip'       => '198.51.100.' . wp_rand( 1, 250 ),
			)
		);
		$this->assertTrue( $order( 'a@example.com', $this->sequences[1], $type->id )->ok );
		$this->assertSame( 'sold_out', $checkout->sale_state( ( new TicketTypes() )->get( $type->id ) ) );
		$this->assertStringContainsString( 'oe_occurrence=', $this->last_email()->body );

		$result = $order( 'b@example.com', $this->sequences[1], $type->id );
		$this->assertFalse( $result->ok, 'That date is sold out.' );

		$this->assertSame( 'invalid', $order( 'b@example.com', $this->sequences[2], $type->id )->code, 'Another date’s ticket ids don’t carry over.' );
		$third = $checkout->ticket_types( $this->event, false, $this->sequences[2] );
		$third = reset( $third );
		$this->assertTrue( $order( 'b@example.com', $this->sequences[2], $third->id )->ok, 'Another date has its own seats.' );
	}

	public function test_attendee_list_shows_and_filters_dates(): void {
		$this->respond( 'a@example.com', array( 'sequence' => $this->sequences[1] ) );
		$this->respond( 'b@example.com' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new \WP_REST_Request( 'GET', '/openevents/v1/attendees' );
		$request->set_param( 'event', $this->event );
		$request->set_param( 'sequence', $this->sequences[1] );
		$items = rest_do_request( $request )->get_data();
		$this->assertSame( array( 'a@example.com' ), array_column( $items, 'email' ) );
		$occurrence = openevents()->occurrences()->by_sequence( $this->event, $this->sequences[1] );
		$this->assertSame( \OpenEvents\Frontend\Formatter::range( $occurrence ), $items[0]['date'] );

		$request = new \WP_REST_Request( 'GET', '/openevents/v1/attendees/dates' );
		$request->set_param( 'event', $this->event );
		$this->assertSame( array( 0, $this->sequences[1] ), array_column( rest_do_request( $request )->get_data(), 'value' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}
}
