<?php
/**
 * Tickets REST routes.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /openevents/v1/events/{id}/orders     Place an order (public).
 * GET  /openevents/v1/orders/return          Buyers return from Stripe/PayPal (public).
 * POST /openevents/v1/gateways/{id}/webhook  Provider notifications (public, signature-checked).
 * GET  /openevents/v1/orders                 Order list (editors).
 * POST /openevents/v1/orders/{id}            mark_paid | refund | cancel (event editors).
 * GET|POST /openevents/v1/gateways           Gateway settings (administrators; secrets masked).
 */
final class Rest {

	private const NS   = 'openevents/v1';
	public const MASK  = '••••••••';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/orders',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'place' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'name'    => array( 'type' => 'string' ),
					'email'   => array( 'type' => 'string' ),
					'sequence' => array(
						'description' => __( 'Which date of a repeating event (an occurrence sequence; 0 is the event’s own date).', 'openevents' ),
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
					),
					'tickets' => array( 'type' => 'object' ),
					'attendees' => array(
						'description' => __( 'Per ticket type id, a list of people: name, email, and fields (answers by field key).', 'openevents' ),
						'type'        => 'object',
					),
					'gateway' => array( 'type' => 'string' ),
					'waitlist_token' => array( 'type' => 'string' ),
					'website' => array( 'type' => 'string' ),
					'ts'      => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/return',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'return_from_gateway' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/gateways/(?P<gateway>[a-z0-9_-]+)/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true', // Each gateway verifies its provider's signature.
			)
		);
		register_rest_route(
			self::NS,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_orders' ),
				'permission_callback' => static fn( WP_REST_Request $r ) => (int) $r['event'] ? current_user_can( 'edit_post', (int) $r['event'] ) : current_user_can( \OpenEvents\Modules\Rsvp\Rest::capability() ),
				'args'                => array(
					'event'    => array( 'type' => 'integer', 'default' => 0 ),
					'status'   => array( 'type' => 'string', 'default' => '' ),
					'search'   => array( 'type' => 'string', 'default' => '' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'order_action' ),
				'permission_callback' => static function ( WP_REST_Request $r ): bool {
					$order = ( new Orders() )->get( (int) $r['id'] );
					return $order && current_user_can( 'edit_post', $order->event_id );
				},
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'mark_paid', 'refund', 'cancel' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/gateways',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_gateways' ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_gateways' ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				),
			)
		);
	}

	public function place( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];
		if ( 'publish' !== get_post_status( $event_id ) ) {
			return new WP_REST_Response( CheckoutResult::error( 'closed', __( 'This event isn’t selling tickets.', 'openevents' ) )->to_array(), 404 );
		}
		$result = ( new Checkout() )->place(
			$event_id,
			array(
				'name'    => (string) $request['name'],
				'email'   => (string) $request['email'],
				'sequence' => (int) $request['sequence'],
				'tickets' => array_map( 'absint', (array) $request['tickets'] ),
				'attendees' => (array) $request['attendees'],
				'gateway' => sanitize_key( (string) $request['gateway'] ),
				'waitlist_token' => (string) $request['waitlist_token'],
				'website' => (string) $request['website'],
				'ts'      => (string) $request['ts'],
				'ip'      => Guard::client_ip(),
			)
		);
		if ( $result->ok ) {
			Module::remember_order( $result );
		}
		$codes = array(
			'full'           => 409,
			'closed'         => 403,
			'rate_limited'   => 429,
			'invalid'        => 422,
			'payment_failed' => 502,
		);
		return new WP_REST_Response( $result->to_array(), $result->ok ? 200 : ( $codes[ $result->code ] ?? 400 ) );
	}

	/**
	 * The provider sends the buyer here. Confirm with the provider, then send
	 * the buyer to the event page with the outcome.
	 */
	public function return_from_gateway( WP_REST_Request $request ): WP_REST_Response {
		$order = ( new Orders() )->by_uuid( sanitize_text_field( (string) $request['oe_order'] ) );
		if ( ! $order ) {
			return $this->redirect( home_url( '/' ) );
		}
		$checkout = new Checkout();
		$gateway  = Gateways::get( $order->gateway );
		$state    = 'pending';

		if ( Order::COMPLETED === $order->status ) {
			$state = 'done';
		} elseif ( $gateway && ! $order->is_final() ) {
			$query  = array_map( 'sanitize_text_field', array_map( 'strval', $request->get_query_params() ) );
			$result = $gateway->handle_return( $order, $query );
			if ( 'paid' === $result->status ) {
				$checkout->complete( $order, $result->txn );
				$state = 'done';
			} elseif ( 'failed' === $result->status ) {
				$checkout->release( $order, 'cancel' === ( $query['oe_checkout'] ?? '' ) ? Order::CANCELLED : Order::FAILED );
				$state = 'cancel' === ( $query['oe_checkout'] ?? '' ) ? 'cancelled' : 'failed';
			}
		}

		$token = isset( $_COOKIE[ 'oe_order_' . $order->uuid ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ 'oe_order_' . $order->uuid ] ) ) : '';
		$args  = array( 'oe_checkout' => $state );
		if ( $token && ( new Orders() )->by_token( $token )?->id === $order->id ) {
			$args['oe_order_token'] = $token;
		}
		return $this->redirect( add_query_arg( $args, Checkout::order_page( $order ) ) . '#tickets' );
	}

	public function webhook( WP_REST_Request $request ): WP_REST_Response {
		$gateway = Gateways::get( (string) $request['gateway'] );
		return $gateway ? $gateway->handle_webhook( $request ) : new WP_REST_Response( array( 'ok' => false ), 404 );
	}

	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new Orders() )->query(
			array(
				'event_id' => (int) $request['event'],
				'status'   => sanitize_key( (string) $request['status'] ),
				'search'   => (string) $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);
		$ids = array_values( array_unique( array_map( static fn( Order $o ) => $o->event_id, $result['items'] ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		$items    = array_map(
			static fn( Order $o ) => array_merge(
				$o->to_array(),
				array(
					'event_title'   => html_entity_decode( get_the_title( $o->event_id ), ENT_QUOTES ),
					'gateway_label' => Gateways::get( $o->gateway )?->label() ?? ( 'free' === $o->gateway ? __( 'Free', 'openevents' ) : $o->gateway ),
					'oversold'      => ! empty( $o->meta['oversold'] ),
				)
			),
			$result['items']
		);
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}

	public function order_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$orders   = new Orders();
		$order    = $orders->get( (int) $request['id'] );
		$checkout = new Checkout();
		switch ( $request['action'] ) {
			case 'mark_paid':
				if ( Order::UNPAID !== $order->status || ! $checkout->mark_paid( $order ) ) {
					return new WP_Error( 'openevents_order_state', __( 'Only unpaid orders can be marked as paid.', 'openevents' ), array( 'status' => 409 ) );
				}
				break;
			case 'refund':
				$result = $checkout->refund( $order );
				if ( is_wp_error( $result ) ) {
					$result->add_data( array( 'status' => 409 ) );
					return $result;
				}
				break;
			case 'cancel':
				if ( ! $checkout->release( $order, Order::CANCELLED ) ) {
					return new WP_Error( 'openevents_order_state', __( 'Only pending or unpaid orders can be cancelled. Refund paid orders instead.', 'openevents' ), array( 'status' => 409 ) );
				}
				break;
		}
		return new WP_REST_Response( $orders->get( $order->id )->to_array() );
	}

	/**
	 * Gateway settings for the admin. Secret values are never sent back.
	 */
	public function get_gateways(): WP_REST_Response {
		$out = array();
		foreach ( Gateways::all() as $id => $gateway ) {
			$fields = array();
			foreach ( $gateway->settings_fields() as $key => $field ) {
				$value          = $gateway instanceof AbstractGateway ? $gateway->setting( $key, '' ) : '';
				$fields[ $key ] = array_merge(
					$field,
					array(
						'value'    => 'secret' === $field['type'] ? ( '' !== (string) $value ? self::MASK . substr( (string) $value, -4 ) : '' ) : $value,
						'constant' => $gateway instanceof AbstractGateway && $gateway->is_constant( $key ),
					)
				);
			}
			$out[] = array(
				'id'        => $id,
				'label'     => $gateway->label(),
				'available' => $gateway->is_available( Checkout::currency() ),
				'webhook'   => rest_url( 'openevents/v1/gateways/' . $id . '/webhook' ),
				'fields'    => $fields,
			);
		}
		return new WP_REST_Response(
			array(
				'currency' => Checkout::currency(),
				'gateways' => $out,
			)
		);
	}

	public function save_gateways( WP_REST_Request $request ): WP_REST_Response {
		$stored = get_option( AbstractGateway::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( (array) $request['gateways'] as $id => $values ) {
			$gateway = Gateways::get( (string) $id );
			if ( ! $gateway ) {
				continue;
			}
			foreach ( $gateway->settings_fields() as $key => $field ) {
				if ( ! array_key_exists( $key, (array) $values ) ) {
					continue;
				}
				$value = $values[ $key ];
				if ( 'secret' === $field['type'] && is_string( $value ) && str_starts_with( $value, self::MASK ) ) {
					continue; // Unchanged masked secret.
				}
				$stored[ $id ][ $key ] = match ( $field['type'] ) {
					'toggle' => (bool) $value,
					'select' => array_key_exists( (string) $value, (array) ( $field['options'] ?? array() ) ) ? (string) $value : '',
					default  => sanitize_text_field( (string) $value ),
				};
			}
		}
		update_option( AbstractGateway::OPTION, $stored, false );
		Gateways::reset();
		return $this->get_gateways();
	}

	private function redirect( string $url ): WP_REST_Response {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		return $response;
	}
}
