<?php
/**
 * Ticket checkout and order lifecycle.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Attendee;
use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Money;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\Schema;
use OpenEvents\Attendance\TicketType;
use OpenEvents\Attendance\TicketTypes;

/**
 * Places orders and moves them through their lifecycle. Every status change
 * goes through Orders::transition(), a conditional UPDATE, so completing,
 * expiring, and refunding each happen exactly once even when a webhook and a
 * return URL race each other.
 *
 *   pending ──paid──▶ completed ──refund──▶ refunded
 *      │  └──expire/fail/cancel──▶ expired | failed | cancelled
 *      └──manual──▶ unpaid ──mark paid──▶ completed
 */
final class Checkout {

	public const HOLD_MINUTES = 20;
	public const MAX_TICKETS  = 50;
	public const RATE         = 10;  // Orders per IP per window.
	public const RATE_SPAN    = 600; // Seconds.

	public function __construct(
		private TicketTypes $types = new TicketTypes(),
		private Orders $orders = new Orders(),
		private Attendees $attendees = new Attendees(),
		private ?Mailer $mailer = null,
	) {
		$this->mailer ??= new Mailer();
	}

	public static function currency(): string {
		return strtoupper( (string) ( openevents()->settings()->get( 'currency' ) ?: 'USD' ) );
	}

	/**
	 * Active, on-sale paid ticket types for an event, keyed by id.
	 *
	 * @return array<int, TicketType>
	 */
	public function ticket_types( int $event_id, bool $on_sale_only = false ): array {
		global $wpdb;
		$table = Schema::ticket_types();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND kind = 'paid' AND status = 'active' ORDER BY id", $event_id ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$type = TicketType::from_row( $row );
			if ( ! $on_sale_only || null === $this->sale_state( $type ) ) {
				$out[ $type->id ] = $type;
			}
		}
		return $out;
	}

	/**
	 * Why a ticket type can't be bought right now ("not_started", "ended",
	 * "event_started", "sold_out"), or null when it can.
	 */
	public function sale_state( TicketType $type ): ?string {
		$now   = gmdate( 'Y-m-d H:i:s' );
		$start = (string) $type->setting( 'sale_start_utc', '' );
		if ( '' !== $start && $now < $start ) {
			return 'not_started';
		}
		if ( $type->sale_end_utc && $now > $type->sale_end_utc ) {
			return 'ended';
		}
		$occurrence = openevents()->occurrences()->for_event( $type->event_id );
		if ( ! $type->sale_end_utc && ( ! $occurrence || $now >= $occurrence->start_utc()->format( 'Y-m-d H:i:s' ) ) ) {
			return 'event_started';
		}
		return $type->is_full() ? 'sold_out' : null;
	}

	/**
	 * Places an order. $input: name, email, tickets (type id => quantity),
	 * gateway, website (honeypot), ts (signed timestamp), ip.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public function place( int $event_id, array $input ): CheckoutResult {
		$this->expire_due( 20 );

		if ( Guard::is_bot( $input ) ) {
			return CheckoutResult::error( 'invalid', __( 'Please try again.', 'openevents' ) );
		}
		if ( ! Guard::verify_timestamp( (string) ( $input['ts'] ?? '' ) ) ) {
			return CheckoutResult::error( 'invalid', __( 'Please wait a moment and submit the form again.', 'openevents' ) );
		}

		$name  = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( '' === $name || mb_strlen( $name ) > 200 ) {
			return CheckoutResult::error( 'invalid', __( 'Please enter your name.', 'openevents' ) );
		}
		if ( ! is_email( $email ) ) {
			return CheckoutResult::error( 'invalid', __( 'Please enter a valid email address.', 'openevents' ) );
		}

		$ip_hash = Guard::hash_ip( (string) ( $input['ip'] ?? '' ) );
		if ( $this->recent_orders( $ip_hash ) >= self::RATE ) {
			return CheckoutResult::error( 'rate_limited', __( 'Too many orders from your connection. Please try again later.', 'openevents' ) );
		}

		$types    = $this->ticket_types( $event_id );
		$currency = self::currency();
		$lines    = array();
		$count    = 0;
		$total    = 0;
		foreach ( (array) ( $input['tickets'] ?? array() ) as $type_id => $quantity ) {
			$quantity = (int) $quantity;
			if ( $quantity <= 0 ) {
				continue;
			}
			$type = $types[ (int) $type_id ] ?? null;
			if ( ! $type ) {
				return CheckoutResult::error( 'invalid', __( 'One of those tickets is no longer available.', 'openevents' ) );
			}
			$state = $this->sale_state( $type );
			if ( $state && 'sold_out' !== $state ) {
				/* translators: %s: ticket name */
				return CheckoutResult::error( 'closed', sprintf( __( '“%s” tickets aren’t on sale.', 'openevents' ), $type->name ) );
			}
			$max = (int) $type->setting( 'max_per_order', 10 );
			if ( $quantity > $max ) {
				/* translators: 1: maximum, 2: ticket name */
				return CheckoutResult::error( 'invalid', sprintf( __( 'You can buy up to %1$d “%2$s” tickets per order.', 'openevents' ), $max, $type->name ) );
			}
			$price   = (int) $type->setting( 'price_minor', 0 );
			$lines[] = array(
				'type'     => $type,
				'quantity' => $quantity,
				'price'    => $price,
			);
			$count  += $quantity;
			$total  += $price * $quantity;
		}
		if ( 0 === $count ) {
			return CheckoutResult::error( 'invalid', __( 'Choose at least one ticket.', 'openevents' ) );
		}
		if ( $count > self::MAX_TICKETS ) {
			/* translators: %d: maximum tickets */
			return CheckoutResult::error( 'invalid', sprintf( __( 'You can buy up to %d tickets in one order.', 'openevents' ), self::MAX_TICKETS ) );
		}

		$gateway = null;
		if ( $total > 0 ) {
			$gateway = Gateways::get( (string) ( $input['gateway'] ?? '' ) );
			if ( ! $gateway || ! $gateway->is_available( $currency ) ) {
				return CheckoutResult::error( 'invalid', __( 'Please choose how you’d like to pay.', 'openevents' ) );
			}
		}

		// Hold every line's seats, or none.
		$held = array();
		foreach ( $lines as $line ) {
			if ( ! $this->types->reserve( $line['type']->id, $line['quantity'] ) ) {
				foreach ( $held as $h ) {
					$this->types->release( $h['type']->id, $h['quantity'] );
				}
				$left = $this->types->get( $line['type']->id )?->remaining() ?? 0;
				return CheckoutResult::error(
					'full',
					$left > 0
						/* translators: 1: number left, 2: ticket name */
						? sprintf( _n( 'Only %1$d “%2$s” ticket is left.', 'Only %1$d “%2$s” tickets are left.', $left, 'openevents' ), $left, $line['type']->name )
						/* translators: %s: ticket name */
						: sprintf( __( '“%s” is sold out.', 'openevents' ), $line['type']->name )
				);
			}
			$held[] = $line;
		}

		$token = Guard::new_token();
		$order = $this->orders->create(
			array(
				'event_id'    => $event_id,
				'gateway'     => $gateway ? $gateway->id() : 'free',
				'name'        => $name,
				'email'       => $email,
				'total_minor' => $total,
				'currency'    => $currency,
				'token_hash'  => Guard::hash_token( $token ),
				'ip_hash'     => $ip_hash,
				'expires_utc' => gmdate( 'Y-m-d H:i:s', time() + self::HOLD_MINUTES * MINUTE_IN_SECONDS ),
			),
			array_map(
				static fn( $l ) => array(
					'ticket_type_id'   => $l['type']->id,
					'quantity'         => $l['quantity'],
					'unit_price_minor' => $l['price'],
				),
				$lines
			)
		);
		foreach ( $lines as $line ) {
			$this->attendees->create_group(
				array(
					'name'           => $name,
					'email'          => $email,
					'status'         => 'pending',
					'ticket_type_id' => $line['type']->id,
					'event_id'       => $event_id,
					'order_id'       => $order->id,
					'ip_hash'        => $ip_hash,
				),
				$line['quantity'],
				$order->uuid,
				true
			);
		}

		if ( ! $gateway ) {
			$this->complete( $order );
			return CheckoutResult::confirmed( $order, $token, __( 'You’re all set! Your tickets are on their way to your inbox.', 'openevents' ) );
		}

		$result = $gateway->start_payment( $order );
		switch ( $result->status ) {
			case 'redirect':
				$this->orders->update( $order->id, array( 'gateway_ref' => $result->reference ) );
				return CheckoutResult::redirect( $order, $token, $result->url );
			case 'paid':
				$this->complete( $this->orders->get( $order->id ), $result->txn );
				return CheckoutResult::confirmed( $order, $token, __( 'Payment received. Your tickets are on their way to your inbox.', 'openevents' ) );
			case 'unpaid':
				$this->confirm_unpaid( $this->orders->get( $order->id ) );
				return CheckoutResult::confirmed( $order, $token, __( 'Your tickets are reserved. We’ve emailed you the details.', 'openevents' ) );
			default:
				$this->release( $this->orders->get( $order->id ), Order::FAILED );
				return CheckoutResult::error( 'payment_failed', $result->message ?: __( 'We couldn’t start the payment. Please try again.', 'openevents' ) );
		}
	}

	/**
	 * Marks an order paid and its tickets valid. Safe to call repeatedly (a
	 * webhook and the return URL may both arrive). A payment that lands after
	 * the hold expired is still honoured: seats are re-taken if possible, and
	 * the order is flagged for the organiser if not.
	 */
	public function complete( Order $order, string $txn = '' ): bool {
		$fresh = $this->orders->get( $order->id );
		if ( ! $fresh ) {
			return false;
		}
		if ( in_array( $fresh->status, array( Order::EXPIRED, Order::FAILED, Order::CANCELLED ), true ) ) {
			$oversold = false;
			foreach ( $this->orders->items( $fresh->id ) as $item ) {
				if ( ! $this->types->reserve( (int) $item->ticket_type_id, (int) $item->quantity ) ) {
					$this->types->force_add( (int) $item->ticket_type_id, (int) $item->quantity );
					$oversold = true;
				}
			}
			$meta = $fresh->meta;
			if ( $oversold ) {
				$meta['oversold'] = true;
			}
			if ( ! $this->orders->transition( $fresh->id, array( $fresh->status ), Order::COMPLETED, array( 'gateway_txn' => $txn, 'expires_utc' => null, 'meta' => wp_json_encode( $meta ) ) ) ) {
				return false;
			}
			if ( $oversold ) {
				/**
				 * Fires when a late payment completed an order whose seats had been resold.
				 *
				 * @param Order $order Order.
				 */
				do_action( 'openevents/tickets/oversold', $this->orders->get( $fresh->id ) );
			}
		} elseif ( ! $this->orders->transition( $fresh->id, array( Order::PENDING, Order::UNPAID ), Order::COMPLETED, array( 'gateway_txn' => $txn, 'expires_utc' => null ) ) ) {
			return false; // Already completed (or refunded) by another request.
		}

		$this->attendees->set_group_status( $fresh->uuid, Attendee::GOING );
		$order = $this->orders->get( $fresh->id );
		if ( Order::UNPAID !== $fresh->status ) {
			$this->mailer->confirmation( $order );
		}

		/**
		 * Fires once when an order is paid (or free) and its tickets are valid.
		 *
		 * @param Order $order Order.
		 */
		do_action( 'openevents/tickets/order_completed', $order );
		return true;
	}

	/**
	 * Manual gateway: tickets valid now, payment collected later.
	 */
	public function confirm_unpaid( Order $order ): bool {
		if ( ! $this->orders->transition( $order->id, array( Order::PENDING ), Order::UNPAID, array( 'expires_utc' => null ) ) ) {
			return false;
		}
		$this->attendees->set_group_status( $order->uuid, Attendee::GOING );
		$this->mailer->confirmation( $this->orders->get( $order->id ) );
		do_action( 'openevents/tickets/order_unpaid', $this->orders->get( $order->id ) );
		return true;
	}

	/**
	 * Organiser recorded payment for an unpaid (manual) order.
	 */
	public function mark_paid( Order $order ): bool {
		return $this->complete( $order, 'manual:' . get_current_user_id() );
	}

	/**
	 * Ends an order without payment and frees its seats.
	 *
	 * @param string $status Order::EXPIRED, FAILED, or CANCELLED.
	 */
	public function release( Order $order, string $status ): bool {
		$from = Order::CANCELLED === $status ? array( Order::PENDING, Order::UNPAID ) : array( Order::PENDING );
		if ( ! $this->orders->transition( $order->id, $from, $status ) ) {
			return false;
		}
		$this->free_seats( $order );
		$this->attendees->set_group_status( $order->uuid, Attendee::CANCELLED );
		do_action( 'openevents/tickets/order_released', $this->orders->get( $order->id ), $status );
		return true;
	}

	/**
	 * Refunds through the gateway, then frees the seats.
	 */
	public function refund( Order $order ): true|\WP_Error {
		if ( ! in_array( $order->status, array( Order::COMPLETED, Order::UNPAID ), true ) ) {
			return new \WP_Error( 'openevents_not_refundable', __( 'Only paid or unpaid orders can be refunded.', 'openevents' ) );
		}
		if ( $order->total_minor > 0 && Order::COMPLETED === $order->status ) {
			$gateway = Gateways::get( $order->gateway );
			$result  = $gateway ? $gateway->refund( $order ) : new \WP_Error( 'openevents_gateway_missing', __( 'The payment method for this order is no longer available.', 'openevents' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( ! $this->orders->transition( $order->id, array( Order::COMPLETED, Order::UNPAID ), Order::REFUNDED ) ) {
			return new \WP_Error( 'openevents_refund_conflict', __( 'This order changed while refunding. Reload and check its status.', 'openevents' ) );
		}
		$this->free_seats( $order );
		$this->attendees->set_group_status( $order->uuid, 'refunded' );
		do_action( 'openevents/tickets/order_refunded', $this->orders->get( $order->id ) );
		return true;
	}

	/**
	 * Records a refund made in the provider's dashboard (from a webhook).
	 */
	public function refunded_externally( Order $order ): bool {
		if ( ! $this->orders->transition( $order->id, array( Order::COMPLETED ), Order::REFUNDED ) ) {
			return false;
		}
		$this->free_seats( $order );
		$this->attendees->set_group_status( $order->uuid, 'refunded' );
		do_action( 'openevents/tickets/order_refunded', $this->orders->get( $order->id ) );
		return true;
	}

	/**
	 * Releases seats held by abandoned checkouts.
	 */
	public function expire_due( int $limit = 50 ): int {
		$count = 0;
		foreach ( $this->orders->expired_ids( $limit ) as $id ) {
			$order = $this->orders->get( $id );
			if ( $order && $this->release( $order, Order::EXPIRED ) ) {
				++$count;
			}
		}
		return $count;
	}

	private function free_seats( Order $order ): void {
		foreach ( $this->orders->items( $order->id ) as $item ) {
			$this->types->release( (int) $item->ticket_type_id, (int) $item->quantity );
		}
	}

	private function recent_orders( string $ip_hash ): int {
		global $wpdb;
		if ( '' === $ip_hash ) {
			return 0;
		}
		$table = Schema::orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND created_utc >= %s", $ip_hash, gmdate( 'Y-m-d H:i:s', time() - self::RATE_SPAN ) ) );
	}

	/**
	 * Where the buyer lands after checkout: the event page with their order.
	 */
	public static function confirmation_url( Order $order, string $token = '' ): string {
		$args = array( 'oe_checkout' => 'done' );
		if ( '' !== $token ) {
			$args['oe_order_token'] = $token;
		}
		return add_query_arg( $args, (string) get_permalink( $order->event_id ) ) . '#tickets';
	}
}
