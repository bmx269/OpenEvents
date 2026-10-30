<?php
/**
 * Waitlists for RSVPs and tickets.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Attendance\Waitlist;
use OpenEvents\Attendance\WaitlistEntry;
use OpenEvents\Attendance\WaitlistService;
use OpenEvents\Modules\Rsvp\Service as Rsvp;
use OpenEvents\Modules\Tickets\AbstractGateway;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\Gateways;
use OpenEvents\Modules\Tickets\Module as Tickets;
use OpenEvents\Tests\TestCase;

final class WaitlistTest extends TestCase {

	private WaitlistService $waitlist;

	public function set_up(): void {
		parent::set_up();
		$this->waitlist = new WaitlistService();
		update_option( AbstractGateway::OPTION, array( 'manual' => array( 'enabled' => true ) ) );
		Gateways::reset();
		reset_phpmailer_instance();
	}

	private function upcoming( array $meta = array() ): int {
		$day = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$id  = $this->event( array_merge( array( '_oe_start' => "{$day} 19:00:00" ), $meta ) );
		$this->rows( $id );
		return $id;
	}

	private function rsvp_event( int $capacity, bool $waitlist = true ): int {
		$id = $this->upcoming();
		update_post_meta( $id, Rsvp::META_KEY, array( 'enabled' => true, 'capacity' => $capacity, 'max_per_response' => 4, 'waitlist' => $waitlist ) );
		return $id;
	}

	private function respond( int $event, string $email, int $quantity = 1, string $status = 'going' ) {
		return ( new Rsvp() )->respond( $event, array( 'name' => ucfirst( strtok( $email, '@' ) ), 'email' => $email, 'quantity' => $quantity, 'status' => $status, 'ts' => Guard::sign_timestamp( time() - 10 ), 'ip' => '203.0.113.' . wp_rand( 1, 250 ) ) );
	}

	private function rsvp_type( int $event ) {
		return ( new TicketTypes() )->find( $event, Rsvp::TYPE_KEY );
	}

	private function last_email(): object {
		$mailer = tests_retrieve_phpmailer_instance();
		return $mailer->get_sent( count( $mailer->mock_sent ) - 1 );
	}

	private function token_from_last_email( string $param ): string {
		preg_match( '/' . $param . '=([a-f0-9]+)/', $this->last_email()->body, $m );
		return $m[1] ?? '';
	}

	public function test_full_rsvp_offers_the_waitlist_with_position(): void {
		$event = $this->rsvp_event( 2 );
		$this->respond( $event, 'a@example.com', 2 );

		$this->assertSame( 'full', $this->respond( $event, 'b@example.com' )->code, 'Going is refused when full.' );

		$joined = $this->respond( $event, 'b@example.com', 1, 'waitlist' );
		$this->assertSame( 'waitlisted', $joined->code );
		$this->assertStringContainsString( '#1', $joined->message );
		$this->assertStringContainsString( 'You’re #1 on the waitlist', $this->last_email()->body );

		$this->assertStringContainsString( '#2', $this->respond( $event, 'c@example.com', 1, 'waitlist' )->message );
	}

	public function test_waitlist_requires_opt_in_and_a_full_event(): void {
		$closed = $this->rsvp_event( 2, false );
		$this->respond( $closed, 'a@example.com', 2 );
		$this->assertSame( 'disabled', $this->respond( $closed, 'b@example.com', 1, 'waitlist' )->code );

		$open = $this->rsvp_event( 5 );
		$this->assertSame( 'invalid', $this->respond( $open, 'b@example.com', 1, 'waitlist' )->code, 'There’s room: book directly.' );
	}

	public function test_cancellation_promotes_the_next_person_who_fits(): void {
		$event = $this->rsvp_event( 2 );
		$this->respond( $event, 'a@example.com', 2 );
		$token = $this->token_from_last_email( 'oe_rsvp_token' );
		$this->respond( $event, 'big@example.com', 3, 'waitlist' );   // Needs 3: won't fit in 2.
		$this->respond( $event, 'small@example.com', 2, 'waitlist' ); // Fits.

		( new Rsvp() )->cancel( $token );

		$people = ( new Attendees() )->query( array( 'event_id' => $event, 'status' => 'going' ) )['items'];
		$this->assertSame( array( 'small@example.com', 'small@example.com' ), array_map( static fn( $a ) => $a->email, $people ) );
		$this->assertSame( 2, $this->rsvp_type( $event )->sold );
		$this->assertStringContainsString( 'A spot opened up', $this->last_email()->subject );
		$this->assertStringContainsString( 'oe_rsvp_token=', $this->last_email()->body, 'Promoted people get a change/cancel link.' );

		$big = ( new Waitlist() )->queue( $this->rsvp_type( $event )->id );
		$this->assertCount( 1, $big );
		$this->assertSame( 'big@example.com', $big[0]->email, 'The larger party keeps its place.' );
	}

	public function test_raising_capacity_fills_from_the_waitlist(): void {
		$event = $this->rsvp_event( 1 );
		$this->respond( $event, 'a@example.com' );
		$this->respond( $event, 'b@example.com', 1, 'waitlist' );

		update_post_meta( $event, Rsvp::META_KEY, array( 'enabled' => true, 'capacity' => 2, 'max_per_response' => 4, 'waitlist' => true ) );

		$this->assertSame( 2, ( new Attendees() )->counts( $event )['going'] );
	}

	public function test_same_email_cannot_take_over_a_waitlist_place(): void {
		$event = $this->rsvp_event( 1 );
		$this->respond( $event, 'a@example.com' );
		$this->respond( $event, 'b@example.com', 1, 'waitlist' );

		$again = $this->respond( $event, 'b@example.com', 4, 'waitlist' );
		$this->assertSame( 'exists', $again->code );
		$this->assertSame( 1, ( new Waitlist() )->queue( $this->rsvp_type( $event )->id )[0]->quantity );
		$this->assertStringContainsString( 'already on it', $this->last_email()->body );
	}

	public function test_leaving_the_waitlist(): void {
		$event = $this->rsvp_event( 1 );
		$this->respond( $event, 'a@example.com' );
		$this->respond( $event, 'b@example.com', 1, 'waitlist' );
		$token = $this->token_from_last_email( 'oe_waitlist' );

		$this->assertTrue( $this->waitlist->leave( $token )->ok );
		$this->assertSame( array(), ( new Waitlist() )->queue( $this->rsvp_type( $event )->id ) );
		$this->assertFalse( $this->waitlist->leave( $token )->ok, 'Only once.' );
	}

	// ── Tickets ───────────────────────────────────────────────────────────

	private function ticketed( int $capacity, string $price = '20' ): array {
		$event = $this->upcoming();
		update_post_meta( $event, Tickets::META_KEY, array( array( 'key' => 'ga', 'name' => 'General', 'price' => $price, 'capacity' => $capacity, 'max_per_order' => 4, 'waitlist' => true ) ) );
		return array( $event, ( new TicketTypes() )->find( $event, Tickets::KEY_PREFIX . 'ga' ) );
	}

	private function buy( int $event, int $type_id, int $quantity, array $extra = array() ) {
		return ( new Checkout() )->place( $event, array_merge( array( 'name' => 'Buyer', 'email' => 'buyer' . wp_rand() . '@example.com', 'tickets' => array( $type_id => $quantity ), 'gateway' => 'manual', 'ts' => Guard::sign_timestamp( time() - 10 ), 'ip' => '198.51.100.' . wp_rand( 1, 250 ) ), $extra ) );
	}

	public function test_refunded_tickets_are_held_for_the_waitlist_and_claimable(): void {
		list( $event, $type ) = $this->ticketed( 2 );
		$first                = $this->buy( $event, $type->id, 2 );
		$this->waitlist->join( ( new TicketTypes() )->get( $type->id ), array( 'name' => 'Wait', 'email' => 'wait@example.com', 'quantity' => 2 ) );

		( new Checkout() )->refund( ( new Orders() )->get( $first->order->id ) );

		$entry = ( new Waitlist() )->query( array( 'event_id' => $event ) )['items'][0];
		$this->assertSame( WaitlistEntry::OFFERED, $entry->status );
		$this->assertSame( 2, ( new TicketTypes() )->get( $type->id )->sold, 'Seats are held for the offer.' );
		$this->assertStringContainsString( 'being held for you', $this->last_email()->subject );
		$token = $this->token_from_last_email( 'oe_waitlist' );

		$stranger = $this->buy( $event, $type->id, 1 );
		$this->assertSame( 'full', $stranger->code, 'Nobody else can take held seats.' );

		$claim = $this->buy( $event, $type->id, 1, array( 'waitlist_token' => $token, 'email' => 'wait@example.com' ) );
		$this->assertTrue( $claim->ok, $claim->message );
		$this->assertSame( 4000, ( new Orders() )->get( $claim->order->id )->total_minor, 'The claim is exactly the held tickets.' );
		$this->assertSame( 2, ( new TicketTypes() )->get( $type->id )->sold, 'No double reservation.' );
		$this->assertSame( WaitlistEntry::CLAIMED, ( new Waitlist() )->get( $entry->id )->status );
		$this->assertSame( 'invalid', $this->buy( $event, $type->id, 1, array( 'waitlist_token' => $token ) )->code, 'Claim links work once.' );
	}

	public function test_unclaimed_offers_pass_to_the_next_person(): void {
		list( $event, $type ) = $this->ticketed( 1 );
		$first                = $this->buy( $event, $type->id, 1 );
		$repo                 = new TicketTypes();
		$this->waitlist->join( $repo->get( $type->id ), array( 'name' => 'One', 'email' => 'one@example.com', 'quantity' => 1 ) );
		$this->waitlist->join( $repo->get( $type->id ), array( 'name' => 'Two', 'email' => 'two@example.com', 'quantity' => 1 ) );

		( new Checkout() )->release( ( new Orders() )->get( $first->order->id ), Order::CANCELLED );
		$entries = ( new Waitlist() )->query( array( 'event_id' => $event ) )['items'];
		$this->assertSame( array( 'offered', 'waiting' ), array_map( static fn( $e ) => $e->status, $entries ) );

		global $wpdb;
		$wpdb->update( \OpenEvents\Attendance\Schema::waitlist(), array( 'offer_expires_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $entries[0]->id ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, $this->waitlist->expire_offers() );

		$entries = ( new Waitlist() )->query( array( 'event_id' => $event ) )['items'];
		$this->assertSame( array( 'expired', 'offered' ), array_map( static fn( $e ) => $e->status, $entries ) );
		$this->assertSame( 'two@example.com', $this->last_email()->to[0][0] );
		$this->assertSame( 1, $repo->get( $type->id )->sold, 'Still exactly one seat held.' );
	}

	public function test_abandoned_claim_checkout_releases_to_the_next_person(): void {
		list( $event, $type ) = $this->ticketed( 1 );
		$first                = $this->buy( $event, $type->id, 1 );
		$repo                 = new TicketTypes();
		$this->waitlist->join( $repo->get( $type->id ), array( 'name' => 'One', 'email' => 'one@example.com', 'quantity' => 1 ) );
		$this->waitlist->join( $repo->get( $type->id ), array( 'name' => 'Two', 'email' => 'two@example.com', 'quantity' => 1 ) );
		( new Checkout() )->release( ( new Orders() )->get( $first->order->id ), Order::CANCELLED );
		$token = $this->token_from_last_email( 'oe_waitlist' );

		$claim = $this->buy( $event, $type->id, 1, array( 'waitlist_token' => $token ) );
		( new Checkout() )->release( ( new Orders() )->get( $claim->order->id ), Order::CANCELLED );

		$this->assertSame( 'two@example.com', $this->last_email()->to[0][0], 'Cancelled claim passes the seat on.' );
		$this->assertSame( 1, $repo->get( $type->id )->sold );
	}

	public function test_joining_is_rate_limited_per_connection(): void {
		$event = $this->rsvp_event( 1 );
		$this->respond( $event, 'a@example.com' );
		$type = $this->rsvp_type( $event );
		$join = fn( int $n ) => $this->waitlist->join(
			$type,
			array(
				'name'  => 'Person ' . $n,
				'email' => "p{$n}@example.com",
				'ip'    => '198.51.100.7',
			)
		);
		for ( $n = 1; $n <= WaitlistService::RATE; $n++ ) {
			$this->assertTrue( $join( $n )->ok );
		}

		$this->assertSame( 'rate_limited', $join( 99 )->code );
		$this->assertSame( WaitlistService::RATE, $this->waitlist->waiting_count( $type->id ) );
	}

	public function test_processing_twice_does_not_double_promote(): void {
		$event = $this->rsvp_event( 1 );
		$this->respond( $event, 'a@example.com' );
		$token = $this->token_from_last_email( 'oe_rsvp_token' );
		$this->respond( $event, 'b@example.com', 1, 'waitlist' );
		( new Rsvp() )->cancel( $token );

		$this->assertSame( 0, $this->waitlist->process( $this->rsvp_type( $event )->id ), 'Nothing left to fill.' );
		$this->assertSame( 1, ( new Attendees() )->counts( $event )['going'] );
		$this->assertSame( 1, $this->rsvp_type( $event )->sold );
	}
}
