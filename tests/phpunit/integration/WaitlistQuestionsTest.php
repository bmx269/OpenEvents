<?php
/**
 * Attendee questions on RSVP waitlists: asked when joining, checked like a
 * direct response, carried onto attendees at promotion, and covered by the
 * privacy tools.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Privacy;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Attendance\Waitlist;
use OpenEvents\Attendance\WaitlistEntry;
use OpenEvents\Attendance\WaitlistService;
use OpenEvents\Modules\Rsvp\Form;
use OpenEvents\Modules\Rsvp\Service as Rsvp;
use OpenEvents\Tests\TestCase;

final class WaitlistQuestionsTest extends TestCase {

	private const FIELDS = array(
		array(
			'type'  => 'tel',
			'label' => 'Phone number',
		),
		array(
			'type'     => 'radio',
			'label'    => 'Age range',
			'required' => true,
			'options'  => array( 'Under 18', '18 or over' ),
		),
	);

	private int $event;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$day         = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$this->event = $this->event( array( '_oe_start' => "{$day} 19:00:00" ) );
		update_post_meta(
			$this->event,
			Rsvp::META_KEY,
			array(
				'enabled'          => true,
				'capacity'         => 1,
				'max_per_response' => 3,
				'waitlist'         => true,
				'attendee_details' => 'optional',
				'fields'           => self::FIELDS,
			)
		);
		$this->rows( $this->event );
	}

	private function respond( string $email, array $input = array() ) {
		return ( new Rsvp() )->respond(
			$this->event,
			array_merge(
				array(
					'name'      => ucfirst( strtok( $email, '@' ) ),
					'email'     => $email,
					'status'    => 'going',
					'quantity'  => 1,
					'attendees' => array( array( 'fields' => array( 'age-range' => '18 or over' ) ) ),
					'ts'        => Guard::sign_timestamp( time() - 10 ),
					'ip'        => '203.0.113.' . wp_rand( 1, 250 ),
				),
				$input
			)
		);
	}

	/** Fills the one seat; returns the link token that frees it again. */
	private function fill(): string {
		$this->respond( 'first@example.com' );
		$mailer = tests_retrieve_phpmailer_instance();
		preg_match( '/oe_rsvp_token=([a-f0-9]+)/', $mailer->get_sent( count( $mailer->mock_sent ) - 1 )->body, $m );
		return $m[1];
	}

	/** Lead and one guest, each with their own answers. */
	private function join_with_guest() {
		return $this->respond(
			'lead@example.com',
			array(
				'status'    => 'waitlist',
				'quantity'  => 2,
				'attendees' => array(
					array(
						'fields' => array(
							'phone-number' => '604 555 0100',
							'age-range'    => '18 or over',
						),
					),
					array(
						'name'   => 'Gus Guest',
						'email'  => 'gus@example.com',
						'fields' => array( 'age-range' => 'Under 18' ),
					),
				),
			)
		);
	}

	private function entry(): WaitlistEntry {
		$items = ( new Waitlist() )->query( array( 'event_id' => $this->event ) )['items'];
		$this->assertCount( 1, $items );
		return $items[0];
	}

	public function test_joining_checks_answers_like_a_response(): void {
		$this->fill();

		$result = $this->respond(
			'lead@example.com',
			array(
				'status'    => 'waitlist',
				'quantity'  => 2,
				'attendees' => array( array( 'fields' => array( 'age-range' => '18 or over' ) ) ),
			)
		);

		$this->assertSame( 'fields', $result->code );
		$this->assertSame( 'Guest 2: Please fill in “Age range”.', $result->message );
		$this->assertSame( 0, ( new Waitlist() )->query( array( 'event_id' => $this->event ) )['total'], 'Nothing is queued.' );
	}

	public function test_promotion_carries_answers_onto_attendees(): void {
		$token = $this->fill();
		$this->assertSame( 'waitlisted', $this->join_with_guest()->code );
		$this->assertSame( 'Under 18', $this->entry()->people[1]['fields']['age-range'] );

		// Make room for both.
		update_post_meta( $this->event, Rsvp::META_KEY, array_merge( get_post_meta( $this->event, Rsvp::META_KEY, true ), array( 'capacity' => 3 ) ) );
		( new Rsvp() )->cancel( $token );
		$this->rows( $this->event );

		$people = array_column( ( new Attendees() )->query( array( 'event_id' => $this->event, 'status' => 'going' ) )['items'], null, 'name' );
		$this->assertEqualsCanonicalizing( array( 'Lead', 'Gus Guest' ), array_keys( $people ), 'Guests keep the name they gave.' );
		$this->assertSame( array( 'phone-number' => '604 555 0100', 'age-range' => '18 or over' ), $people['Lead']->fields );
		$this->assertSame( 'gus@example.com', $people['Gus Guest']->email );
		$this->assertSame( array( 'age-range' => 'Under 18' ), $people['Gus Guest']->fields );

		$entry = $this->entry();
		$this->assertSame( WaitlistEntry::CLAIMED, $entry->status );
		$this->assertSame( array(), $entry->people, 'The copy on the waitlist is dropped once promoted.' );
	}

	public function test_leaving_drops_the_answers(): void {
		$this->fill();
		$this->join_with_guest();
		$entry = $this->entry();

		( new WaitlistService() )->remove( $entry );

		$this->assertSame( array(), $this->entry()->people );
	}

	public function test_waitlist_form_asks_the_questions(): void {
		$this->fill();
		$type = ( new TicketTypes() )->find( $this->event, Rsvp::TYPE_KEY );
		$html = Form::render( \OpenEvents\Content\Event::get( $this->event ), $type, new Rsvp() );

		$this->assertStringContainsString( 'name="status" value="waitlist"', $html );
		$this->assertSame( 3, substr_count( $html, '<fieldset class="oe-person"' ) );
		$this->assertStringContainsString( 'name="attendees[0][fields][age-range]"', $html );
		$this->assertStringContainsString( 'data-wp-on--change="actions.setQuantity"', $html );
		$this->assertStringContainsString( '{&quot;quantity&quot;:1,&quot;going&quot;:true}', $html, 'Groups beyond the party size hide, as on the RSVP form.' );
	}

	public function test_organisers_see_the_answers(): void {
		$this->fill();
		$this->join_with_guest();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new \WP_REST_Request( 'GET', '/openevents/v1/waitlist' );
		$request->set_param( 'event', $this->event );
		$answers = array_column( rest_do_request( $request )->get_data()[0]['answers'], 'value', 'label' );

		$this->assertSame( '604 555 0100', $answers['Phone number'] );
		$this->assertSame( 'Gus Guest', $answers['Guest 2: Name'] );
		$this->assertSame( 'Under 18', $answers['Guest 2: Age range'] );
	}

	public function test_privacy_tools_cover_guests_on_someone_elses_entry(): void {
		$this->fill();
		$this->join_with_guest();

		$lead = array_column( Privacy::export( 'lead@example.com' )['data'], null, 'group_id' )['openevents-waitlist'];
		$lead = array_column( $lead['data'], 'value', 'name' );
		$this->assertSame( '604 555 0100', $lead['You: Phone number'] );
		$this->assertSame( 'gus@example.com', $lead['Guest 2: Email'] );

		$guest = Privacy::export( 'GUS@example.com' )['data'];
		$this->assertCount( 1, $guest );
		$values = array_column( $guest[0]['data'], 'value', 'name' );
		$this->assertSame( 'Gus Guest', $values['Name'] );
		$this->assertSame( 'Under 18', $values['Age range'] );
		$this->assertNotContains( '604 555 0100', $values, 'A guest sees only their own part.' );
		$this->assertNotContains( 'lead@example.com', $values );

		$this->assertTrue( Privacy::erase( 'gus@example.com' )['items_removed'] );
		$entry = $this->entry();
		$this->assertSame( WaitlistEntry::WAITING, $entry->status, 'The lead keeps their place.' );
		$this->assertSame(
			array(
				'name'   => '',
				'email'  => '',
				'fields' => array(),
			),
			$entry->people[1]
		);
		$this->assertSame( '604 555 0100', $entry->people[0]['fields']['phone-number'] );
		$this->assertSame( array(), Privacy::export( 'gus@example.com' )['data'] );
	}
}
