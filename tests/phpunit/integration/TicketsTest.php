<?php
/**
 * Ticket checkout and order lifecycle.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Money;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Modules\Tickets\AbstractGateway;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\Gateways;
use OpenEvents\Tests\TestCase;

final class TicketsTest extends TestCase {

	private Checkout $checkout;
	private TicketTypes $types;

	public function set_up(): void {
		parent::set_up();
		$this->checkout = new Checkout();
		$this->types    = new TicketTypes();
		update_option( AbstractGateway::OPTION, array( 'manual' => array( 'enabled' => true ) ) );
		Gateways::reset();
		reset_phpmailer_instance();
	}

	/**
	 * Event in 10 days with ticket types: [ key => [price "12.50", capacity] ].
	 *
	 * @return array{0: int, 1: array<string, int>} Event id and type ids by key.
	 */
	private function ticketed_event( array $types ): array {
		$day = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$id  = $this->event( array( '_oe_start' => "{$day} 19:00:00", '_oe_end' => "{$day} 21:00:00" ) );
		$this->rows( $id );
		$ids = array();
		foreach ( $types as $key => list( $price, $capacity ) ) {
			$ids[ $key ] = $this->types->upsert(
				$id,
				$key,
				array(
					'kind'     => 'paid',
					'name'     => ucfirst( $key ),
					'capacity' => $capacity,
					'status'   => 'active',
					'settings' => array(
						'price_minor'   => Money::to_minor( $price, 'USD' ),
						'max_per_order' => 10,
					),
				)
			);
		}
		return array( $id, $ids );
	}

	private function place( int $event_id, array $tickets, array $input = array() ) {
		return $this->checkout->place(
			$event_id,
			array_merge(
				array(
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'tickets' => $tickets,
					'gateway' => 'manual',
					'ts'      => Guard::sign_timestamp( time() - 10 ),
					'ip'      => '203.0.113.' . wp_rand( 1, 250 ),
				),
				$input
			)
		);
	}

	private function sold( int $type_id ): int {
		return $this->types->get( $type_id )->sold;
	}

	public function test_money_uses_integer_minor_units(): void {
		$this->assertSame( 1250, Money::to_minor( '12.50', 'USD' ) );
		$this->assertSame( 1250, Money::to_minor( '12,5', 'EUR' ) );
		$this->assertSame( 1200, Money::to_minor( '1200', 'JPY' ), 'Zero-decimal currencies.' );
		$this->assertSame( 0, Money::to_minor( '-5', 'USD' ) );
		$this->assertSame( '12.50', Money::to_decimal( 1250, 'USD' ) );
		$this->assertSame( '1200', Money::to_decimal( 1200, 'JPY' ) );
	}

	public function test_free_tickets_complete_without_a_gateway(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'general' => array( '0', 50 ) ) );
		$result                = $this->place( $event, array( $types['general'] => 2 ), array( 'gateway' => '' ) );

		$this->assertTrue( $result->ok, $result->message );
		$this->assertSame( 'confirmed', $result->code );
		$this->assertSame( Order::COMPLETED, ( new Orders() )->get( $result->order->id )->status );
		$this->assertSame( 2, ( new Attendees() )->counts( $event )['going'] );
		$this->assertStringContainsString( 'Check-in codes', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_manual_gateway_reserves_tickets_as_unpaid_until_marked_paid(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'adult' => array( '20', 10 ), 'child' => array( '5', 10 ) ) );
		$result                = $this->place( $event, array( $types['adult'] => 2, $types['child'] => 1 ) );

		$order = ( new Orders() )->get( $result->order->id );
		$this->assertSame( Order::UNPAID, $order->status );
		$this->assertSame( 4500, $order->total_minor );
		$this->assertNull( $order->expires_utc, 'Unpaid manual orders keep their seats.' );
		$this->assertSame( 3, ( new Attendees() )->counts( $event )['going'] );
		$this->assertStringContainsString( 'Payment is due', tests_retrieve_phpmailer_instance()->get_sent()->body );

		$this->assertTrue( $this->checkout->mark_paid( $order ) );
		$this->assertSame( Order::COMPLETED, ( new Orders() )->get( $order->id )->status );
	}

	public function test_seat_holds_are_all_or_nothing(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'vip' => array( '50', 1 ), 'general' => array( '10', 10 ) ) );

		$result = $this->place( $event, array( $types['general'] => 3, $types['vip'] => 2 ) );
		$this->assertSame( 'full', $result->code );
		$this->assertSame( 'Only 1 “Vip” ticket is left.', $result->message );
		$this->assertSame( 0, $this->sold( $types['general'] ), 'The general seats taken first were given back.' );
		$this->assertSame( 0, $this->sold( $types['vip'] ) );
	}

	public function test_validation(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'general' => array( '10', 10 ) ) );

		$this->assertSame( 'invalid', $this->place( $event, array() )->code, 'At least one ticket.' );
		$this->assertSame( 'invalid', $this->place( $event, array( $types['general'] => 11 ) )->code, 'Per-order maximum.' );
		$this->assertSame( 'invalid', $this->place( $event, array( 999999 => 1 ) )->code, 'Unknown type.' );
		$this->assertSame( 'invalid', $this->place( $event, array( $types['general'] => 1 ), array( 'gateway' => 'stripe' ) )->code, 'Unconfigured gateway.' );
		$this->assertSame( 'invalid', $this->place( $event, array( $types['general'] => 1 ), array( 'email' => 'x' ) )->code );
		$this->assertSame( 'invalid', $this->place( $event, array( $types['general'] => 1 ), array( 'ts' => Guard::sign_timestamp() ) )->code, 'Too fast.' );
		$this->assertSame( 0, $this->sold( $types['general'] ) );
	}

	public function test_abandoned_checkouts_release_their_seats(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'general' => array( '10', 5 ) ) );
		update_option( AbstractGateway::OPTION, array( 'stripe' => array( 'enabled' => true, 'secret_key' => 'sk_test_x' ) ) );
		Gateways::reset();
		add_filter( 'pre_http_request', array( $this, 'fake_stripe_session' ), 10, 3 );

		$result = $this->place( $event, array( $types['general'] => 3 ), array( 'gateway' => 'stripe' ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_stripe_session' ), 10 );

		$this->assertSame( 'redirect', $result->code );
		$this->assertSame( 3, $this->sold( $types['general'] ), 'Seats are held while paying.' );

		( new Orders() )->update( $result->order->id, array( 'expires_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$this->assertSame( 1, $this->checkout->expire_due() );
		$this->assertSame( 0, $this->sold( $types['general'] ) );
		$this->assertSame( Order::EXPIRED, ( new Orders() )->get( $result->order->id )->status );
	}

	public function test_completion_is_idempotent(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'general' => array( '10', 5 ) ) );
		$order                 = $this->pending_order( $event, array( $types['general'] => 1 ) );

		$this->assertTrue( $this->checkout->complete( $order, 'pi_1' ) );
		$this->assertFalse( $this->checkout->complete( $order, 'pi_1' ), 'A second webhook does nothing.' );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent, 'One confirmation email.' );
	}

	public function test_late_payment_is_honoured_even_if_seats_were_resold(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'general' => array( '10', 1 ) ) );
		$order                 = $this->pending_order( $event, array( $types['general'] => 1 ) );
		( new Orders() )->update( $order->id, array( 'expires_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$this->checkout->expire_due();
		$this->assertTrue( $this->place( $event, array( $types['general'] => 1 ), array( 'email' => 'bob@example.com' ) )->ok, 'Seat resold.' );

		$this->assertTrue( $this->checkout->complete( $order, 'pi_late' ) );
		$fresh = ( new Orders() )->get( $order->id );
		$this->assertSame( Order::COMPLETED, $fresh->status, 'Never keep money without a ticket.' );
		$this->assertTrue( $fresh->meta['oversold'] ?? false, 'Flagged for the organiser.' );
	}

	public function test_refunds_free_seats_and_cancel_tickets(): void {
		list( $event, $types ) = $this->ticketed_event( array( 'general' => array( '10', 5 ) ) );
		$result                = $this->place( $event, array( $types['general'] => 2 ) );
		$order                 = ( new Orders() )->get( $result->order->id );

		$this->assertTrue( $this->checkout->refund( $order ) );
		$this->assertSame( Order::REFUNDED, ( new Orders() )->get( $order->id )->status );
		$this->assertSame( 0, $this->sold( $types['general'] ) );
		$this->assertSame( 0, ( new Attendees() )->counts( $event )['going'] );
		$this->assertInstanceOf( \WP_Error::class, $this->checkout->refund( ( new Orders() )->get( $order->id ) ), 'No double refunds.' );
	}

	/**
	 * A pending order as if a hosted gateway were mid-payment.
	 */
	private function pending_order( int $event, array $tickets ): Order {
		update_option( AbstractGateway::OPTION, array( 'stripe' => array( 'enabled' => true, 'secret_key' => 'sk_test_x' ) ) );
		Gateways::reset();
		add_filter( 'pre_http_request', array( $this, 'fake_stripe_session' ), 10, 3 );
		$result = $this->place( $event, $tickets, array( 'gateway' => 'stripe' ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_stripe_session' ), 10 );
		update_option( AbstractGateway::OPTION, array( 'manual' => array( 'enabled' => true ), 'stripe' => array( 'enabled' => true, 'secret_key' => 'sk_test_x' ) ) );
		Gateways::reset();
		return ( new Orders() )->get( $result->order->id );
	}

	public function fake_stripe_session( $pre, array $args, string $url ) {
		if ( str_contains( $url, 'api.stripe.com/v1/checkout/sessions' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => 'cs_test_' . wp_generate_password( 8, false ), 'url' => 'https://checkout.stripe.com/c/pay/x' ) ),
				'headers'  => array(),
			);
		}
		return $pre;
	}
}
