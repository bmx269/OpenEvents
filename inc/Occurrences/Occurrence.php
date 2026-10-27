<?php
/**
 * Occurrence value object.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Occurrences;

use DateTimeImmutable;
use DateTimeZone;
use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;

/**
 * One dated instance of an event (a row in oe_occurrences).
 */
final class Occurrence {

	public function __construct(
		public readonly int $id,
		public readonly int $event_id,
		public readonly int $sequence,
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
		public readonly bool $all_day,
	) {}

	/**
	 * @param object $row Database row from oe_occurrences.
	 */
	public static function from_row( object $row ): Occurrence {
		$tz = Event::resolve_timezone( (string) $row->timezone );
		return new self(
			(int) $row->id,
			(int) $row->event_id,
			(int) $row->sequence,
			DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, $row->start_local, $tz ),
			DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, $row->end_local, $tz ),
			(bool) $row->all_day,
		);
	}

	public function event(): ?Event {
		return Event::get( $this->event_id );
	}

	public function is_multi_day(): bool {
		return $this->start->format( 'Y-m-d' ) !== $this->end->format( 'Y-m-d' );
	}

	public function has_ended(): bool {
		return $this->end->getTimestamp() < time();
	}

	public function start_utc(): DateTimeImmutable {
		return $this->start->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	public function end_utc(): DateTimeImmutable {
		return $this->end->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
