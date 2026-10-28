<?php
/**
 * Stripe Checkout.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets\Gateways;

use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Modules\Tickets\AbstractGateway;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\PaymentResult;

/**
 * Hosted Stripe Checkout: the buyer pays on stripe.com, so card data never
 * touches the site. Confirmed on return (by retrieving the session) and by
 * signed webhooks; whichever arrives first completes the order.
 */
final class Stripe extends AbstractGateway {

	private const API       = 'https://api.stripe.com/v1/';
	private const TOLERANCE = 300; // Webhook timestamp tolerance, seconds.

	public function id(): string {
		return 'stripe';
	}

	public function label(): string {
		return __( 'Card (Stripe)', 'openevents' );
	}

	public function description(): string {
		return __( 'Pay securely by card, Apple Pay, or Google Pay on Stripe.', 'openevents' );
	}

	public function is_available( string $currency ): bool {
		return $this->enabled() && '' !== (string) $this->setting( 'secret_key' );
	}

	public function start_payment( Order $order ): PaymentResult {
		$body = array(
			'mode'                => 'payment',
			'success_url'         => $this->return_url( $order, 'success' ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => $this->return_url( $order, 'cancel' ),
			'customer_email'      => $order->email,
			'client_reference_id' => $order->uuid,
			'metadata'            => array( 'order' => $order->uuid ),
			// Stripe's minimum is 30 minutes; our hold is shorter and late payments are honoured.
			'expires_at'          => time() + 31 * MINUTE_IN_SECONDS,
			'line_items'          => array(),
		);
		$types = new TicketTypes();
		foreach ( ( new Orders() )->items( $order->id ) as $item ) {
			$type                 = $types->get( (int) $item->ticket_type_id );
			$body['line_items'][] = array(
				'quantity'   => (int) $item->quantity,
				'price_data' => array(
					'currency'     => strtolower( $order->currency ),
					'unit_amount'  => (int) $item->unit_price_minor,
					'product_data' => array(
						'name' => wp_strip_all_tags( get_the_title( $order->event_id ) ) . ' — ' . ( $type ? $type->name : __( 'Ticket', 'openevents' ) ),
					),
				),
			);
		}

		$session = $this->request( 'POST', 'checkout/sessions', $body, 'oe-' . $order->uuid );
		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			return PaymentResult::failed( is_wp_error( $session ) ? $session->get_error_message() : __( 'Stripe did not return a checkout page.', 'openevents' ) );
		}
		return PaymentResult::redirect( $session['url'], (string) $session['id'] );
	}

	public function handle_return( Order $order, array $query ): PaymentResult {
		if ( 'cancel' === ( $query['oe_checkout'] ?? '' ) ) {
			return PaymentResult::failed( __( 'Payment was cancelled. Your tickets were not reserved.', 'openevents' ) );
		}
		$session_id = (string) ( $query['session_id'] ?? '' );
		if ( '' === $session_id || $session_id !== $order->gateway_ref ) {
			return PaymentResult::pending();
		}
		$session = $this->request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( ! is_wp_error( $session ) && 'paid' === ( $session['payment_status'] ?? '' ) && $this->matches( $session, $order ) ) {
			return PaymentResult::paid( (string) ( $session['payment_intent'] ?? '' ), $session_id );
		}
		return PaymentResult::pending();
	}

	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_body();
		if ( ! $this->verify_signature( $payload, (string) $request->get_header( 'stripe-signature' ) ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$event    = json_decode( $payload, true );
		$object   = $event['data']['object'] ?? array();
		$orders   = new Orders();
		$checkout = new Checkout();

		switch ( $event['type'] ?? '' ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$order = $orders->by_gateway_ref( $this->id(), (string) ( $object['id'] ?? '' ) );
				if ( $order && 'paid' === ( $object['payment_status'] ?? '' ) && $this->matches( $object, $order ) ) {
					$checkout->complete( $order, (string) ( $object['payment_intent'] ?? '' ) );
				}
				break;
			case 'checkout.session.expired':
			case 'checkout.session.async_payment_failed':
				$order = $orders->by_gateway_ref( $this->id(), (string) ( $object['id'] ?? '' ) );
				if ( $order ) {
					$checkout->release( $order, 'checkout.session.expired' === $event['type'] ? Order::EXPIRED : Order::FAILED );
				}
				break;
			case 'charge.refunded':
				// Refunded from the Stripe dashboard.
				$order = $this->by_payment_intent( (string) ( $object['payment_intent'] ?? '' ) );
				if ( $order && ! empty( $object['refunded'] ) ) {
					$checkout->refunded_externally( $order );
				}
				break;
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	public function refund( Order $order ): true|\WP_Error {
		if ( '' === $order->gateway_txn ) {
			return new \WP_Error( 'openevents_refund_missing_payment', __( 'No Stripe payment is recorded for this order.', 'openevents' ) );
		}
		$refund = $this->request( 'POST', 'refunds', array( 'payment_intent' => $order->gateway_txn ), 'oe-refund-' . $order->uuid );
		return is_wp_error( $refund ) ? $refund : true;
	}

	public function settings_fields(): array {
		return parent::settings_fields() + array(
			'secret_key'     => array(
				'label' => __( 'Secret key', 'openevents' ),
				'type'  => 'secret',
				'help'  => __( 'From Stripe → Developers → API keys. Starts with sk_test_ or sk_live_. A restricted key needs Checkout Sessions and Refunds write access.', 'openevents' ),
			),
			'webhook_secret' => array(
				'label' => __( 'Webhook signing secret', 'openevents' ),
				'type'  => 'secret',
				/* translators: %s: webhook URL */
				'help'  => sprintf( __( 'Add a webhook endpoint at %s for checkout.session.* and charge.refunded events, then paste its whsec_ secret.', 'openevents' ), $this->webhook_url() ),
			),
		);
	}

	public function is_test_mode(): bool {
		return str_starts_with( (string) $this->setting( 'secret_key' ), 'sk_test_' );
	}

	/**
	 * Stripe-Signature: t=<timestamp>,v1=<hmac>. HMAC-SHA256 of "<t>.<payload>".
	 */
	public function verify_signature( string $payload, string $header ): bool {
		$secret = (string) $this->setting( 'webhook_secret' );
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			list( $key, $value ) = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
			if ( 't' === $key ) {
				$timestamp = (int) $value;
			} elseif ( 'v1' === $key ) {
				$signatures[] = $value;
			}
		}
		if ( ! $timestamp || abs( time() - $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The session really is for this order and amount.
	 *
	 * @param array<string, mixed> $session Checkout Session.
	 */
	private function matches( array $session, Order $order ): bool {
		return ( $session['client_reference_id'] ?? '' ) === $order->uuid
			&& (int) ( $session['amount_total'] ?? -1 ) === $order->total_minor
			&& strtoupper( (string) ( $session['currency'] ?? '' ) ) === $order->currency;
	}

	private function by_payment_intent( string $intent ): ?Order {
		global $wpdb;
		if ( '' === $intent ) {
			return null;
		}
		$table = \OpenEvents\Attendance\Schema::orders();
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = 'stripe' AND gateway_txn = %s", $intent ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? ( new Orders() )->get( (int) $id ) : null;
	}

	/**
	 * @param array<string, mixed> $body Form fields (nested arrays use Stripe's bracket syntax).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, array $body = array(), string $idempotency = '' ) {
		$headers = array( 'Authorization' => 'Bearer ' . $this->setting( 'secret_key' ) );
		if ( $idempotency ) {
			$headers['Idempotency-Key'] = $idempotency;
		}
		$response = wp_remote_request(
			self::API . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => 'GET' === $method ? null : http_build_query( $body ),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new \WP_Error( 'openevents_stripe', (string) ( $data['error']['message'] ?? __( 'Stripe request failed.', 'openevents' ) ), array( 'status' => $code ) );
		}
		return $data;
	}
}
