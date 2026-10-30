<?php
/**
 * Date formatting.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Frontend;

use OpenEvents\Occurrences\Occurrence;

/**
 * The single path for turning occurrences into display strings, so every view,
 * feed, and email shows the same times.
 */
final class Formatter {

	public static function date( \DateTimeImmutable $date, ?string $format = null ): string {
		$format ??= openevents()->settings()->date_format();
		return wp_date( $format, $date->getTimestamp(), $date->getTimezone() );
	}

	public static function time( \DateTimeImmutable $date ): string {
		return wp_date( openevents()->settings()->time_format(), $date->getTimestamp(), $date->getTimezone() );
	}

	/**
	 * Examples:
	 * - "November 3, 2026"                                (all day)
	 * - "November 3 – 5, 2026"                            (multi-day, all day; via date format)
	 * - "November 3, 2026, 7:00 pm – 9:00 pm"             (same day)
	 * - "November 3, 2026, 7:00 pm – November 4, 2026, 1:00 am"
	 * A timezone abbreviation is appended when it differs from the site's.
	 */
	public static function range( Occurrence $occurrence ): string {
		$start = $occurrence->start;
		$end   = $occurrence->end;
		/* translators: separator between start and end of a date range */
		$sep = _x( ' – ', 'date range separator', 'openevents' );

		if ( $occurrence->all_day ) {
			$out = self::date( $start );
			if ( $occurrence->is_multi_day() ) {
				$out .= $sep . self::date( $end );
			}
		} elseif ( $occurrence->is_multi_day() ) {
			$out = self::date( $start ) . ', ' . self::time( $start ) . $sep . self::date( $end ) . ', ' . self::time( $end );
		} else {
			$out = self::date( $start ) . ', ' . self::time( $start );
			if ( $end > $start ) {
				$out .= $sep . self::time( $end );
			}
		}

		if ( ! $occurrence->all_day && $start->getTimezone()->getName() !== wp_timezone()->getName() ) {
			$out .= ' ' . $start->format( 'T' );
		}

		/**
		 * Filters the formatted date range of an occurrence.
		 *
		 * @param string     $out        Formatted range.
		 * @param Occurrence $occurrence Occurrence.
		 */
		return apply_filters( 'openevents/format_range', $out, $occurrence );
	}

	/**
	 * "For <date>" above an RSVP or tickets form on a repeating event, whose
	 * dates each have their own places; empty for other events.
	 */
	public static function booking_date( \OpenEvents\Attendance\TicketType $type, string $class_name ): string {
		$event = openevents_get_event( $type->event_id );
		if ( ! $event || ! \OpenEvents\Modules\Recurrence\Recurrence::for_event( $event ) ) {
			return '';
		}
		$occurrence = \OpenEvents\Attendance\TicketTypes::date_of( $type );
		if ( ! $occurrence ) {
			return '';
		}
		return '<p class="' . esc_attr( $class_name ) . '">' . sprintf(
			/* translators: %s: date and time of one date of a repeating event */
			esc_html__( 'For %s', 'openevents' ),
			'<time datetime="' . esc_attr( self::iso( $occurrence->start, $occurrence->all_day ) ) . '">' . esc_html( self::range( $occurrence ) ) . '</time>'
		) . '</p>';
	}

	/**
	 * Machine-readable datetime attribute for <time>.
	 */
	public static function iso( \DateTimeImmutable $date, bool $all_day = false ): string {
		return $all_day ? $date->format( 'Y-m-d' ) : $date->format( DATE_ATOM );
	}
}
