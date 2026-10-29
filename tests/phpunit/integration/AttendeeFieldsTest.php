<?php
/**
 * Attendee fields: definitions, validation, collection on RSVPs and orders,
 * and reporting (REST, CSV).
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Fields;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Modules\Rsvp\Form;
use OpenEvents\Modules\Rsvp\Service;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\Module as TicketsModule;
use OpenEvents\Tests\TestCase;

final class AttendeeFieldsTest extends TestCase {

	/** WVML-style questions: phone, age range, and a required consent. */
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
		array(
			'type'     => 'checkbox',
			'label'    => 'I understand that the information on this form is collected under the authority of the Freedom of Information and Protection of Privacy Act.',
			'required' => true,
		),
	);

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	private function rsvp_event( array $config = array() ): int {
		$day = current_datetime()->modify( '+5 days' )->format( 'Y-m-d' );
		$id  = $this->event(
			array(
				'_oe_start' => "{$day} 19:00:00",
				'_oe_end'   => "{$day} 21:00:00",
			)
		);
		update_post_meta(
			$id,
			Service::META_KEY,
			array_merge(
				array(
					'enabled'          => true,
					'max_per_response' => 3,
					'fields'           => self::FIELDS,
				),
				$config
			)
		);
		$this->rows( $id );
		return $id;
	}

	private function respond( int $event_id, array $input ) {
		return ( new Service() )->respond(
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

	/**
	 * Keys made from labels, as stored after sanitising.
	 *
	 * @return string[]
	 */
	private function keys(): array {
		return array_column( Fields::sanitize( self::FIELDS ), 'key' );
	}

	public function test_definitions_are_cleaned(): void {
		$fields = Fields::sanitize(
			array(
				array(
					'label' => 'Name',
					'type'  => 'nope',
				),
				array(
					'label' => 'Name',
					'type'  => 'email',
				),
				array(
					'label'   => 'Pick',
					'type'    => 'select',
					'options' => array(),
				),
				array( 'label' => '' ),
				array(
					'label'    => 'Agree',
					'type'     => 'checkbox',
					'required' => 1,
				),
			)
		);

		$this->assertSame( array( 'name', 'name-2', 'agree' ), array_column( $fields, 'key' ), 'Unique keys from labels; empty labels and option-less selects dropped.' );
		$this->assertSame( 'text', $fields[0]['type'], 'Unknown types become text.' );
		$this->assertSame( array( 'Yes' ), $fields[2]['options'], 'A lone checkbox gets one option.' );
		$this->assertTrue( $fields[2]['required'] );
	}

	/**
	 * @dataProvider answers
	 */
	public function test_validation( string $type, array $options, mixed $value, bool $ok ): void {
		$fields = Fields::sanitize(
			array(
				array(
					'label'    => 'Q',
					'type'     => $type,
					'options'  => $options,
					'required' => true,
				),
			)
		);
		$result = Fields::collect( $fields, array( 'q' => $value ) );
		$this->assertSame( $ok, null === $result['error'], (string) $result['error'] );
	}

	public static function answers(): array {
		return array(
			'email ok'          => array( 'email', array(), 'a@example.com', true ),
			'email bad'         => array( 'email', array(), 'nope', false ),
			'phone ok'          => array( 'tel', array(), '+1 (604) 925-7400', true ),
			'phone letters'     => array( 'tel', array(), 'call me', false ),
			'url ok'            => array( 'url', array(), 'https://example.com', true ),
			'url scheme'        => array( 'url', array(), 'javascript:alert(1)', false ),
			'number'            => array( 'number', array(), '12', true ),
			'date ok'           => array( 'date', array(), '2026-02-28', true ),
			'date impossible'   => array( 'date', array(), '2026-02-30', false ),
			'select ok'         => array( 'select', array( 'A', 'B' ), 'B', true ),
			'select other'      => array( 'select', array( 'A', 'B' ), 'C', false ),
			'checkbox picked'   => array( 'checkbox', array( 'A', 'B' ), array( 'B', 'Z' ), true ),
			'checkbox none'     => array( 'checkbox', array( 'A', 'B' ), array( 'Z' ), false ),
			'required empty'    => array( 'text', array(), '   ', false ),
		);
	}

	public function test_rsvp_stores_answers_for_each_person(): void {
		list( $phone, $age, $consent ) = $this->keys();
		$event = $this->rsvp_event( array( 'attendee_details' => 'optional' ) );

		$result = $this->respond(
			$event,
			array(
				'quantity'  => 2,
				'attendees' => array(
					array(
						'fields' => array(
							$phone   => '604-555-0100',
							$age     => '18 or over',
							$consent => array( 'Yes' ),
						),
					),
					array(
						'name'   => 'Charles Babbage',
						'email'  => 'charles@example.com',
						'fields' => array(
							$age     => 'Under 18',
							$consent => array( 'Yes' ),
						),
					),
				),
			)
		);

		$this->assertTrue( $result->ok, $result->message );
		$people = ( new Attendees() )->group( $result->group );
		$this->assertSame( 'Ada Lovelace', $people[0]->name );
		$this->assertSame(
			array(
				$phone   => '604-555-0100',
				$age     => '18 or over',
				$consent => array( 'Yes' ),
			),
			$people[0]->fields
		);
		$this->assertSame( 'Charles Babbage', $people[1]->name, 'Guests can give their own name.' );
		$this->assertSame( 'charles@example.com', $people[1]->email );
		$this->assertSame( 'Under 18', $people[1]->fields[ $age ] );
	}

	public function test_rsvp_requires_answers_before_holding_seats(): void {
		list( , $age ) = $this->keys();
		$event         = $this->rsvp_event();

		$result = $this->respond(
			$event,
			array(
				'quantity'  => 2,
				'attendees' => array( array( 'fields' => array( $age => '18 or over' ) ) ),
			)
		);

		$this->assertSame( 'fields', $result->code );
		$this->assertStringStartsWith( 'You: Please check “I understand that the information on this form is collected under', $result->message );
		$this->assertStringEndsWith( '…”.', $result->message, 'Long labels are shortened in messages.' );
		$this->assertSame( 0, ( new TicketTypes() )->find( $event, Service::TYPE_KEY )->sold );
	}

	public function test_not_going_skips_the_questions(): void {
		$event  = $this->rsvp_event();
		$result = $this->respond( $event, array( 'status' => 'not_going' ) );
		$this->assertTrue( $result->ok, $result->message );
	}

	public function test_required_guest_details(): void {
		list( , $age, $consent ) = $this->keys();
		$event                   = $this->rsvp_event( array( 'attendee_details' => 'required' ) );
		$answers                 = array(
			$age     => 'Under 18',
			$consent => array( 'Yes' ),
		);

		$result = $this->respond(
			$event,
			array(
				'quantity'  => 2,
				'attendees' => array( array( 'fields' => $answers ), array( 'fields' => $answers ) ),
			)
		);

		$this->assertSame( 'fields', $result->code );
		$this->assertSame( 'Guest 2: please enter a name and email.', $result->message );
	}

	public function test_form_renders_a_group_per_person(): void {
		$event = $this->rsvp_event( array( 'attendee_details' => 'optional' ) );
		$type  = ( new TicketTypes() )->find( $event, Service::TYPE_KEY );
		$html  = Form::render( \OpenEvents\Content\Event::get( $event ), $type, new Service() );

		$this->assertSame( 3, substr_count( $html, '<fieldset class="oe-person"' ) );
		$this->assertStringContainsString( '<legend>Your details</legend>', $html );
		$this->assertStringContainsString( '<legend>Guest 3</legend>', $html );
		$this->assertStringContainsString( 'name="attendees[1][email]"', $html );
		// Guests' required inputs aren't required in the HTML (no-JS forms would block on unused groups).
		$this->assertStringContainsString( 'data-wp-bind--required="state.groupRequired"', $html );
		$this->assertSame( 0, substr_count( $html, ' required data-wp' ) );
	}

	public function test_checkout_stores_answers_per_ticket(): void {
		list( $phone, $age, $consent ) = $this->keys();
		$day                           = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$event                         = $this->event(
			array(
				'_oe_start' => "{$day} 19:00:00",
				'_oe_end'   => "{$day} 21:00:00",
			)
		);
		update_post_meta(
			$event,
			TicketsModule::META_KEY,
			array(
				array(
					'name'             => 'Puppet show',
					'price'            => '0',
					'capacity'         => 40,
					'attendee_details' => 'required',
					'fields'           => self::FIELDS,
				),
			)
		);
		$this->rows( $event );
		$type = current( ( new Checkout() )->ticket_types( $event ) );

		$input = array(
			'name'    => 'Grace Hopper',
			'email'   => 'grace@example.com',
			'tickets' => array( $type->id => 2 ),
			'ts'      => Guard::sign_timestamp( time() - 10 ),
			'ip'      => '198.51.100.7',
		);
		$child = array(
			$age     => 'Under 18',
			$consent => array( 'Yes' ),
		);

		$missing = ( new Checkout() )->place( $event, $input + array( 'attendees' => array( $type->id => array( array( 'name' => 'Kid One', 'email' => 'one@example.com', 'fields' => $child ) ) ) ) );
		$this->assertSame( 'fields', $missing->code );
		$this->assertSame( 'Puppet show, ticket 2: please enter a name and email.', $missing->message );
		$this->assertSame( 0, ( new TicketTypes() )->get( $type->id )->sold );

		$result = ( new Checkout() )->place(
			$event,
			$input + array(
				'attendees' => array(
					$type->id => array(
						array(
							'name'   => 'Kid One',
							'email'  => 'one@example.com',
							'fields' => $child + array( $phone => '604 555 0101' ),
						),
						array(
							'name'   => 'Kid Two',
							'email'  => 'two@example.com',
							'fields' => $child,
						),
					),
				),
			)
		);
		$this->assertTrue( $result->ok, $result->message );
		$people = ( new Attendees() )->query(
			array(
				'event_id' => $event,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		)['items'];
		$this->assertSame( array( 'Kid One', 'Kid Two' ), array_map( static fn( $a ) => $a->name, $people ) );
		$this->assertSame( '604 555 0101', $people[0]->fields[ $phone ] );
		$this->assertSame( array( 'Yes' ), $people[1]->fields[ $consent ] );
	}

	public function test_rest_lists_answers_with_labels(): void {
		list( $phone, $age, $consent ) = $this->keys();
		$event                         = $this->rsvp_event();
		$this->respond(
			$event,
			array(
				'attendees' => array(
					array(
						'fields' => array(
							$phone   => '604-555-0100',
							$age     => '18 or over',
							$consent => array( 'Yes' ),
						),
					),
				),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new \WP_REST_Request( 'GET', '/openevents/v1/attendees' );
		$request->set_param( 'event', $event );
		$item = rest_get_server()->dispatch( $request )->get_data()[0];

		$this->assertSame( 'Phone number', $item['answers'][0]['label'] );
		$this->assertSame( '604-555-0100', $item['answers'][0]['value'] );
		$this->assertSame( 'Yes', $item['answers'][2]['value'] );
	}

	public function test_answers_survive_a_removed_question(): void {
		list( $phone ) = $this->keys();
		$event         = $this->rsvp_event( array( 'fields' => array( self::FIELDS[0] ) ) );
		$result        = $this->respond( $event, array( 'attendees' => array( array( 'fields' => array( $phone => '604-555-0100' ) ) ) ) );

		update_post_meta( $event, Service::META_KEY, array( 'enabled' => true ) );
		$types    = array();
		$attendee = ( new Attendees() )->group( $result->group )[0];
		$this->assertSame(
			array(
				array(
					'key'   => $phone,
					'label' => $phone,
					'value' => '604-555-0100',
				),
			),
			Fields::answers( $attendee, $types )
		);
	}

	public function test_saved_question_sets(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request = new \WP_REST_Request( 'POST', '/openevents/v1/field-sets' );
		$request->set_body_params(
			array(
				'name'   => 'Kids programs',
				'fields' => self::FIELDS,
			)
		);
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$request->set_body_params(
			array(
				'name'   => 'Kids programs',
				'fields' => array( self::FIELDS[0] ),
			)
		);
		$sets = rest_get_server()->dispatch( $request )->get_data();

		$this->assertCount( 1, $sets, 'Saving under the same name replaces the set.' );
		$this->assertSame( array( 'Phone number' ), array_column( $sets[0]['fields'], 'label' ) );
	}

	public function test_deleting_an_event_deletes_its_attendance_data(): void {
		global $wpdb;
		$keep   = $this->rsvp_event( array( 'fields' => array() ) );
		$event  = $this->rsvp_event( array( 'fields' => array() ) );
		$this->respond( $keep, array() );
		$this->respond( $event, array( 'email' => 'grace@example.com' ) );
		$count  = static fn( string $table, int $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $id ) ); // phpcs:ignore WordPress.DB

		wp_trash_post( $event );
		$this->assertSame( 1, $count( \OpenEvents\Attendance\Schema::attendees(), $event ), 'Trash keeps attendees, so a restore is whole.' );

		wp_delete_post( $event, true );
		$this->assertSame( 0, $count( \OpenEvents\Attendance\Schema::attendees(), $event ) );
		$this->assertSame( 0, $count( \OpenEvents\Attendance\Schema::ticket_types(), $event ) );
		$this->assertSame( 1, $count( \OpenEvents\Attendance\Schema::attendees(), $keep ), 'Other events are untouched.' );
	}

	public function test_deleting_an_event_deletes_its_orders(): void {
		global $wpdb;
		$day   = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$event = $this->event(
			array(
				'_oe_start' => "{$day} 19:00:00",
				'_oe_end'   => "{$day} 21:00:00",
			)
		);
		update_post_meta(
			$event,
			TicketsModule::META_KEY,
			array(
				array(
					'name'     => 'Free',
					'price'    => '0',
					'capacity' => 10,
				),
			)
		);
		$this->rows( $event );
		$type   = current( ( new Checkout() )->ticket_types( $event ) );
		$result = ( new Checkout() )->place(
			$event,
			array(
				'name'    => 'Grace Hopper',
				'email'   => 'grace@example.com',
				'tickets' => array( $type->id => 2 ),
				'ts'      => Guard::sign_timestamp( time() - 10 ),
				'ip'      => '198.51.100.9',
			)
		);
		$this->assertTrue( $result->ok, $result->message );

		wp_delete_post( $event, true );
		foreach ( array( \OpenEvents\Attendance\Schema::orders(), \OpenEvents\Attendance\Schema::attendees(), \OpenEvents\Attendance\Schema::ticket_types() ) as $table ) {
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event ) ), $table ); // phpcs:ignore WordPress.DB
		}
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \OpenEvents\Attendance\Schema::order_items() ), 'Order lines go with their orders.' ); // phpcs:ignore WordPress.DB
	}
}
