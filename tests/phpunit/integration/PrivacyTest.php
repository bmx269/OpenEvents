<?php
/**
 * Privacy exporter and eraser for attendance data.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Privacy;
use OpenEvents\Attendance\Schema;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Attendance\Waitlist;
use OpenEvents\Modules\Rsvp\Service;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\Module as Tickets;
use OpenEvents\Tests\TestCase;

final class PrivacyTest extends TestCase {

	private int $event;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$day         = current_datetime()->modify( '+5 days' )->format( 'Y-m-d' );
		$this->event = $this->event(
			array(
				'_oe_start' => "{$day} 19:00:00",
				'_oe_end'   => "{$day} 21:00:00",
			)
		);
		update_post_meta(
			$this->event,
			Service::META_KEY,
			array(
				'enabled' => true,
				'fields'  => array(
					array(
						'type'  => 'tel',
						'label' => 'Phone number',
					),
				),
			)
		);
		update_post_meta(
			$this->event,
			Tickets::META_KEY,
			array(
				array(
					'name'     => 'Free',
					'price'    => '0',
					'capacity' => 10,
				),
			)
		);
		$this->rows( $this->event );
	}

	private function rsvp( string $email, string $name = 'Grace Hopper' ): void {
		( new Service() )->respond(
			$this->event,
			array(
				'name'      => $name,
				'email'     => $email,
				'status'    => 'going',
				'quantity'  => 1,
				'attendees' => array( array( 'fields' => array( 'phone-number' => '604 555 0100' ) ) ),
				'ts'        => Service::sign_timestamp( time() - 10 ),
				'ip'        => '203.0.113.' . wp_rand( 1, 250 ),
			)
		);
	}

	private function order( string $email ): void {
		$type = current( ( new Checkout() )->ticket_types( $this->event ) );
		( new Checkout() )->place(
			$this->event,
			array(
				'name'    => 'Grace Hopper',
				'email'   => $email,
				'tickets' => array( $type->id => 1 ),
				'ts'      => Guard::sign_timestamp( time() - 10 ),
				'ip'      => '198.51.100.' . wp_rand( 1, 250 ),
			)
		);
	}

	public function test_tools_are_registered(): void {
		$this->assertArrayHasKey( 'openevents-attendance', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'openevents-attendance', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_export_includes_attendees_orders_and_answers(): void {
		$this->rsvp( 'grace@example.com' );
		$this->order( 'grace@example.com' );
		$this->rsvp( 'someone@example.com', 'Ada Lovelace' );

		$export = Privacy::export( 'grace@example.com' );
		$groups = array_count_values( array_column( $export['data'], 'group_id' ) );

		$this->assertTrue( $export['done'] );
		$this->assertSame( 2, $groups['openevents-attendees'], 'The RSVP and the ticket holder.' );
		$this->assertSame( 1, $groups['openevents-orders'] );
		$values = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'grace@example.com', $values['Email'] );
		$this->assertSame( '604 555 0100', $values['Phone number'], 'Answers are exported under their question.' );
	}

	public function test_erase_anonymises_and_keeps_counts(): void {
		global $wpdb;
		$this->rsvp( 'grace@example.com' );
		$this->order( 'grace@example.com' );
		$this->rsvp( 'someone@example.com', 'Ada Lovelace' );
		$type = ( new TicketTypes() )->find( $this->event, Service::TYPE_KEY );
		( new Waitlist() )->add(
			array(
				'ticket_type_id' => $type->id,
				'event_id'       => $this->event,
				'name'           => 'Grace Hopper',
				'email'          => 'grace@example.com',
				'quantity'       => 1,
			)
		);
		$sold = $type->sold;

		$result = Privacy::erase( 'grace@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::attendees() . ' WHERE email = %s OR name = %s', 'grace@example.com', 'Grace Hopper' ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::orders() . ' WHERE email = %s', 'grace@example.com' ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::waitlist() . ' WHERE email = %s', 'grace@example.com' ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::attendees() . " WHERE fields LIKE '%604%' AND email <> 'someone@example.com'" ) ); // phpcs:ignore WordPress.DB

		$this->assertSame( $sold, ( new TicketTypes() )->recount( $type->id ), 'Rows stay, so seats stay counted.' );
		$this->assertCount( 1, ( new Attendees() )->query( array( 'search' => 'someone@example.com' ) )['items'], 'Other people are untouched.' );
		$this->assertSame( array(), Privacy::export( 'grace@example.com' )['data'] );
	}
}
