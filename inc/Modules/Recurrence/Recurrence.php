<?php
/**
 * An event's repeat settings.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Recurrence;

use DateTimeImmutable;
use InvalidArgumentException;
use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;

/**
 * The `_oe_recurrence` meta: an RRULE plus extra dates (RDATE) and skipped dates
 * (EXDATE), the same three parts an iCal VEVENT uses.
 *
 * The event's own start (`_oe_start`) is always the first date, as DTSTART is in
 * iCal, and every date keeps the event's start time and length.
 */
final class Recurrence {

	public const META_KEY = '_oe_recurrence';

	/**
	 * @param string[] $rdates  Extra start times, "Y-m-d H:i:s" in the event timezone.
	 * @param string[] $exdates Skipped dates, "Y-m-d" in the event timezone.
	 */
	public function __construct(
		public readonly ?Rule $rule,
		public readonly array $rdates = array(),
		public readonly array $exdates = array(),
	) {}

	public static function for_event( Event $event ): ?Recurrence {
		return self::from_meta( $event->meta( self::META_KEY ) );
	}

	/**
	 * Null when the meta is empty or holds no repeat at all.
	 */
	public static function from_meta( mixed $value ): ?Recurrence {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$rule = null;
		if ( ! empty( $value['rrule'] ) ) {
			try {
				$rule = Rule::parse( (string) $value['rrule'] );
			} catch ( InvalidArgumentException ) {
				$rule = null;
			}
		}
		$recurrence = new self( $rule, self::clean_rdates( $value['rdates'] ?? array() ), self::clean_exdates( $value['exdates'] ?? array() ) );
		return $recurrence->is_empty() ? null : $recurrence;
	}

	/**
	 * Meta sanitiser. Invalid rules are dropped here; the REST layer validates
	 * first so editors get an error instead.
	 *
	 * @return array{rrule: string, rdates: string[], exdates: string[]}
	 */
	public static function sanitize( mixed $value ): array {
		$recurrence = self::from_meta( $value );
		return $recurrence ? $recurrence->to_meta() : self::empty_meta();
	}

	/**
	 * @return array{rrule: string, rdates: string[], exdates: string[]}
	 */
	public static function empty_meta(): array {
		return array(
			'rrule'   => '',
			'rdates'  => array(),
			'exdates' => array(),
		);
	}

	/**
	 * @return array{rrule: string, rdates: string[], exdates: string[]}
	 */
	public function to_meta(): array {
		return array(
			'rrule'   => $this->rule ? (string) $this->rule : '',
			'rdates'  => $this->rdates,
			'exdates' => $this->exdates,
		);
	}

	public function is_empty(): bool {
		return null === $this->rule && ! $this->rdates;
	}

	/**
	 * Whether dates keep being added as time passes (an RRULE with no COUNT or UNTIL).
	 */
	public function is_open_ended(): bool {
		return null !== $this->rule && ! $this->rule->is_finite();
	}

	/**
	 * Start times up to $limit: the event start, the rule's dates, and extra dates,
	 * minus skipped dates. Sorted and unique.
	 *
	 * @return DateTimeImmutable[]
	 */
	public function starts( DateTimeImmutable $dtstart, DateTimeImmutable $limit, int $max = 5000 ): array {
		$tz     = $dtstart->getTimezone();
		$starts = array( $dtstart->format( Meta::DATETIME_FORMAT ) => $dtstart );
		if ( $this->rule ) {
			foreach ( $this->rule->starts( $dtstart, $limit, $max ) as $start ) {
				$starts[ $start->format( Meta::DATETIME_FORMAT ) ] = $start;
			}
		}
		foreach ( $this->rdates as $rdate ) {
			$start = DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, $rdate, $tz );
			if ( $start && $start >= $dtstart && $start <= $limit ) {
				$starts[ $rdate ] = $start;
			}
		}
		foreach ( $starts as $key => $start ) {
			if ( in_array( $start->format( 'Y-m-d' ), $this->exdates, true ) ) {
				unset( $starts[ $key ] );
			}
		}
		ksort( $starts );
		return array_slice( array_values( $starts ), 0, $max );
	}

	/**
	 * A copy with changes, e.g. one more skipped date.
	 *
	 * @param array<string, mixed> $changes rule, rdates, and/or exdates.
	 */
	public function with( array $changes ): Recurrence {
		return new self(
			array_key_exists( 'rule', $changes ) ? $changes['rule'] : $this->rule,
			self::clean_rdates( $changes['rdates'] ?? $this->rdates ),
			self::clean_exdates( $changes['exdates'] ?? $this->exdates )
		);
	}

	/**
	 * @return string[]
	 */
	private static function clean_rdates( mixed $values ): array {
		$out = array();
		foreach ( (array) $values as $value ) {
			$value = Meta::sanitize_datetime( $value );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * @return string[]
	 */
	private static function clean_exdates( mixed $values ): array {
		$out = array();
		foreach ( (array) $values as $value ) {
			$value = substr( trim( (string) $value ), 0, 10 );
			$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
			if ( $date && $date->format( 'Y-m-d' ) === $value ) {
				$out[] = $value;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}
}
