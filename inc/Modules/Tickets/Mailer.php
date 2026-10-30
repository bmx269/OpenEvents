<?php
/**
 * Ticket emails.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Money;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Frontend\Formatter;

/**
 * Order confirmation: what was bought, payment status, each ticket's
 * check-in code, and event details. Plain text, filterable.
 */
final class Mailer {

	public function confirmation( Order $order ): void {
		$title = wp_strip_all_tags( get_the_title( $order->event_id ) );
		$types = new TicketTypes();
		$lines = array(
			/* translators: %s: buyer name */
			sprintf( __( 'Hi %s,', 'openevents' ), $order->name ),
			'',
			Order::UNPAID === $order->status
				/* translators: %s: event title */
				? sprintf( __( 'Your tickets for %s are reserved. Payment is due before the event.', 'openevents' ), $title )
				/* translators: %s: event title */
				: sprintf( __( 'Thanks for your order! Here are your tickets for %s.', 'openevents' ), $title ),
			'',
		);

		$type       = Checkout::order_type( $order );
		$occurrence = ( $type ? \OpenEvents\Attendance\TicketTypes::date_of( $type ) : null ) ?? openevents()->occurrences()->for_event( $order->event_id );
		if ( $occurrence ) {
			/* translators: %s: date and time */
			$lines[] = sprintf( __( 'When: %s', 'openevents' ), Formatter::range( $occurrence ) );
		}
		$venue = openevents_get_event( $order->event_id )?->venue();
		if ( $venue ) {
			/* translators: %s: venue */
			$lines[] = sprintf( __( 'Where: %s', 'openevents' ), wp_strip_all_tags( get_the_title( $venue ) ) );
		}
		$lines[] = '';

		foreach ( ( new \OpenEvents\Attendance\Orders() )->items( $order->id ) as $item ) {
			$type    = $types->get( (int) $item->ticket_type_id );
			$lines[] = sprintf( '%d × %s — %s', (int) $item->quantity, $type ? $type->name : '', Money::format( (int) $item->unit_price_minor * (int) $item->quantity, $order->currency ) );
		}
		/* translators: %s: order total */
		$lines[] = sprintf( __( 'Total: %s', 'openevents' ), Money::format( $order->total_minor, $order->currency ) );
		if ( Order::UNPAID === $order->status ) {
			$manual = Gateways::get( 'manual' );
			if ( $manual ) {
				$lines[] = $manual->description();
			}
		}
		$lines[] = '';
		$lines[] = __( 'Passes (show one per person at the door, on your phone or printed):', 'openevents' );
		foreach ( ( new Attendees() )->group( $order->uuid ) as $i => $attendee ) {
			$holder  = $types->get( $attendee->ticket_type_id );
			$lines[] = sprintf( '  %d. %s, %s (%s)', $i + 1, $attendee->name, $holder ? $holder->name : '', $attendee->security_code );
			$lines[] = '     ' . \OpenEvents\Attendance\Pass::url( $attendee );
		}
		$lines[] = '';
		/* translators: %s: order number */
		$lines[] = sprintf( __( 'Order: %s', 'openevents' ), $order->uuid );
		$lines[] = $type ? \OpenEvents\Attendance\TicketTypes::url( $type ) : get_permalink( $order->event_id );

		$email = array(
			'to'      => $order->email,
			/* translators: %s: event title */
			'subject' => sprintf( __( 'Your tickets: %s', 'openevents' ), $title ),
			'message' => implode( "\n", $lines ),
			'headers' => array(),
		);

		/**
		 * Filters the order confirmation email. Return an empty array to skip it.
		 *
		 * @param array $email to, subject, message, headers.
		 * @param Order $order Order.
		 */
		$email = apply_filters( 'openevents/tickets/email', $email, $order );
		if ( $email ) {
			wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
		}
	}
}
