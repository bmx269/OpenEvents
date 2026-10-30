<?php
/**
 * RSVP emails.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Rsvp;

use OpenEvents\Attendance\Attendee;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Frontend\Formatter;
use OpenEvents\Occurrences\Occurrence;

/**
 * Plain-text emails: confirmation (with a private change/cancel link) and a
 * "manage your RSVP" link when someone responds again with the same email.
 */
final class Mailer {

	/**
	 * @param Attendee[] $people Response rows; the first is the respondent.
	 */
	public function confirmation( int $event_id, array $people, ?string $token, bool $updated = false ): void {
		if ( ! $people ) {
			return;
		}
		$lead  = $people[0];
		$title = wp_strip_all_tags( get_the_title( $event_id ) );
		$going = Attendee::GOING === $lead->status;

		$subject = $going
			/* translators: %s: event title */
			? sprintf( $updated ? __( 'RSVP updated: %s', 'openevents' ) : __( 'You’re going: %s', 'openevents' ), $title )
			/* translators: %s: event title */
			: sprintf( __( 'RSVP: not going to %s', 'openevents' ), $title );

		$lines = array(
			/* translators: %s: attendee name */
			sprintf( __( 'Hi %s,', 'openevents' ), $lead->name ),
			'',
			$going
				/* translators: 1: number of people, 2: event title */
				? sprintf( _n( 'You’re confirmed for %2$s.', 'You’re confirmed for %2$s, party of %1$d.', count( $people ), 'openevents' ), count( $people ), $title )
				/* translators: %s: event title */
				: sprintf( __( 'We’ve noted that you can’t make it to %s.', 'openevents' ), $title ),
			'',
		);
		$occurrence = self::occurrence( $event_id, $people );
		$lines      = array_merge( $lines, $this->event_lines( $event_id, $occurrence ), \OpenEvents\Attendance\Pass::email_lines( $people ) );
		if ( $token ) {
			$lines[] = '';
			$lines[] = __( 'Change or cancel your RSVP:', 'openevents' );
			$lines[] = self::manage_url( $event_id, $token, $occurrence );
		}

		$this->send( $lead->email, $subject, $lines, 'confirmation', $event_id, $people );
	}

	/**
	 * @param Attendee[] $people Response rows.
	 */
	public function manage_link( int $event_id, array $people, string $token ): void {
		if ( ! $people ) {
			return;
		}
		$title = wp_strip_all_tags( get_the_title( $event_id ) );
		$lines = array(
			/* translators: %s: attendee name */
			sprintf( __( 'Hi %s,', 'openevents' ), $people[0]->name ),
			'',
			/* translators: %s: event title */
			sprintf( __( 'Someone (hopefully you) tried to RSVP to %s with this email address, which already has a response.', 'openevents' ), $title ),
			__( 'Use this private link to change or cancel your RSVP:', 'openevents' ),
			self::manage_url( $event_id, $token, self::occurrence( $event_id, $people ) ),
			'',
			__( 'If this wasn’t you, you can ignore this email.', 'openevents' ),
		);
		/* translators: %s: event title */
		$this->send( $people[0]->email, sprintf( __( 'Your RSVP for %s', 'openevents' ), $title ), $lines, 'manage_link', $event_id, $people );
	}

	/**
	 * The private link, on the response's date when given (the link works on
	 * any date's page, but that's the one it's about).
	 */
	public static function manage_url( int $event_id, string $token, ?Occurrence $occurrence = null ): string {
		$url = $occurrence ? openevents_occurrence_url( $occurrence ) : get_permalink( $event_id );
		return add_query_arg( 'oe_rsvp_token', rawurlencode( $token ), $url ) . '#rsvp';
	}

	/**
	 * The date a response is for: its RSVP type's (each date of a repeating
	 * event has its own), else the event's next.
	 *
	 * @param Attendee[] $people Response rows.
	 */
	private static function occurrence( int $event_id, array $people ): ?Occurrence {
		$type = $people ? ( new TicketTypes() )->get( $people[0]->ticket_type_id ) : null;
		return ( $type ? TicketTypes::date_of( $type ) : null ) ?? openevents()->occurrences()->for_event( $event_id );
	}

	/**
	 * @return string[]
	 */
	private function event_lines( int $event_id, ?Occurrence $occurrence ): array {
		$lines = array();
		if ( $occurrence ) {
			/* translators: %s: date and time */
			$lines[] = sprintf( __( 'When: %s', 'openevents' ), Formatter::range( $occurrence ) );
		}
		$event = openevents_get_event( $event_id );
		$venue = $event?->venue();
		if ( $venue ) {
			$address = \OpenEvents\Content\Event::venue_address( $venue );
			/* translators: %s: venue name and address */
			$lines[] = sprintf( __( 'Where: %s', 'openevents' ), wp_strip_all_tags( get_the_title( $venue ) ) . ( $address ? ', ' . $address : '' ) );
		}
		$lines[] = $occurrence ? openevents_occurrence_url( $occurrence ) : get_permalink( $event_id );
		return $lines;
	}

	/**
	 * @param string[]   $lines  Body lines.
	 * @param Attendee[] $people Response rows.
	 */
	private function send( string $to, string $subject, array $lines, string $type, int $event_id, array $people ): void {
		$email = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => implode( "\n", $lines ),
			'headers' => array(),
		);

		/**
		 * Filters an RSVP email before sending. Return an empty array to skip it.
		 *
		 * @param array      $email    to, subject, message, headers.
		 * @param string     $type     "confirmation" or "manage_link".
		 * @param int        $event_id Event id.
		 * @param Attendee[] $people   Response rows.
		 */
		$email = apply_filters( 'openevents/rsvp/email', $email, $type, $event_id, $people );
		if ( $email ) {
			wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
		}
	}
}
