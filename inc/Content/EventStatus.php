<?php
/**
 * Event status: scheduled, cancelled, or postponed.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Content;

use DateTimeImmutable;
use OpenEvents\Occurrences\Occurrence;

/**
 * Whether an event (or one date of a repeating event) is still happening.
 *
 * - `_oe_status` and `_oe_status_reason`: the whole event's status and an
 *   optional note ("Postponed because of the storm; new date to follow").
 * - `_oe_date_status`: one date of a repeating event cancelled or postponed
 *   on its own, `[ { date: "Y-m-d", status, reason } ]`, keyed by local date
 *   like skipped dates (iCal's STATUS on a RECURRENCE-ID instance).
 *
 * A date's status is resolved when occurrences are written (the
 * `event_status` column), so listings show it and filter on it without
 * reading meta. The event's own status wins over a date's when it isn't
 * "scheduled".
 */
final class EventStatus {

	public const SCHEDULED = 'scheduled';
	public const CANCELLED = 'cancelled';
	public const POSTPONED = 'postponed';

	public const ALL = array( self::SCHEDULED, self::CANCELLED, self::POSTPONED );

	public static function label( string $status ): string {
		return match ( $status ) {
			self::CANCELLED => __( 'Cancelled', 'openevents' ),
			self::POSTPONED => __( 'Postponed', 'openevents' ),
			default         => __( 'Scheduled', 'openevents' ),
		};
	}

	/**
	 * The schema.org EventStatusType for structured data.
	 */
	public static function schema_org( string $status ): string {
		return 'https://schema.org/' . match ( $status ) {
			self::CANCELLED => 'EventCancelled',
			self::POSTPONED => 'EventPostponed',
			default         => 'EventScheduled',
		};
	}

	public static function sanitize( mixed $value ): string {
		return in_array( $value, self::ALL, true ) ? $value : self::SCHEDULED;
	}

	public static function sanitize_reason( mixed $value ): string {
		return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, 500 );
	}

	/**
	 * Keeps one entry per valid date, and only real changes from "scheduled".
	 *
	 * @param mixed $value Raw meta value.
	 * @return array<int, array{date: string, status: string, reason: string}>
	 */
	public static function sanitize_dates( mixed $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $entry ) {
			$date   = is_array( $entry ) ? (string) ( $entry['date'] ?? '' ) : '';
			$status = self::sanitize( is_array( $entry ) ? ( $entry['status'] ?? '' ) : '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || self::SCHEDULED === $status ) {
				continue;
			}
			$out[ $date ] = array(
				'date'   => $date,
				'status' => $status,
				'reason' => self::sanitize_reason( $entry['reason'] ?? '' ),
			);
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Sets the whole event's status, or one date's ($date, a local "Y-m-d"
	 * when the event is on that date). "Scheduled" for a date clears its own
	 * status. Occurrences follow through the meta change.
	 */
	public static function set( int $event_id, string $status, string $reason = '', string $date = '' ): true|\WP_Error {
		if ( ! in_array( $status, self::ALL, true ) ) {
			return new \WP_Error( 'openevents_invalid_status', __( 'Status must be scheduled, cancelled, or postponed.', 'openevents' ) );
		}
		$event = openevents_get_event( $event_id );
		if ( ! $event ) {
			return new \WP_Error( 'openevents_not_event', __( 'That is not an event.', 'openevents' ) );
		}
		$reason = self::sanitize_reason( $reason );
		if ( '' === $date ) {
			update_post_meta( $event_id, '_oe_status', $status );
			update_post_meta( $event_id, '_oe_status_reason', self::SCHEDULED === $status ? '' : $reason );
			return true;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! openevents()->occurrences()->on_date( $event_id, $date ) ) {
			return new \WP_Error( 'openevents_invalid_date', __( 'The event isn’t on that date.', 'openevents' ) );
		}
		$dates = array_values( array_filter( self::sanitize_dates( $event->meta( '_oe_date_status' ) ), static fn( array $entry ) => $entry['date'] !== $date ) );
		if ( self::SCHEDULED !== $status ) {
			$dates[] = array(
				'date'   => $date,
				'status' => $status,
				'reason' => $reason,
			);
		}
		update_post_meta( $event_id, '_oe_date_status', self::sanitize_dates( $dates ) );
		return true;
	}

	/**
	 * The event's own status.
	 */
	public static function for_event( Event $event ): string {
		return self::sanitize( $event->meta( '_oe_status' ) );
	}

	/**
	 * Status and reason for the date starting at $start: the event's, when
	 * the whole event is cancelled or postponed; else that date's own; else
	 * scheduled.
	 *
	 * @return array{status: string, reason: string}
	 */
	public static function for_date( Event $event, DateTimeImmutable $start ): array {
		$status = self::for_event( $event );
		if ( self::SCHEDULED !== $status ) {
			return array(
				'status' => $status,
				'reason' => self::sanitize_reason( $event->meta( '_oe_status_reason' ) ),
			);
		}
		$day = $start->format( 'Y-m-d' );
		foreach ( self::sanitize_dates( $event->meta( '_oe_date_status' ) ) as $entry ) {
			if ( $entry['date'] === $day ) {
				return array(
					'status' => $entry['status'],
					'reason' => $entry['reason'],
				);
			}
		}
		return array(
			'status' => self::SCHEDULED,
			'reason' => '',
		);
	}

	/**
	 * The reason shown for one occurrence (its status is on the row).
	 */
	public static function reason( Occurrence $occurrence ): string {
		$event = $occurrence->event();
		return $event && self::SCHEDULED !== $occurrence->event_status ? self::for_date( $event, $occurrence->start )['reason'] : '';
	}

	/**
	 * "Cancelled" badge for listings, or empty for a scheduled date.
	 */
	public static function badge( Occurrence $occurrence, string $class_name ): string {
		if ( self::SCHEDULED === $occurrence->event_status ) {
			return '';
		}
		return '<span class="' . esc_attr( $class_name ) . ' is-' . esc_attr( $occurrence->event_status ) . '">' . esc_html( self::label( $occurrence->event_status ) ) . '</span>';
	}
}
