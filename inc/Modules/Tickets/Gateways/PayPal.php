<?php
/**
 * PayPal Checkout (Orders v2).
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets\Gateways;

use OpenEvents\Attendance\Money;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Modules\Tickets\AbstractGateway;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\PaymentResult;

/**
 * The buyer approves on paypal.com and returns; the site then captures the
 * payment and checks the captured amount. A verified webhook also captures
 * approved orders whose buyers never came back, and records refunds made in
 * PayPal.
 */
final class PayPal extends AbstractGateway {

	private const LIVE    = 'https://api-m.paypal.com';
	private const SANDBOX = 'https://api-m.sandbox.paypal.com';

	public function id(): string {
		return 'paypal';
	}

	public function label(): string {
		return __( 'PayPal', 'openevents' );
	}

	public function description(): string {
		return __( 'Pay with your PayPal account, Venmo, or a card on PayPal.', 'openevents' );
	}

	public function is_available( string $currency ): bool {
		return $this->enabled() && '' !== (string) $this->setting( 'client_id' ) && '' !== (string) $this->setting( 'client_secret' );
	}

	public function start_payment( Order $order ): PaymentResult {
		$items = array();
		$types = new TicketTypes();
		foreach ( ( new Orders() )->items( $order->id ) as $item ) {
			$type    = $types->get( (int) $item->ticket_type_id );
			$items[] = array(
				'name'        => mb_substr( $type ? $type->name : __( 'Ticket', 'openevents' ), 0, 127 ),
				'quantity'    => (string) (int) $item->quantity,
				'unit_amount' => array(
					'currency_code' => $order->currency,
					'value'         => Money::to_decimal( (int) $item->unit_price_minor, $order->currency ),
				),
				'category'    => 'DIGITAL_GOODS',
			);
		}
		$total = Money::to_decimal( $order->total_minor, $order->currency );

		$paypal = $this->request(
			'POST',
			'/v2/checkout/orders',
			array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array(
					array(
						'reference_id' => $order->uuid,
						'custom_id'    => $order->uuid,
						'description'  => mb_substr( wp_strip_all_tags( get_the_title( $order->event_id ) ), 0, 127 ),
						'amount'       => array(
							'currency_code' => $order->currency,
							'value'         => $total,
							'breakdown'     => array(
								'item_total' => array(
									'currency_code' => $order->currency,
									'value'         => $total,
								),
							),
						),
						'items'        => $items,
					),
				),
				'payment_source' => array(
					'paypal' => array(
						'experience_context' => array(
							'brand_name'          => mb_substr( wp_strip_all_tags( get_bloginfo( 'name' ) ), 0, 127 ),
							'user_action'         => 'PAY_NOW',
							'shipping_preference' => 'NO_SHIPPING',
							'return_url'          => $this->return_url( $order, 'success' ),
							'cancel_url'          => $this->return_url( $order, 'cancel' ),
						),
					),
				),
			),
			'oe-' . $order->uuid
		);
		if ( is_wp_error( $paypal ) ) {
			return PaymentResult::failed( $paypal->get_error_message() );
		}
		foreach ( (array) ( $paypal['links'] ?? array() ) as $link ) {
			if ( in_array( $link['rel'] ?? '', array( 'payer-action', 'approve' ), true ) ) {
				return PaymentResult::redirect( (string) $link['href'], (string) $paypal['id'] );
			}
		}
		return PaymentResult::failed( __( 'PayPal did not return an approval page.', 'openevents' ) );
	}

	public function handle_return( Order $order, array $query ): PaymentResult {
		if ( 'cancel' === ( $query['oe_checkout'] ?? '' ) ) {
			return PaymentResult::failed( __( 'Payment was cancelled. Your tickets were not reserved.', 'openevents' ) );
		}
		// PayPal appends ?token=<PayPal order id>.
		if ( ( $query['token'] ?? '' ) !== $order->gateway_ref ) {
			return PaymentResult::pending();
		}
		return $this->capture( $order );
	}

	/**
	 * Captures an approved PayPal order and checks it paid what we expect.
	 */
	public function capture( Order $order ): PaymentResult {
		$result = $this->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $order->gateway_ref ) . '/capture', null, 'oe-capture-' . $order->uuid );
		if ( is_wp_error( $result ) && 'ORDER_ALREADY_CAPTURED' === $result->get_error_code() ) {
			$result = $this->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $order->gateway_ref ) );
		}
		if ( is_wp_error( $result ) ) {
			return PaymentResult::pending();
		}
		$capture = $result['purchase_units'][0]['payments']['captures'][0] ?? array();
		if ( 'COMPLETED' === ( $result['status'] ?? '' ) && 'COMPLETED' === ( $capture['status'] ?? '' ) && $this->matches( $capture, $result, $order ) ) {
			return PaymentResult::paid( (string) $capture['id'], $order->gateway_ref );
		}
		if ( 'DECLINED' === ( $capture['status'] ?? '' ) ) {
			return PaymentResult::failed( __( 'PayPal declined the payment.', 'openevents' ) );
		}
		return PaymentResult::pending();
	}

	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$event = json_decode( $request->get_body(), true );
		if ( ! is_array( $event ) || ! $this->verify_webhook( $request, $event ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$resource = $event['resource'] ?? array();
		$orders   = new Orders();
		$checkout = new Checkout();

		switch ( $event['event_type'] ?? '' ) {
			case 'CHECKOUT.ORDER.APPROVED':
				// The buyer approved but never returned to the site: capture now.
				$order = $orders->by_gateway_ref( $this->id(), (string) ( $resource['id'] ?? '' ) );
				if ( $order && ! $order->is_final() ) {
					$result = $this->capture( $order );
					if ( 'paid' === $result->status ) {
						$checkout->complete( $order, $result->txn );
					}
				}
				break;
			case 'PAYMENT.CAPTURE.COMPLETED':
				$order = $orders->by_gateway_ref( $this->id(), (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' ) );
				if ( $order && ( $resource['custom_id'] ?? '' ) === $order->uuid && Money::to_minor( (string) ( $resource['amount']['value'] ?? '' ), $order->currency ) === $order->total_minor ) {
					$checkout->complete( $order, (string) ( $resource['id'] ?? '' ) );
				}
				break;
			case 'PAYMENT.CAPTURE.DENIED':
				$order = $orders->by_gateway_ref( $this->id(), (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' ) );
				if ( $order ) {
					$checkout->release( $order, Order::FAILED );
				}
				break;
			case 'PAYMENT.CAPTURE.REFUNDED':
				$order = $this->by_capture( (string) ( $resource['id'] ?? '' ) );
				if ( $order ) {
					$checkout->refunded_externally( $order );
				}
				break;
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	public function refund( Order $order ): true|\WP_Error {
		if ( '' === $order->gateway_txn ) {
			return new \WP_Error( 'openevents_refund_missing_payment', __( 'No PayPal capture is recorded for this order.', 'openevents' ) );
		}
		$refund = $this->request( 'POST', '/v2/payments/captures/' . rawurlencode( $order->gateway_txn ) . '/refund', null, 'oe-refund-' . $order->uuid );
		return is_wp_error( $refund ) ? $refund : true;
	}

	public function settings_fields(): array {
		return parent::settings_fields() + array(
			'mode'          => array(
				'label'   => __( 'Mode', 'openevents' ),
				'type'    => 'select',
				'options' => array(
					'sandbox' => __( 'Sandbox (testing)', 'openevents' ),
					'live'    => __( 'Live', 'openevents' ),
				),
			),
			'client_id'     => array(
				'label' => __( 'Client ID', 'openevents' ),
				'type'  => 'text',
				'help'  => __( 'From developer.paypal.com → Apps & Credentials, for the mode above.', 'openevents' ),
			),
			'client_secret' => array(
				'label' => __( 'Secret', 'openevents' ),
				'type'  => 'secret',
			),
			'webhook_id'    => array(
				'label' => __( 'Webhook ID', 'openevents' ),
				'type'  => 'text',
				/* translators: %s: webhook URL */
				'help'  => sprintf( __( 'Add a webhook at %s for CHECKOUT.ORDER.APPROVED and PAYMENT.CAPTURE.* events, then paste its ID.', 'openevents' ), $this->webhook_url() ),
			),
		);
	}

	private function base(): string {
		return 'live' === $this->setting( 'mode', 'sandbox' ) ? self::LIVE : self::SANDBOX;
	}

	/**
	 * Asks PayPal whether the webhook's signature headers are genuine.
	 *
	 * @param array<string, mixed> $event Decoded webhook body.
	 */
	private function verify_webhook( \WP_REST_Request $request, array $event ): bool {
		$webhook_id = (string) $this->setting( 'webhook_id' );
		if ( '' === $webhook_id ) {
			return false;
		}
		$result = $this->request(
			'POST',
			'/v1/notifications/verify-webhook-signature',
			array(
				'auth_algo'         => (string) $request->get_header( 'paypal-auth-algo' ),
				'cert_url'          => (string) $request->get_header( 'paypal-cert-url' ),
				'transmission_id'   => (string) $request->get_header( 'paypal-transmission-id' ),
				'transmission_sig'  => (string) $request->get_header( 'paypal-transmission-sig' ),
				'transmission_time' => (string) $request->get_header( 'paypal-transmission-time' ),
				'webhook_id'        => $webhook_id,
				'webhook_event'     => $event,
			)
		);
		return ! is_wp_error( $result ) && 'SUCCESS' === ( $result['verification_status'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $capture Capture object.
	 * @param array<string, mixed> $paypal  PayPal order.
	 */
	private function matches( array $capture, array $paypal, Order $order ): bool {
		$unit = $paypal['purchase_units'][0] ?? array();
		return ( $unit['reference_id'] ?? ( $capture['custom_id'] ?? '' ) ) === $order->uuid
			&& strtoupper( (string) ( $capture['amount']['currency_code'] ?? '' ) ) === $order->currency
			&& Money::to_minor( (string) ( $capture['amount']['value'] ?? '' ), $order->currency ) === $order->total_minor;
	}

	private function by_capture( string $capture_id ): ?Order {
		global $wpdb;
		if ( '' === $capture_id ) {
			return null;
		}
		$table = \OpenEvents\Attendance\Schema::orders();
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = 'paypal' AND gateway_txn = %s", $capture_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? ( new Orders() )->get( (int) $id ) : null;
	}

	/**
	 * OAuth access token, cached until shortly before it expires.
	 */
	private function access_token(): string|\WP_Error {
		$key   = 'openevents_paypal_token_' . md5( $this->base() . $this->setting( 'client_id' ) );
		$token = get_transient( $key );
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}
		$response = wp_remote_post(
			$this->base() . '/v1/oauth2/token',
			array(
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->setting( 'client_id' ) . ':' . $this->setting( 'client_secret' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'body'    => array( 'grant_type' => 'client_credentials' ),
				'timeout' => 20,
			)
		);
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'openevents_paypal_auth', __( 'Could not sign in to PayPal. Check the client ID and secret.', 'openevents' ) );
		}
		set_transient( $key, $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 ) );
		return $data['access_token'];
	}

	/**
	 * @param array<string, mixed>|null $body JSON body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null, string $request_id = '' ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		);
		if ( $request_id ) {
			$headers['PayPal-Request-Id'] = $request_id;
		}
		$response = wp_remote_request(
			$this->base() . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => null === $body ? ( 'POST' === $method ? '{}' : null ) : wp_json_encode( $body ),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$issue = (string) ( $data['details'][0]['issue'] ?? 'openevents_paypal' );
			return new \WP_Error( $issue, (string) ( $data['details'][0]['description'] ?? $data['message'] ?? __( 'PayPal request failed.', 'openevents' ) ), array( 'status' => $code ) );
		}
		return $data;
	}
}
