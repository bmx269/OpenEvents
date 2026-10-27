<?php
/**
 * Occurrence generation.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Occurrences;

use DateTimeImmutable;
use DateTimeZone;
use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;

/**
 * Turns an event's schedule meta into the rows that should exist in oe_occurrences.
 */
final class Generator {

	/**
	 * @return array<int, array<string, mixed>> Rows keyed by sequence, without id.
	 */
	public function generate( Event $event ): array {
		$rows  = array();
		$start = $event->start();
		$end   = $event->end();

		if ( $start && $end ) {
			if ( $event->is_all_day() ) {
				$start = $start->setTime( 0, 0, 0 );
				$end   = $end->setTime( 23, 59, 59 );
			}
			$rows[0] = self::row( $event, 0, $start, $end );
		}

		/**
		 * Filters the occurrence rows for an event before they are saved.
		 *
		 * The recurrence module hooks here to expand a rule into many rows. Rows are
		 * matched to existing ones by sequence, so keep sequences stable for dates
		 * that have not changed (attendees reference occurrence ids).
		 *
		 * @param array<int, array<string, mixed>> $rows  Rows keyed by sequence. Build them with Generator::row().
		 * @param Event                            $event Event.
		 */
		return apply_filters( 'openevents/occurrences/generate', $rows, $event );
	}

	/**
	 * Builds one occurrence row from start and end in the event timezone.
	 *
	 * @return array<string, mixed>
	 */
	public static function row( Event $event, int $sequence, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$utc = new DateTimeZone( 'UTC' );
		return array(
			'event_id'           => $event->id(),
			'sequence'           => $sequence,
			'start_local'        => $start->format( Meta::DATETIME_FORMAT ),
			'end_local'          => $end->format( Meta::DATETIME_FORMAT ),
			'start_utc'          => $start->setTimezone( $utc )->format( Meta::DATETIME_FORMAT ),
			'end_utc'            => $end->setTimezone( $utc )->format( Meta::DATETIME_FORMAT ),
			'timezone'           => (string) $event->meta( '_oe_timezone' ),
			'all_day'            => $event->is_all_day() ? 1 : 0,
			'hide_from_listings' => $event->meta( '_oe_hide_from_listings' ) ? 1 : 0,
		);
	}
}
