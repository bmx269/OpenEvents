<?php
/**
 * Passes, QR codes, and door check-in.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendee;
use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Pass;
use OpenEvents\Attendance\QrCode;
use OpenEvents\Modules\Rsvp\Service as Rsvp;
use OpenEvents\Tests\TestCase;

final class CheckInTest extends TestCase {

	private int $event;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$day         = current_datetime()->format( 'Y-m-d' );
		$this->event = $this->event(
			array(
				'_oe_start' => "{$day} 23:00:00",
				'_oe_end'   => "{$day} 23:59:00",
			),
			array( 'post_title' => 'Tonight' )
		);
		update_post_meta(
			$this->event,
			Rsvp::META_KEY,
			array(
				'enabled'          => true,
				'max_per_response' => 2,
			)
		);
	}

	/** @return Attendee[] */
	private function rsvp( int $people = 1 ): array {
		$result = ( new Rsvp() )->respond(
			$this->event,
			array(
				'name'     => 'Ada Lovelace',
				'email'    => 'ada@example.com',
				'quantity' => $people,
				'ts'       => Guard::sign_timestamp( time() - 10 ),
				'ip'       => '203.0.113.' . wp_rand( 1, 250 ),
			)
		);
		$this->assertTrue( $result->ok, $result->message );
		return ( new Attendees() )->group( $result->group );
	}

	private function staff(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	private function request( string $method, array $params ) {
		$request = new \WP_REST_Request( $method, '/openevents/v1/check-in' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	public function test_qr_matrix(): void {
		$matrix = QrCode::matrix( 'HELLO' );
		$this->assertCount( 21, $matrix, 'Version 1 is 21 modules square.' );
		// Finder pattern corners: dark ring, light ring, dark centre.
		foreach ( array( array( 0, 0 ), array( 0, 14 ), array( 14, 0 ) ) as list( $y, $x ) ) {
			$this->assertTrue( $matrix[ $y ][ $x ] );
			$this->assertFalse( $matrix[ $y + 1 ][ $x + 1 ] );
			$this->assertTrue( $matrix[ $y + 3 ][ $x + 3 ] );
		}
		$this->assertCount( 57, QrCode::matrix( str_repeat( 'x', 213 ) ), 'Version 10 holds 213 bytes.' );
		$this->assertNull( QrCode::matrix( str_repeat( 'x', 214 ) ) );

		$svg = QrCode::svg( 'HELLO', 'Code "X"' );
		$this->assertStringStartsWith( '<svg class="oe-qr"', $svg );
		$this->assertStringContainsString( 'role="img" aria-label="Code &quot;X&quot;"', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 29 29"', $svg, 'Four-module quiet zone.' );
	}

	public function test_pass_links_are_signed(): void {
		$attendee = $this->rsvp()[0];
		$url      = Pass::url( $attendee );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( $attendee->id, Pass::attendee( $query['oe_pass'] )->id );
		$this->assertNull( Pass::attendee( $attendee->uuid . '.' . str_repeat( '0', 32 ) ), 'A made-up signature.' );
		$this->assertNull( Pass::attendee( 'nonsense' ) );

		$page = Pass::page( $attendee );
		$this->assertStringContainsString( '<h1>Tonight</h1>', $page );
		$this->assertStringContainsString( '<svg class="oe-qr"', $page );
		$this->assertStringContainsString( 'aria-label="QR code for check-in code ' . $attendee->security_code . '"', $page );
		$this->assertStringContainsString( '<code class="oe-pass__code">' . $attendee->security_code . '</code>', $page );
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow">', $page );

		( new Attendees() )->set_status( $attendee->id, Attendee::CANCELLED );
		$cancelled = Pass::page( ( new Attendees() )->get( $attendee->id ) );
		$this->assertStringContainsString( 'This pass is no longer valid', $cancelled );
		$this->assertStringNotContainsString( '<svg', $cancelled );

		$this->assertStringContainsString( 'isn’t valid', Pass::page( null ) );
	}

	public function test_confirmation_email_links_each_persons_pass(): void {
		$people = $this->rsvp( 2 );
		$body   = tests_retrieve_phpmailer_instance()->get_sent()->body;
		$this->assertStringContainsString( 'Passes (show one per person at the door', $body );
		foreach ( $people as $attendee ) {
			$this->assertStringContainsString( Pass::url( $attendee ), $body );
		}
	}

	public function test_check_in_at_the_door(): void {
		$attendee = $this->rsvp()[0];
		$this->staff();

		$found = $this->request( 'GET', array( 'code' => strtolower( $attendee->security_code ) ) );
		$this->assertSame( 200, $found->get_status() );
		$this->assertSame( 'found', $found->get_data()['result'] );
		$this->assertSame( 'Ada Lovelace', $found->get_data()['attendee']['name'] );
		$this->assertFalse( $found->get_data()['attendee']['checked_in'], 'Looking up doesn’t check in.' );

		$done = $this->request( 'POST', array( 'code' => Pass::check_in_url( $attendee ) ) )->get_data();
		$this->assertSame( 'checked_in', $done['result'], 'A scanned link works too.' );
		$this->assertSame(
			array(
				'checked_in' => 1,
				'going'      => 1,
			),
			$done['counts']
		);
		$this->assertSame( array(), $done['warnings'] );

		$again = $this->request( 'POST', array( 'code' => $attendee->security_code ) )->get_data();
		$this->assertSame( 'already', $again['result'] );
		$this->assertStringStartsWith( 'Already checked in at ', $again['message'] );

		$undo = $this->request(
			'POST',
			array(
				'code' => $attendee->security_code,
				'undo' => true,
			)
		)->get_data();
		$this->assertSame( 'undone', $undo['result'] );
		$this->assertNull( ( new Attendees() )->get( $attendee->id )->checked_in_utc );
	}

	public function test_only_staff_for_that_event(): void {
		$attendee = $this->rsvp()[0];

		$this->assertSame( 401, $this->request( 'POST', array( 'code' => $attendee->security_code ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'POST', array( 'code' => $attendee->security_code ) )->get_status() );

		// An author can open the screen, but this isn't their event: same answer as a wrong code.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 404, $this->request( 'POST', array( 'code' => $attendee->security_code ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', array( 'code' => 'NOSUCHCODE' ) )->get_status() );
		$this->assertNull( ( new Attendees() )->get( $attendee->id )->checked_in_utc );
	}

	public function test_warns_when_payment_is_due_at_the_door(): void {
		update_option( \OpenEvents\Modules\Tickets\AbstractGateway::OPTION, array( 'manual' => array( 'enabled' => true ) ) );
		\OpenEvents\Modules\Tickets\Gateways::reset();
		update_post_meta(
			$this->event,
			\OpenEvents\Modules\Tickets\Module::META_KEY,
			array(
				array(
					'key'   => 'adult',
					'name'  => 'Adult',
					'price' => '15',
				),
			)
		);
		$type   = current( ( new \OpenEvents\Modules\Tickets\Checkout() )->ticket_types( $this->event ) );
		$result = ( new \OpenEvents\Modules\Tickets\Checkout() )->place(
			$this->event,
			array(
				'name'    => 'Grace Hopper',
				'email'   => 'grace@example.com',
				'tickets' => array( $type->id => 2 ),
				'gateway' => 'manual',
				'ts'      => Guard::sign_timestamp( time() - 10 ),
				'ip'      => '198.51.100.20',
			)
		);
		$this->assertTrue( $result->ok, $result->message );
		$this->staff();

		$code = ( new Attendees() )->group( $result->order->uuid )[0]->security_code;
		$data = $this->request( 'POST', array( 'code' => $code ) )->get_data();
		$this->assertSame( 'checked_in', $data['result'] );
		$this->assertSame( array( 'unpaid' ), array_column( $data['warnings'], 'code' ) );
		$this->assertStringContainsString( '30.00', $data['warnings'][0]['message'] );
	}

	public function test_refuses_cancelled_bookings_and_warns_about_other_dates(): void {
		$attendee = $this->rsvp()[0];
		$this->staff();

		( new Attendees() )->set_status( $attendee->id, Attendee::CANCELLED );
		$refused = $this->request( 'POST', array( 'code' => $attendee->security_code ) );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertStringContainsString( 'cancelled', $refused->get_data()['message'] );

		$later = $this->event( array( '_oe_start' => current_datetime()->modify( '+3 days' )->format( 'Y-m-d' ) . ' 19:00:00' ) );
		update_post_meta( $later, Rsvp::META_KEY, array( 'enabled' => true ) );
		$result = ( new Rsvp() )->respond(
			$later,
			array(
				'name'  => 'Grace',
				'email' => 'grace@example.com',
				'ts'    => Guard::sign_timestamp( time() - 10 ),
				'ip'    => '203.0.113.77',
			)
		);
		$code   = ( new Attendees() )->group( $result->group )[0]->security_code;
		$data   = $this->request( 'POST', array( 'code' => $code ) )->get_data();
		$this->assertSame( 'checked_in', $data['result'], 'Staff decide; it isn’t blocked.' );
		$this->assertSame( array( 'other_date' ), array_column( $data['warnings'], 'code' ) );
	}
}
