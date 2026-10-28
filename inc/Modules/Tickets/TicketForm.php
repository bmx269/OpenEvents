<?php
/**
 * Ticket checkout markup.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Money;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\TicketType;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Content\Event;

/**
 * The tickets section: a table of ticket types with quantity pickers, buyer
 * details, and payment options. A plain POST form; the openevents/tickets
 * module shows a running total and submits over REST.
 */
final class TicketForm {

	/**
	 * @param TicketType[] $types Active paid ticket types.
	 */
	public static function render( Event $event, array $types, Checkout $checkout, string $token = '' ): string {
		$id       = 'oe-tickets-' . $event->id();
		$currency = Checkout::currency();

		$html = '<section class="oe-tickets" id="tickets" aria-labelledby="' . esc_attr( $id ) . '-title" data-wp-interactive="openevents/tickets" data-wp-context="' . esc_attr(
			(string) wp_json_encode(
				array(
					'endpoint'      => rest_url( 'openevents/v1/events/' . $event->id() . '/orders' ),
					'message'       => '',
					'error'         => false,
					'busy'          => false,
					'total'         => '',
					'currency'      => $currency,
					'locale'        => str_replace( '_', '-', get_locale() ),
					'decimals'      => Money::decimals( $currency ),
					'fallbackError' => __( 'Something went wrong. Please check your connection and try again.', 'openevents' ),
				)
			)
		) . '">';
		$html .= '<h3 class="oe-tickets__title" id="' . esc_attr( $id ) . '-title">' . esc_html__( 'Tickets', 'openevents' ) . '</h3>';

		// Returning buyer: show their order.
		$order = '' !== $token ? ( new Orders() )->by_token( $token ) : null;
		if ( $order && $order->event_id === $event->id() ) {
			$html .= self::order_summary( $order );
		}
		$html .= self::outcome_notice();
		$html .= '<p class="oe-tickets__notice oe-tickets__live" role="status" aria-live="polite" tabindex="-1" data-wp-text="context.message" data-wp-class--is-error="context.error" data-wp-bind--hidden="!context.message" hidden></p>';

		$on_sale = array_filter( $types, static fn( TicketType $t ) => null === $checkout->sale_state( $t ) );
		if ( ! $types ) {
			return $html . '</section>';
		}

		$gateways = Gateways::available( $currency );
		$html    .= '<form class="oe-tickets__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wp-on--submit="actions.submit" data-wp-on--change="actions.recalculate">';
		$html    .= '<input type="hidden" name="action" value="openevents_checkout"><input type="hidden" name="event_id" value="' . esc_attr( (string) $event->id() ) . '">';
		$html    .= '<input type="hidden" name="ts" value="' . esc_attr( Guard::sign_timestamp() ) . '">';
		$html    .= '<div class="oe-tickets__hp" aria-hidden="true"><label>' . esc_html__( 'Website', 'openevents' ) . ' <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';

		$html .= '<table class="oe-tickets__types"><caption class="oe-visually-hidden">' . esc_html__( 'Ticket types', 'openevents' ) . '</caption><thead><tr>';
		$html .= '<th scope="col">' . esc_html__( 'Ticket', 'openevents' ) . '</th><th scope="col">' . esc_html__( 'Price', 'openevents' ) . '</th><th scope="col">' . esc_html__( 'Quantity', 'openevents' ) . '</th></tr></thead><tbody>';
		foreach ( $types as $type ) {
			$state    = $checkout->sale_state( $type );
			$price    = (int) $type->setting( 'price_minor', 0 );
			$field_id = $id . '-qty-' . $type->id;
			$html    .= '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $type->name ) . '</label>';
			$details  = self::availability( $type, $state );
			if ( '' !== $details ) {
				$html .= '<span class="oe-tickets__availability" id="' . esc_attr( $field_id ) . '-help">' . esc_html( $details ) . '</span>';
			}
			$html .= '</th><td>' . esc_html( Money::format( $price, $currency ) ) . '</td><td>';
			if ( null === $state ) {
				$max     = (int) $type->setting( 'max_per_order', 10 );
				$max     = null === $type->remaining() ? $max : min( $max, $type->remaining() );
				$options = '';
				for ( $n = 0; $n <= $max; $n++ ) {
					$options .= sprintf( '<option value="%1$d">%1$d</option>', $n );
				}
				$html .= sprintf(
					'<select id="%1$s" name="tickets[%2$d]" data-price="%3$d"%4$s>%5$s</select>',
					esc_attr( $field_id ),
					$type->id,
					$price,
					'' !== $details ? ' aria-describedby="' . esc_attr( $field_id ) . '-help"' : '',
					$options
				);
			} else {
				$html .= '<span aria-hidden="true">—</span>';
			}
			$html .= '</td></tr>';
		}
		$html .= '</tbody></table>';

		if ( ! $on_sale ) {
			return $html . '</form></section>';
		}

		$html .= '<p class="oe-tickets__total" data-wp-bind--hidden="!context.total" hidden>' . esc_html__( 'Total:', 'openevents' ) . ' <strong data-wp-text="context.total"></strong></p>';
		$html .= '<div class="oe-tickets__fields">';
		$html .= '<p class="oe-tickets__field"><label for="' . esc_attr( $id ) . '-name">' . esc_html__( 'Name', 'openevents' ) . '</label><input id="' . esc_attr( $id ) . '-name" type="text" name="name" required maxlength="200" autocomplete="name"></p>';
		$html .= '<p class="oe-tickets__field"><label for="' . esc_attr( $id ) . '-email">' . esc_html__( 'Email', 'openevents' ) . '</label><input id="' . esc_attr( $id ) . '-email" type="email" name="email" required autocomplete="email"></p>';
		$html .= '</div>';

		$priced = array_filter( $on_sale, static fn( TicketType $t ) => (int) $t->setting( 'price_minor', 0 ) > 0 );
		if ( $priced ) {
			if ( ! $gateways ) {
				$html .= '<p class="oe-tickets__notice is-error">' . esc_html__( 'Online payment isn’t set up for this event yet.', 'openevents' ) . '</p>';
			} else {
				$html .= '<fieldset class="oe-tickets__gateways"><legend>' . esc_html__( 'Payment', 'openevents' ) . '</legend>';
				$first = true;
				foreach ( $gateways as $gateway ) {
					$gid   = $id . '-gw-' . $gateway->id();
					$html .= '<p class="oe-tickets__gateway"><input type="radio" id="' . esc_attr( $gid ) . '" name="gateway" value="' . esc_attr( $gateway->id() ) . '"' . checked( $first, true, false ) . ( $gateway->description() ? ' aria-describedby="' . esc_attr( $gid ) . '-desc"' : '' ) . '>';
					$html .= ' <label for="' . esc_attr( $gid ) . '">' . esc_html( $gateway->label() ) . '</label>';
					if ( $gateway->description() ) {
						$html .= '<span class="oe-tickets__gateway-desc" id="' . esc_attr( $gid ) . '-desc">' . esc_html( $gateway->description() ) . '</span>';
					}
					$html .= '</p>';
					$first = false;
				}
				$html .= '</fieldset>';
			}
		}

		$html .= '<p class="oe-tickets__actions"><button type="submit" class="oe-tickets__submit wp-element-button" data-wp-bind--disabled="context.busy">' . esc_html__( 'Get tickets', 'openevents' ) . '</button></p>';
		return $html . '</form></section>';
	}

	private static function availability( TicketType $type, ?string $state ): string {
		$messages = array(
			'not_started'   => __( 'Not on sale yet', 'openevents' ),
			'ended'         => __( 'Sales have ended', 'openevents' ),
			'event_started' => __( 'Sales have ended', 'openevents' ),
			'sold_out'      => __( 'Sold out', 'openevents' ),
		);
		if ( $state ) {
			return $messages[ $state ] ?? '';
		}
		$remaining = $type->remaining();
		/* translators: %d: tickets left */
		return null !== $remaining && $remaining <= 20 ? sprintf( _n( '%d left', '%d left', $remaining, 'openevents' ), $remaining ) : '';
	}

	private static function order_summary( Order $order ): string {
		$statuses = array(
			Order::COMPLETED => __( 'Confirmed', 'openevents' ),
			Order::UNPAID    => __( 'Reserved — payment due', 'openevents' ),
			Order::PENDING   => __( 'Waiting for payment confirmation', 'openevents' ),
			Order::REFUNDED  => __( 'Refunded', 'openevents' ),
			Order::CANCELLED => __( 'Cancelled', 'openevents' ),
			Order::EXPIRED   => __( 'Expired (not paid in time)', 'openevents' ),
			Order::FAILED    => __( 'Payment failed', 'openevents' ),
		);
		$html  = '<div class="oe-tickets__order" role="status" tabindex="-1"><h4>' . esc_html__( 'Your order', 'openevents' ) . '</h4>';
		$html .= '<p><strong>' . esc_html( $statuses[ $order->status ] ?? $order->status ) . '</strong> · ' . esc_html( Money::format( $order->total_minor, $order->currency ) ) . '</p>';
		if ( in_array( $order->status, Order::VALID, true ) ) {
			$types = new TicketTypes();
			$html .= '<p>' . esc_html__( 'Show one check-in code per person at the door:', 'openevents' ) . '</p><ol class="oe-tickets__codes">';
			foreach ( ( new Attendees() )->group( $order->uuid ) as $attendee ) {
				$type  = $types->get( $attendee->ticket_type_id );
				$html .= '<li><code>' . esc_html( $attendee->security_code ) . '</code> ' . esc_html( $type ? $type->name : '' ) . '</li>';
			}
			$html .= '</ol>';
		}
		return $html . '</div>';
	}

	private static function outcome_notice(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state = sanitize_key( wp_unslash( $_GET['oe_checkout'] ?? '' ) );
		$code  = sanitize_key( wp_unslash( $_GET['oe_checkout_code'] ?? '' ) );
		// phpcs:enable
		$messages = array(
			'done'      => array( 'status', __( 'Thanks! Your tickets are confirmed and on their way to your inbox.', 'openevents' ) ),
			'pending'   => array( 'status', __( 'We’re waiting for the payment provider to confirm. You’ll get an email shortly.', 'openevents' ) ),
			'cancelled' => array( 'alert', __( 'Payment was cancelled, so no tickets were reserved.', 'openevents' ) ),
			'failed'    => array( 'alert', __( 'The payment didn’t go through, so no tickets were reserved.', 'openevents' ) ),
		);
		if ( 'error' === $state ) {
			$errors = array(
				'full'         => __( 'Sorry, there aren’t enough tickets left for that order.', 'openevents' ),
				'closed'       => __( 'Those tickets are no longer on sale.', 'openevents' ),
				'rate_limited' => __( 'Too many orders from your connection. Please try again later.', 'openevents' ),
			);
			return '<p class="oe-tickets__notice is-error" role="alert" tabindex="-1">' . esc_html( $errors[ $code ] ?? __( 'Please check the form and try again.', 'openevents' ) ) . '</p>';
		}
		if ( ! isset( $messages[ $state ] ) ) {
			return '';
		}
		list( $role, $text ) = $messages[ $state ];
		return '<p class="oe-tickets__notice' . ( 'alert' === $role ? ' is-error' : '' ) . '" role="' . $role . '" tabindex="-1">' . esc_html( $text ) . '</p>';
	}
}
