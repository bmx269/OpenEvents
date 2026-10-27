<?php
/**
 * RSVP module.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendee;
use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Modules\Rsvp\Admin;
use OpenEvents\Modules\Rsvp\Form;
use OpenEvents\Modules\Rsvp\Service;
use OpenEvents\Tests\TestCase;

final class RsvpTest extends TestCase {

	private Service $rsvp;

	public function set_up(): void {
		parent::set_up();
		$this->rsvp = new Service();
		reset_phpmailer_instance();
	}

	/**
	 * An upcoming event (in 5 days) with RSVP settings.
	 *
	 * @param array<string, mixed> $config _oe_rsvp overrides.
	 */
	private function rsvp_event( array $config = array(), int $days = 5 ): int {
		$day = current_datetime()->modify( "+{$days} days" )->format( 'Y-m-d' );
		$id  = $this->event(
			array(
				'_oe_start' => "{$day} 19:00:00",
				'_oe_end'   => "{$day} 21:00:00",
			)
		);
		update_post_meta( $id, Service::META_KEY, array_merge( array( 'enabled' => true ), $config ) );
		$this->rows( $id ); // Flush occurrence sync.
		return $id;
	}

	/**
	 * @param array<string, mixed> $input Overrides.
	 */
	private function respond( int $event_id, array $input = array() ) {
		return $this->rsvp->respond(
			$event_id,
			array_merge(
				array(
					'name'     => 'Ada Lovelace',
					'email'    => 'ada@example.com',
					'status'   => 'going',
					'quantity' => 1,
					'ts'       => Service::sign_timestamp( time() - 10 ),
					'ip'       => '203.0.113.' . wp_rand( 1, 250 ),
				),
				$input
			)
		);
	}

	private function sold( int $event_id ): int {
		return ( new TicketTypes() )->find( $event_id, Service::TYPE_KEY )->sold;
	}

	private function last_email(): ?object {
		$mailer = tests_retrieve_phpmailer_instance();
		return $mailer->get_sent( count( $mailer->mock_sent ) - 1 ) ?: null;
	}

	private function token_from_last_email(): string {
		preg_match( '/oe_rsvp_token=([a-f0-9]+)/', $this->last_email()->body, $m );
		return $m[1] ?? '';
	}

	public function test_settings_create_and_archive_the_ticket_type(): void {
		$id   = $this->rsvp_event( array( 'capacity' => 40 ) );
		$type = ( new TicketTypes() )->find( $id, Service::TYPE_KEY );
		$this->assertSame( 'active', $type->status );
		$this->assertSame( 40, $type->capacity );

		update_post_meta( $id, Service::META_KEY, array( 'enabled' => false ) );
		$this->assertSame( 'archived', ( new TicketTypes() )->find( $id, Service::TYPE_KEY )->status );
		$this->assertNull( $this->rsvp->ticket_type( $id ) );
	}

	public function test_going_reserves_seats_and_emails_a_private_link(): void {
		$id     = $this->rsvp_event( array( 'max_per_response' => 3 ) );
		$result = $this->respond( $id, array( 'quantity' => 3 ) );

		$this->assertTrue( $result->ok, $result->message );
		$this->assertSame( 'going', $result->code );
		$this->assertSame( 3, $this->sold( $id ) );

		$people = ( new Attendees() )->group( $result->group );
		$this->assertCount( 3, $people, 'One row per person, so guests can be checked in.' );
		$this->assertSame( 'Guest of Ada Lovelace', $people[1]->name );
		$this->assertNotSame( $people[0]->security_code, $people[1]->security_code );

		$email = $this->last_email();
		$this->assertSame( 'ada@example.com', $email->to[0][0] );
		$this->assertStringContainsString( 'party of 3', $email->body );
		$this->assertNotSame( '', $this->token_from_last_email() );
	}

	public function test_capacity_is_never_exceeded(): void {
		$id = $this->rsvp_event(
			array(
				'capacity'         => 3,
				'max_per_response' => 2,
			)
		);
		$this->assertTrue( $this->respond( $id, array( 'quantity' => 2 ) )->ok );

		$full = $this->respond(
			$id,
			array(
				'email'    => 'bob@example.com',
				'quantity' => 2,
			)
		);
		$this->assertFalse( $full->ok );
		$this->assertSame( 'full', $full->code );
		$this->assertSame( 'Only 1 spot is left.', $full->message );

		$this->assertTrue( $this->respond( $id, array( 'email' => 'bob@example.com' ) )->ok );
		$this->assertSame( 'full', $this->respond( $id, array( 'email' => 'cy@example.com' ) )->code );
		$this->assertSame( 3, $this->sold( $id ) );
	}

	public function test_atomic_reserve_rejects_overbooking(): void {
		$id   = $this->rsvp_event( array( 'capacity' => 2 ) );
		$type = $this->rsvp->ticket_type( $id );
		$repo = new TicketTypes();

		$this->assertTrue( $repo->reserve( $type->id, 2 ) );
		$this->assertFalse( $repo->reserve( $type->id, 1 ), 'The conditional UPDATE refuses the third seat.' );
		$this->assertSame( 2, $this->sold( $id ) );
	}

	public function test_same_email_cannot_overwrite_someone_elses_rsvp(): void {
		$id       = $this->rsvp_event( array( 'max_per_response' => 2 ) );
		$original = $this->respond( $id, array( 'quantity' => 2 ) );
		$old      = $this->token_from_last_email();

		$again = $this->respond( $id, array( 'name' => 'Impostor', 'status' => 'not_going' ) );

		$this->assertTrue( $again->ok );
		$this->assertSame( 'exists', $again->code );
		$this->assertSame( 2, $this->sold( $id ), 'Seats unchanged.' );
		$this->assertSame( 'Ada Lovelace', ( new Attendees() )->group( $original->group )[0]->name );
		$this->assertStringContainsString( 'already has a response', $this->last_email()->body );
		$this->assertSame( array(), $this->rsvp->response_for_token( $old ), 'The old link is replaced.' );
		$this->assertCount( 2, $this->rsvp->response_for_token( $this->token_from_last_email() ) );
	}

	public function test_private_link_changes_and_cancels_a_response(): void {
		$id = $this->rsvp_event( array( 'max_per_response' => 3 ) );
		$this->respond( $id, array( 'quantity' => 3 ) );
		$token = $this->token_from_last_email();

		$this->assertTrue( $this->respond( $id, array( 'token' => $token, 'quantity' => 1 ) )->ok );
		$this->assertSame( 1, $this->sold( $id ), 'Fewer people frees seats.' );

		$this->assertSame( 'not_going', $this->respond( $id, array( 'token' => $token, 'status' => 'not_going' ) )->code );
		$this->assertSame( 0, $this->sold( $id ) );

		$this->assertTrue( $this->respond( $id, array( 'token' => $token, 'quantity' => 2 ) )->ok );
		$this->assertSame( 2, $this->sold( $id ) );

		$cancelled = $this->rsvp->cancel( $token );
		$this->assertTrue( $cancelled->ok );
		$this->assertSame( 0, $this->sold( $id ) );
		$this->assertSame( Attendee::CANCELLED, ( new Attendees() )->group( $cancelled->group )[0]->status );
		$this->assertSame( array(), $this->rsvp->response_for_token( $token ) );
	}

	public function test_validation_rules(): void {
		$id = $this->rsvp_event(
			array(
				'max_per_response' => 2,
				'allow_not_going'  => false,
			)
		);

		$this->assertSame( 'invalid', $this->respond( $id, array( 'quantity' => 3 ) )->code );
		$this->assertSame( 'invalid', $this->respond( $id, array( 'status' => 'not_going' ) )->code );
		$this->assertSame( 'invalid', $this->respond( $id, array( 'email' => 'nope' ) )->code );
		$this->assertSame( 'invalid', $this->respond( $id, array( 'name' => '' ) )->code );
		$this->assertSame( 0, $this->sold( $id ) );
	}

	public function test_closing_rules(): void {
		$started = $this->rsvp_event( array(), -1 );
		$this->assertSame( 'closed', $this->respond( $started )->code, 'Closes when the event starts by default.' );

		$deadline = $this->rsvp_event( array( 'deadline' => current_datetime()->modify( '-1 hour' )->format( 'Y-m-d H:i:s' ) ) );
		$this->assertSame( 'deadline', $this->rsvp->closed_reason( $deadline ) );
		$this->assertSame( 'closed', $this->respond( $deadline )->code );

		$disabled = $this->event( array( '_oe_start' => current_datetime()->modify( '+3 days' )->format( 'Y-m-d H:i:s' ) ) );
		$this->assertSame( 'disabled', $this->respond( $disabled )->code );
	}

	public function test_spam_defences(): void {
		$id = $this->rsvp_event();

		$honeypot = $this->respond( $id, array( 'website' => 'http://spam.example' ) );
		$this->assertTrue( $honeypot->ok, 'Bots are told it worked…' );
		$this->assertSame( 0, $this->sold( $id ), '…but nothing is saved.' );

		$this->assertSame( 'invalid', $this->respond( $id, array( 'ts' => Service::sign_timestamp() ) )->code, 'Instant submissions are rejected.' );
		$this->assertSame( 'invalid', $this->respond( $id, array( 'ts' => ( time() - 10 ) . '.forged' ) )->code, 'Forged timestamps are rejected.' );

		for ( $i = 0; $i < Service::RATE; $i++ ) {
			$this->assertTrue( $this->respond( $id, array( 'email' => "p{$i}@example.com", 'ip' => '198.51.100.7' ) )->ok );
		}
		$this->assertSame( 'rate_limited', $this->respond( $id, array( 'email' => 'more@example.com', 'ip' => '198.51.100.7' ) )->code );
	}

	public function test_rest_responses_and_status_codes(): void {
		$id      = $this->rsvp_event( array( 'capacity' => 1 ) );
		$request = static function ( array $body ) use ( $id ) {
			$r = new \WP_REST_Request( 'POST', "/openevents/v1/events/{$id}/rsvp" );
			$r->set_body_params( array_merge( array( 'name' => 'Ada', 'status' => 'going', 'quantity' => 1, 'ts' => Service::sign_timestamp( time() - 10 ) ), $body ) );
			return rest_do_request( $r );
		};

		$ok = $request( array( 'email' => 'a@example.com' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( '1 going · Full', $ok->get_data()['count_text'] );

		$this->assertSame( 409, $request( array( 'email' => 'b@example.com' ) )->get_status() );
		$this->assertSame( 422, $request( array( 'email' => 'bad' ) )->get_status() );

		$summary = rest_do_request( new \WP_REST_Request( 'GET', "/openevents/v1/events/{$id}/rsvp" ) )->get_data();
		$this->assertSame( 1, $summary['going'] );
		$this->assertSame( 0, $summary['remaining'] );
	}

	public function test_attendee_list_requires_edit_rights_and_supports_check_in(): void {
		$id = $this->rsvp_event( array( 'capacity' => 1 ) );
		$this->respond( $id );

		$list = new \WP_REST_Request( 'GET', '/openevents/v1/attendees' );
		$list->set_param( 'event', $id );
		$this->assertGreaterThanOrEqual( 401, rest_do_request( $list )->get_status(), 'Anonymous users are refused.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_do_request( $list );
		$this->assertSame( 200, $response->get_status() );
		$attendee = $response->get_data()[0];
		$this->assertSame( 'Ada Lovelace', $attendee['name'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );

		$checkin = new \WP_REST_Request( 'POST', '/openevents/v1/attendees/' . $attendee['id'] );
		$checkin->set_body_params( array( 'checked_in' => true ) );
		$this->assertTrue( rest_do_request( $checkin )->get_data()['checked_in'] );

		$cancel = new \WP_REST_Request( 'POST', '/openevents/v1/attendees/' . $attendee['id'] );
		$cancel->set_body_params( array( 'status' => 'cancelled' ) );
		rest_do_request( $cancel );
		$this->assertSame( 0, $this->sold( $id ), 'Cancelling from the admin frees the seat.' );
	}

	public function test_form_markup_is_accessible_and_cache_safe(): void {
		$id   = $this->rsvp_event( array( 'max_per_response' => 4 ) );
		$html = Form::render( openevents_get_event( $id ), $this->rsvp->ticket_type( $id ), $this->rsvp );

		$this->assertStringContainsString( '<label for="oe-rsvp-' . $id . '-name">Name</label>', $html );
		$this->assertStringContainsString( '<label for="oe-rsvp-' . $id . '-email">Email</label>', $html );
		$this->assertStringContainsString( '<legend>Are you going?</legend>', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( 'name="website" tabindex="-1"', $html, 'Honeypot is out of the tab order.' );
		$this->assertStringNotContainsString( '_wpnonce', $html, 'No nonce, so cached pages keep working.' );
		$this->assertMatchesRegularExpression( '/name="ts" value="\d+\.[a-f0-9]{16}"/', $html );
		$this->assertSame( 4, substr_count( $html, '<option value=' ) );
	}

	public function test_full_event_offers_only_not_going(): void {
		$id = $this->rsvp_event( array( 'capacity' => 1 ) );
		$this->respond( $id );
		$html = Form::render( openevents_get_event( $id ), $this->rsvp->ticket_type( $id ), $this->rsvp );

		$this->assertStringContainsString( 'This event is full.', $html );
		$this->assertMatchesRegularExpression( '/value="going"[^>]*disabled/', $html );
		$this->assertMatchesRegularExpression( '/value="not_going" checked/', $html );
	}

	public function test_csv_cells_are_protected_from_formula_injection(): void {
		$this->assertSame( "'=HYPERLINK(\"x\")", Admin::safe_cell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'+1", Admin::safe_cell( '+1' ) );
		$this->assertSame( 'Ada', Admin::safe_cell( 'Ada' ) );
	}
}
