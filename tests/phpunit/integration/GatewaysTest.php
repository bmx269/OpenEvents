<?php
/**
 * Stripe and PayPal gateways against mocked provider APIs.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Modules\Tickets\AbstractGateway;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\Gateways;
use OpenEvents\Modules\Tickets\Gateways\PayPal;
use OpenEvents\Modules\Tickets\Gateways\Stripe;
use OpenEvents\Tests\TestCase;

final class GatewaysTest extends TestCase {

	/** @var array<int, array{url: string, args: array}> */
	private array $requests = array();

	/** @var array<string, callable> URL fragment => fn( args ) => [ code, body ] */
	private array $routes = array();

	private int $event;
	private int $type;

	public function set_up(): void {
		parent::set_up();
		update_option(
			AbstractGateway::OPTION,
			array(
				'stripe' => array( 'enabled' => true, 'secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test' ),
				'paypal' => array( 'enabled' => true, 'mode' => 'sandbox', 'client_id' => 'cid', 'client_secret' => 'sec', 'webhook_id' => 'WH-1' ),
			)
		);
		Gateways::reset();
		reset_phpmailer_instance();
		add_filter( 'pre_http_request', array( $this, 'route' ), 10, 3 );

		$day        = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$this->event = $this->event( array( '_oe_start' => "{$day} 19:00:00" ), array( 'post_title' => 'Gala' ) );
		$this->rows( $this->event );
		$this->type = ( new TicketTypes() )->upsert(
			$this->event,
			'general',
			array( 'kind' => 'paid', 'name' => 'General', 'capacity' => 10, 'status' => 'active', 'settings' => array( 'price_minor' => 2500, 'max_per_order' => 10 ) )
		);
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'route' ), 10 );
		parent::tear_down();
	}

	public function route( $pre, array $args, string $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		foreach ( $this->routes as $fragment => $handler ) {
			if ( str_contains( $url, $fragment ) ) {
				list( $code, $body ) = $handler( $args );
				return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ), 'headers' => array() );
			}
		}
		return array( 'response' => array( 'code' => 500 ), 'body' => '{}', 'headers' => array() );
	}

	private function place( string $gateway, int $quantity = 2 ) {
		return ( new Checkout() )->place(
			$this->event,
			array( 'name' => 'Ada', 'email' => 'ada@example.com', 'tickets' => array( $this->type => $quantity ), 'gateway' => $gateway, 'ts' => Guard::sign_timestamp( time() - 10 ), 'ip' => '203.0.113.9' )
		);
	}

	private function last_request( string $fragment ): array {
		foreach ( array_reverse( $this->requests ) as $r ) {
			if ( str_contains( $r['url'], $fragment ) ) {
				return $r;
			}
		}
		$this->fail( "No request to {$fragment}" );
	}

	private function order( $result ): Order {
		return ( new Orders() )->get( $result->order->id );
	}

	// ── Stripe ────────────────────────────────────────────────────────────

	private function stripe_session_routes( string $status = 'paid', int $amount = 5000 ): void {
		$this->routes['/v1/checkout/sessions/cs_1'] = static fn() => array( 200, array( 'id' => 'cs_1', 'payment_status' => $status, 'payment_intent' => 'pi_1', 'amount_total' => $amount, 'currency' => 'usd', 'client_reference_id' => $GLOBALS['oe_test_uuid'] ?? '' ) );
		$this->routes['/v1/checkout/sessions']      = static fn() => array( 200, array( 'id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1' ) );
	}

	public function test_stripe_creates_a_checkout_session_and_redirects(): void {
		$this->stripe_session_routes();
		$result = $this->place( 'stripe' );

		$this->assertSame( 'redirect', $result->code );
		$this->assertSame( 'https://checkout.stripe.com/c/pay/cs_1', $result->redirect );
		$this->assertSame( 'cs_1', $this->order( $result )->gateway_ref );

		$request = $this->last_request( '/v1/checkout/sessions' );
		parse_str( $request['args']['body'], $body );
		$this->assertSame( 'Bearer sk_test_123', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'oe-' . $result->order->uuid, $request['args']['headers']['Idempotency-Key'] );
		$this->assertSame( '2500', $body['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( '2', $body['line_items'][0]['quantity'] );
		$this->assertSame( 'usd', $body['line_items'][0]['price_data']['currency'] );
		$this->assertSame( $result->order->uuid, $body['client_reference_id'] );
		$this->assertStringContainsString( 'session_id={CHECKOUT_SESSION_ID}', $body['success_url'] );
	}

	public function test_stripe_return_confirms_with_stripe_before_completing(): void {
		$this->stripe_session_routes();
		$result                  = $this->place( 'stripe' );
		$GLOBALS['oe_test_uuid'] = $result->order->uuid;
		$gateway                 = Gateways::get( 'stripe' );

		$this->assertSame( 'pending', $gateway->handle_return( $this->order( $result ), array( 'oe_checkout' => 'success', 'session_id' => 'cs_other' ) )->status, 'A different session id is ignored.' );

		$paid = $gateway->handle_return( $this->order( $result ), array( 'oe_checkout' => 'success', 'session_id' => 'cs_1' ) );
		$this->assertSame( 'paid', $paid->status );
		$this->assertSame( 'pi_1', $paid->txn );

		$this->stripe_session_routes( 'paid', 100 ); // Amount doesn't match the order.
		$this->assertSame( 'pending', $gateway->handle_return( $this->order( $result ), array( 'oe_checkout' => 'success', 'session_id' => 'cs_1' ) )->status );
		unset( $GLOBALS['oe_test_uuid'] );
	}

	private function stripe_webhook( array $event, ?int $time = null, string $secret = 'whsec_test' ): \WP_REST_Response {
		$payload = wp_json_encode( $event );
		$time  ??= time();
		$request = new \WP_REST_Request( 'POST', '/openevents/v1/gateways/stripe/webhook' );
		$request->set_body( $payload );
		$request->set_header( 'stripe-signature', 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, $secret ) );
		return Gateways::get( 'stripe' )->handle_webhook( $request );
	}

	public function test_stripe_webhooks_are_signed_and_complete_orders_once(): void {
		$this->stripe_session_routes();
		$result  = $this->place( 'stripe' );
		$session = array( 'id' => 'cs_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_1', 'amount_total' => 5000, 'currency' => 'usd', 'client_reference_id' => $result->order->uuid );
		$event   = array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => $session ) );

		$this->assertSame( 400, $this->stripe_webhook( $event, null, 'whsec_wrong' )->get_status(), 'Forged signature.' );
		$this->assertSame( 400, $this->stripe_webhook( $event, time() - 600 )->get_status(), 'Replayed old event.' );
		$this->assertSame( Order::PENDING, $this->order( $result )->status );

		$this->assertSame( 200, $this->stripe_webhook( $event )->get_status() );
		$this->assertSame( Order::COMPLETED, $this->order( $result )->status );
		$this->assertSame( 'pi_1', $this->order( $result )->gateway_txn );

		$this->stripe_webhook( $event );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent, 'Duplicate delivery sends no second email.' );

		// Refunded from the Stripe dashboard.
		$this->stripe_webhook( array( 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'payment_intent' => 'pi_1', 'refunded' => true ) ) ) );
		$this->assertSame( Order::REFUNDED, $this->order( $result )->status );
		$this->assertSame( 0, ( new TicketTypes() )->get( $this->type )->sold );
	}

	public function test_stripe_expired_session_releases_seats(): void {
		$this->stripe_session_routes();
		$result = $this->place( 'stripe' );
		$this->stripe_webhook( array( 'type' => 'checkout.session.expired', 'data' => array( 'object' => array( 'id' => 'cs_1' ) ) ) );

		$this->assertSame( Order::EXPIRED, $this->order( $result )->status );
		$this->assertSame( 0, ( new TicketTypes() )->get( $this->type )->sold );
	}

	public function test_stripe_refund_calls_the_refunds_api(): void {
		$this->stripe_session_routes();
		$this->routes['/v1/refunds'] = static fn() => array( 200, array( 'id' => 're_1', 'status' => 'succeeded' ) );
		$result                      = $this->place( 'stripe' );
		( new Checkout() )->complete( $this->order( $result ), 'pi_1' );

		$this->assertTrue( ( new Checkout() )->refund( $this->order( $result ) ) );
		parse_str( $this->last_request( '/v1/refunds' )['args']['body'], $body );
		$this->assertSame( 'pi_1', $body['payment_intent'] );
	}

	public function test_stripe_failure_releases_seats_and_reports_the_error(): void {
		$this->routes['/v1/checkout/sessions'] = static fn() => array( 400, array( 'error' => array( 'message' => 'Invalid currency' ) ) );
		$result                                = $this->place( 'stripe' );

		$this->assertSame( 'payment_failed', $result->code );
		$this->assertSame( 'Invalid currency', $result->message );
		$this->assertSame( 0, ( new TicketTypes() )->get( $this->type )->sold );
	}

	// ── PayPal ────────────────────────────────────────────────────────────

	private function paypal_routes( string $capture_value = '50.00', string $uuid = '' ): void {
		$this->routes['/v1/oauth2/token']                       = static fn() => array( 200, array( 'access_token' => 'A21', 'expires_in' => 32400 ) );
		$this->routes['/v2/checkout/orders/PP-1/capture']       = static fn() => array(
			201,
			array(
				'id'             => 'PP-1',
				'status'         => 'COMPLETED',
				'purchase_units' => array(
					array(
						'reference_id' => $uuid ?: ( $GLOBALS['oe_test_uuid'] ?? '' ),
						'payments'     => array( 'captures' => array( array( 'id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => array( 'currency_code' => 'USD', 'value' => $capture_value ) ) ) ),
					),
				),
			),
		);
		$this->routes['/v2/checkout/orders']                    = static fn() => array( 201, array( 'id' => 'PP-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => array( array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PP-1' ) ) ) );
		$this->routes['/v1/notifications/verify-webhook-signature'] = static fn( $args ) => array( 200, array( 'verification_status' => 'transmission-sig-ok' === ( json_decode( $args['body'], true )['transmission_sig'] ?? '' ) ? 'SUCCESS' : 'FAILURE' ) );
		$this->routes['/v2/payments/captures/CAP-1/refund']     = static fn() => array( 201, array( 'id' => 'REF-1', 'status' => 'COMPLETED' ) );
	}

	public function test_paypal_creates_an_order_and_redirects_to_approval(): void {
		$this->paypal_routes();
		$result = $this->place( 'paypal' );

		$this->assertSame( 'redirect', $result->code );
		$this->assertSame( 'https://www.sandbox.paypal.com/checkoutnow?token=PP-1', $result->redirect );
		$this->assertSame( 'PP-1', $this->order( $result )->gateway_ref );

		$request = $this->last_request( '/v2/checkout/orders' );
		$body    = json_decode( $request['args']['body'], true );
		$this->assertStringStartsWith( 'https://api-m.sandbox.paypal.com', $request['url'], 'Sandbox mode.' );
		$this->assertSame( 'Bearer A21', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'oe-' . $result->order->uuid, $request['args']['headers']['PayPal-Request-Id'] );
		$this->assertSame( 'CAPTURE', $body['intent'] );
		$this->assertSame( '50.00', $body['purchase_units'][0]['amount']['value'] );
		$this->assertSame( '25.00', $body['purchase_units'][0]['items'][0]['unit_amount']['value'] );
		$this->assertSame( 'NO_SHIPPING', $body['payment_source']['paypal']['experience_context']['shipping_preference'] );
	}

	public function test_paypal_return_captures_and_checks_the_amount(): void {
		$this->paypal_routes();
		$result                  = $this->place( 'paypal' );
		$GLOBALS['oe_test_uuid'] = $result->order->uuid;
		$gateway                 = Gateways::get( 'paypal' );

		$this->assertSame( 'pending', $gateway->handle_return( $this->order( $result ), array( 'oe_checkout' => 'success', 'token' => 'PP-OTHER' ) )->status );

		$paid = $gateway->handle_return( $this->order( $result ), array( 'oe_checkout' => 'success', 'token' => 'PP-1' ) );
		$this->assertSame( 'paid', $paid->status );
		$this->assertSame( 'CAP-1', $paid->txn );

		$this->paypal_routes( '1.00', $result->order->uuid ); // Underpaid capture.
		$this->assertSame( 'pending', $gateway->handle_return( $this->order( $result ), array( 'oe_checkout' => 'success', 'token' => 'PP-1' ) )->status );
		unset( $GLOBALS['oe_test_uuid'] );
	}

	public function test_paypal_access_token_is_cached(): void {
		$this->paypal_routes();
		$this->place( 'paypal' );
		$this->place( 'paypal', 1 );

		$tokens = array_filter( $this->requests, static fn( $r ) => str_contains( $r['url'], '/v1/oauth2/token' ) );
		$this->assertCount( 1, $tokens );
	}

	private function paypal_webhook( array $event, string $signature = 'transmission-sig-ok' ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/openevents/v1/gateways/paypal/webhook' );
		$request->set_body( wp_json_encode( $event ) );
		foreach ( array( 'paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.paypal.com/cert', 'paypal-transmission-id' => 't1', 'paypal-transmission-sig' => $signature, 'paypal-transmission-time' => gmdate( 'c' ) ) as $k => $v ) {
			$request->set_header( $k, $v );
		}
		return Gateways::get( 'paypal' )->handle_webhook( $request );
	}

	public function test_paypal_webhooks_are_verified_and_capture_abandoned_approvals(): void {
		$this->paypal_routes();
		$result                  = $this->place( 'paypal' );
		$GLOBALS['oe_test_uuid'] = $result->order->uuid;
		$approved                = array( 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => array( 'id' => 'PP-1' ) );

		$this->assertSame( 400, $this->paypal_webhook( $approved, 'forged' )->get_status() );
		$this->assertSame( Order::PENDING, $this->order( $result )->status );

		$this->assertSame( 200, $this->paypal_webhook( $approved )->get_status() );
		$this->assertSame( Order::COMPLETED, $this->order( $result )->status, 'Captured even though the buyer never came back.' );

		$this->paypal_webhook( array( 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => array( 'id' => 'CAP-1' ) ) );
		$this->assertSame( Order::REFUNDED, $this->order( $result )->status );
		unset( $GLOBALS['oe_test_uuid'] );
	}

	public function test_paypal_refund_calls_the_captures_api(): void {
		$this->paypal_routes();
		$result = $this->place( 'paypal' );
		( new Checkout() )->complete( $this->order( $result ), 'CAP-1' );

		$this->assertTrue( ( new Checkout() )->refund( $this->order( $result ) ) );
		$this->assertSame( 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAP-1/refund', $this->last_request( '/refund' )['url'] );
	}

	public function test_gateways_need_credentials_and_constants_win(): void {
		update_option( AbstractGateway::OPTION, array( 'stripe' => array( 'enabled' => true ) ) );
		$this->assertFalse( ( new Stripe() )->is_available( 'USD' ), 'No key, not available.' );
		$this->assertFalse( ( new PayPal() )->is_available( 'USD' ) );
	}
}
