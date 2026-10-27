<?php
/**
 * RFC 5545 writer.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\ICal;

use OpenEvents\Content\Event;
use OpenEvents\Occurrences\Occurrence;

/**
 * Serialises occurrences as an iCalendar document. Times are written in UTC
 * ("Z") so no VTIMEZONE blocks are needed; all-day events use DATE values.
 */
final class Writer {

	/**
	 * @param Occurrence[] $occurrences Occurrences.
	 */
	public static function calendar( array $occurrences ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//OpenEvents//' . self::text( get_bloginfo( 'name' ) ) . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::text( get_bloginfo( 'name' ) ),
		);

		foreach ( $occurrences as $occurrence ) {
			$event = $occurrence->event();
			if ( $event ) {
				array_push( $lines, ...self::vevent( $occurrence, $event ) );
			}
		}

		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * @return string[]
	 */
	private static function vevent( Occurrence $occurrence, Event $event ): array {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array(
			'BEGIN:VEVENT',
			'UID:' . $occurrence->event_id . '-' . $occurrence->sequence . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'LAST-MODIFIED:' . get_post_modified_time( 'Ymd\THis\Z', true, $event->post ),
		);

		if ( $occurrence->all_day ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . $occurrence->start->format( 'Ymd' );
			$lines[] = 'DTEND;VALUE=DATE:' . $occurrence->end->modify( '+1 day' )->format( 'Ymd' );
		} else {
			$lines[] = 'DTSTART:' . $occurrence->start_utc()->format( 'Ymd\THis\Z' );
			$lines[] = 'DTEND:' . $occurrence->end_utc()->format( 'Ymd\THis\Z' );
		}

		$lines[] = 'SUMMARY:' . self::text( get_the_title( $event->post ) );
		$lines[] = 'DESCRIPTION:' . self::text( get_the_excerpt( $event->post ) );
		$lines[] = 'URL:' . get_permalink( $event->post );

		$venue = $event->venue();
		if ( $venue ) {
			$lines[] = 'LOCATION:' . self::text( get_the_title( $venue ) . ', ' . Event::venue_address( $venue ) );
			$lat     = get_post_meta( $venue->ID, '_oe_lat', true );
			$lng     = get_post_meta( $venue->ID, '_oe_lng', true );
			if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
				$lines[] = 'GEO:' . (float) $lat . ';' . (float) $lng;
			}
		}

		$categories = wp_list_pluck( (array) ( get_the_terms( $event->post, \OpenEvents\Content\PostTypes::CATEGORY ) ?: array() ), 'name' );
		if ( is_array( $categories ) && $categories ) {
			$lines[] = 'CATEGORIES:' . implode( ',', array_map( array( self::class, 'text' ), $categories ) );
		}

		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * Escapes a TEXT value (RFC 5545 §3.3.11).
	 */
	public static function text( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' );
		$value = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $value );
		return str_replace( array( "\r\n", "\n", "\r" ), '\\n', trim( $value ) );
	}

	/**
	 * Folds a content line at 75 octets without splitting UTF-8 characters (RFC 5545 §3.1).
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out    = '';
		$buffer = '';
		$limit  = 75;
		foreach ( mb_str_split( $line ) as $char ) {
			if ( strlen( $buffer ) + strlen( $char ) > $limit ) {
				$out   .= $buffer . "\r\n ";
				$buffer = '';
				$limit  = 74; // Continuation lines start with a space.
			}
			$buffer .= $char;
		}
		return $out . $buffer;
	}
}
