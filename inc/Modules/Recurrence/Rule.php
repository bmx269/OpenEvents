<?php
/**
 * Recurrence rule (RFC 5545 RRULE subset).
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A parsed RRULE. Stored and exchanged as the standard string (what iCal feeds,
 * Google Calendar, and TEC exports use), so rules round-trip without a custom
 * format.
 *
 * Supported: FREQ (DAILY, WEEKLY, MONTHLY, YEARLY), INTERVAL, COUNT, UNTIL,
 * BYDAY (with ordinals such as 2TU or -1FR), BYMONTHDAY, BYMONTH, BYSETPOS, WKST.
 * Anything else is rejected rather than silently ignored, so a rule never
 * expands to dates its author did not mean.
 *
 * UNTIL may be a date (inclusive, in the event's timezone), a local date-time,
 * or a UTC date-time ending in Z.
 */
final class Rule {

	public const DAYS = array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );

	private const FREQUENCIES = array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' );

	/**
	 * @param array<int, array{0: int, 1: string}> $byday      [ordinal (0 = every), day code].
	 * @param int[]                                $bymonthday Days of the month; negative counts from the end.
	 * @param int[]                                $bymonth    Months, 1-12.
	 * @param int[]                                $bysetpos   Positions in each period's set; negative counts from the end.
	 */
	public function __construct(
		public readonly string $freq,
		public readonly int $interval = 1,
		public readonly ?int $count = null,
		public readonly ?string $until = null,
		public readonly array $byday = array(),
		public readonly array $bymonthday = array(),
		public readonly array $bymonth = array(),
		public readonly array $bysetpos = array(),
		public readonly string $wkst = 'MO',
	) {}

	/**
	 * @throws InvalidArgumentException When the rule is malformed or uses unsupported parts.
	 */
	public static function parse( string $rrule ): Rule {
		$rrule = trim( preg_replace( '/^RRULE:/i', '', trim( $rrule ) ) );
		if ( '' === $rrule ) {
			throw new InvalidArgumentException( 'Empty rule.' );
		}

		$parts = array();
		foreach ( explode( ';', $rrule ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			if ( ! str_contains( $pair, '=' ) ) {
				throw new InvalidArgumentException( sprintf( 'Malformed part "%s".', $pair ) );
			}
			list( $name, $value ) = explode( '=', $pair, 2 );
			$parts[ strtoupper( trim( $name ) ) ] = strtoupper( trim( $value ) );
		}

		$unsupported = array_diff( array_keys( $parts ), array( 'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'WKST' ) );
		if ( $unsupported ) {
			throw new InvalidArgumentException( sprintf( 'Unsupported rule parts: %s.', implode( ', ', $unsupported ) ) );
		}
		if ( ! in_array( $parts['FREQ'] ?? '', self::FREQUENCIES, true ) ) {
			throw new InvalidArgumentException( 'FREQ must be DAILY, WEEKLY, MONTHLY, or YEARLY.' );
		}
		if ( isset( $parts['COUNT'], $parts['UNTIL'] ) ) {
			throw new InvalidArgumentException( 'COUNT and UNTIL cannot both be set.' );
		}

		$interval = self::positive( $parts['INTERVAL'] ?? '1', 'INTERVAL' );
		$count    = isset( $parts['COUNT'] ) ? self::positive( $parts['COUNT'], 'COUNT' ) : null;

		$until = null;
		if ( isset( $parts['UNTIL'] ) ) {
			if ( ! preg_match( '/^\d{8}(T\d{6}Z?)?$/', $parts['UNTIL'] ) ) {
				throw new InvalidArgumentException( 'UNTIL must be YYYYMMDD, YYYYMMDDTHHMMSS, or YYYYMMDDTHHMMSSZ.' );
			}
			$until = $parts['UNTIL'];
		}

		$byday = array();
		if ( isset( $parts['BYDAY'] ) ) {
			foreach ( explode( ',', $parts['BYDAY'] ) as $item ) {
				if ( ! preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $item, $m ) ) {
					throw new InvalidArgumentException( sprintf( 'Bad BYDAY value "%s".', $item ) );
				}
				$n = (int) ( $m[1] ?? 0 );
				if ( 0 !== $n && ! in_array( $parts['FREQ'], array( 'MONTHLY', 'YEARLY' ), true ) ) {
					throw new InvalidArgumentException( 'Numbered BYDAY values need FREQ=MONTHLY or YEARLY.' );
				}
				if ( abs( $n ) > 53 ) {
					throw new InvalidArgumentException( sprintf( 'Bad BYDAY value "%s".', $item ) );
				}
				$byday[] = array( $n, $m[2] );
			}
		}

		$wkst = $parts['WKST'] ?? 'MO';
		if ( ! in_array( $wkst, self::DAYS, true ) ) {
			throw new InvalidArgumentException( 'Bad WKST value.' );
		}

		return new self(
			$parts['FREQ'],
			$interval,
			$count,
			$until,
			$byday,
			self::numbers( $parts['BYMONTHDAY'] ?? '', 'BYMONTHDAY', 1, 31, true ),
			self::numbers( $parts['BYMONTH'] ?? '', 'BYMONTH', 1, 12, false ),
			self::numbers( $parts['BYSETPOS'] ?? '', 'BYSETPOS', 1, 366, true ),
			$wkst
		);
	}

	public function __toString(): string {
		$parts = array( 'FREQ=' . $this->freq );
		if ( 1 !== $this->interval ) {
			$parts[] = 'INTERVAL=' . $this->interval;
		}
		if ( $this->byday ) {
			$parts[] = 'BYDAY=' . implode( ',', array_map( static fn( $d ) => ( $d[0] ? $d[0] : '' ) . $d[1], $this->byday ) );
		}
		foreach ( array( 'BYMONTHDAY' => $this->bymonthday, 'BYMONTH' => $this->bymonth, 'BYSETPOS' => $this->bysetpos ) as $name => $values ) {
			if ( $values ) {
				$parts[] = $name . '=' . implode( ',', $values );
			}
		}
		if ( null !== $this->count ) {
			$parts[] = 'COUNT=' . $this->count;
		}
		if ( null !== $this->until ) {
			$parts[] = 'UNTIL=' . $this->until;
		}
		if ( 'MO' !== $this->wkst ) {
			$parts[] = 'WKST=' . $this->wkst;
		}
		return implode( ';', $parts );
	}

	/**
	 * Start dates generated by the rule, in order, from $dtstart (always included
	 * when it matches the rule, as RFC 5545 counts it) up to and including $limit.
	 *
	 * Expansion works on calendar dates and then applies the start's wall-clock
	 * time in its timezone, so a 7pm event stays at 7pm across DST changes.
	 *
	 * @param int $max Safety cap on the number of dates returned.
	 * @return DateTimeImmutable[]
	 */
	public function starts( DateTimeImmutable $dtstart, DateTimeImmutable $limit, int $max = 5000 ): array {
		$tz         = $dtstart->getTimezone();
		$time       = $dtstart->format( 'H:i:s' );
		$first_date = $dtstart->format( 'Y-m-d' );
		$until      = $this->until_in( $tz );
		$end        = ( $until && $until < $limit ) ? $until : $limit;

		$out     = array();
		$emitted = 0;
		// Bounded by $end: every period starts after the previous one, and an empty
		// period (e.g. BYMONTHDAY=31 in a 30-day month) still advances.
		for ( $k = 0; ; $k++ ) {
			$period = $this->period_start( $dtstart, $k );
			if ( $period->format( 'Y-m-d' ) > $end->format( 'Y-m-d' ) ) {
				break;
			}
			foreach ( $this->apply_setpos( $this->candidates( $period, $dtstart ) ) as $date ) {
				if ( $date < $first_date ) {
					continue;
				}
				$start = new DateTimeImmutable( $date . ' ' . $time, $tz );
				if ( $start > $end ) {
					break 2;
				}
				$out[] = $start;
				++$emitted;
				if ( ( null !== $this->count && $emitted >= $this->count ) || $emitted >= $max ) {
					break 2;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether the rule ends (COUNT or UNTIL) rather than repeating forever.
	 */
	public function is_finite(): bool {
		return null !== $this->count || null !== $this->until;
	}

	/**
	 * UNTIL as a moment in the event timezone; a bare date includes the whole day.
	 */
	public function until_in( DateTimeZone $tz ): ?DateTimeImmutable {
		if ( null === $this->until ) {
			return null;
		}
		if ( 8 === strlen( $this->until ) ) {
			return new DateTimeImmutable( substr( $this->until, 0, 4 ) . '-' . substr( $this->until, 4, 2 ) . '-' . substr( $this->until, 6, 2 ) . ' 23:59:59', $tz );
		}
		$utc  = str_ends_with( $this->until, 'Z' );
		$date = DateTimeImmutable::createFromFormat( 'Ymd\THis', rtrim( $this->until, 'Z' ), $utc ? new DateTimeZone( 'UTC' ) : $tz );
		return $date ? $date->setTimezone( $tz ) : null;
	}

	/**
	 * Returns a copy with some parts replaced (e.g. a new UNTIL when splitting a series).
	 *
	 * @param array<string, mixed> $changes Constructor argument name => value.
	 */
	public function with( array $changes ): Rule {
		$args = array(
			'freq'       => $this->freq,
			'interval'   => $this->interval,
			'count'      => $this->count,
			'until'      => $this->until,
			'byday'      => $this->byday,
			'bymonthday' => $this->bymonthday,
			'bymonth'    => $this->bymonth,
			'bysetpos'   => $this->bysetpos,
			'wkst'       => $this->wkst,
		);
		return new self( ...array_merge( $args, $changes ) );
	}

	private function period_start( DateTimeImmutable $dtstart, int $k ): DateTimeImmutable {
		$day = new DateTimeImmutable( $dtstart->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
		$n   = $k * $this->interval;
		switch ( $this->freq ) {
			case 'DAILY':
				return $day->modify( "+{$n} days" );
			case 'WEEKLY':
				$offset = ( (int) $day->format( 'N' ) - 1 - array_search( $this->wkst, self::DAYS, true ) + 7 ) % 7;
				return $day->modify( '-' . $offset . ' days' )->modify( '+' . ( 7 * $n ) . ' days' );
			case 'MONTHLY':
				return $day->modify( 'first day of this month' )->modify( "+{$n} months" );
			default:
				return $day->setDate( (int) $day->format( 'Y' ) + $n, 1, 1 );
		}
	}

	/**
	 * Dates ("Y-m-d", sorted) the rule produces in the period starting at $period.
	 *
	 * @return string[]
	 */
	private function candidates( DateTimeImmutable $period, DateTimeImmutable $dtstart ): array {
		$weekdays = array_map( static fn( $d ) => $d[1], array_filter( $this->byday, static fn( $d ) => 0 === $d[0] ) );

		switch ( $this->freq ) {
			case 'DAILY':
				$dates = $this->matches_filters( $period ) ? array( $period ) : array();
				break;

			case 'WEEKLY':
				$days  = $this->byday ? $weekdays : array( self::DAYS[ (int) $dtstart->format( 'N' ) - 1 ] );
				$dates = array();
				for ( $i = 0; $i < 7; $i++ ) {
					$date = $period->modify( "+{$i} days" );
					if ( in_array( self::DAYS[ (int) $date->format( 'N' ) - 1 ], $days, true ) && $this->in_months( $date ) ) {
						$dates[] = $date;
					}
				}
				break;

			case 'MONTHLY':
				$dates = $this->in_months( $period ) ? $this->month_dates( $period, $dtstart ) : array();
				break;

			default:
				$dates = $this->year_dates( $period, $dtstart );
		}

		$dates = array_map( static fn( DateTimeImmutable $d ) => $d->format( 'Y-m-d' ), $dates );
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return $dates;
	}

	/**
	 * DAILY filters: BYMONTH, BYMONTHDAY, and plain BYDAY weekdays narrow each day.
	 */
	private function matches_filters( DateTimeImmutable $date ): bool {
		if ( ! $this->in_months( $date ) ) {
			return false;
		}
		if ( $this->bymonthday && ! in_array( (int) $date->format( 'j' ), $this->month_days( $date ), true ) ) {
			return false;
		}
		if ( $this->byday && ! in_array( self::DAYS[ (int) $date->format( 'N' ) - 1 ], array_map( static fn( $d ) => $d[1], $this->byday ), true ) ) {
			return false;
		}
		return true;
	}

	private function in_months( DateTimeImmutable $date ): bool {
		return ! $this->bymonth || in_array( (int) $date->format( 'n' ), $this->bymonth, true );
	}

	/**
	 * BYMONTHDAY resolved for one month (negative values count back from its end).
	 *
	 * @return int[]
	 */
	private function month_days( DateTimeImmutable $month ): array {
		$length = (int) $month->format( 't' );
		$days   = array();
		foreach ( $this->bymonthday as $day ) {
			$resolved = $day > 0 ? $day : $length + $day + 1;
			if ( $resolved >= 1 && $resolved <= $length ) {
				$days[] = $resolved;
			}
		}
		return $days;
	}

	/**
	 * @return DateTimeImmutable[]
	 */
	private function month_dates( DateTimeImmutable $month, DateTimeImmutable $dtstart ): array {
		$first = $month->modify( 'first day of this month' );
		$last  = $month->modify( 'last day of this month' );

		$by_monthday = null;
		if ( $this->bymonthday ) {
			$by_monthday = array_map( static fn( $d ) => $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $d ), $this->month_days( $month ) );
		}
		$by_day = $this->byday ? $this->weekday_dates( $first, $last ) : null;

		if ( null === $by_monthday && null === $by_day ) {
			$day = (int) $dtstart->format( 'j' );
			return $day <= (int) $month->format( 't' ) ? array( $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day ) ) : array();
		}
		if ( null === $by_monthday ) {
			return $by_day;
		}
		if ( null === $by_day ) {
			return $by_monthday;
		}
		$keys = array_map( static fn( $d ) => $d->format( 'Y-m-d' ), $by_day );
		return array_values( array_filter( $by_monthday, static fn( $d ) => in_array( $d->format( 'Y-m-d' ), $keys, true ) ) );
	}

	/**
	 * @return DateTimeImmutable[]
	 */
	private function year_dates( DateTimeImmutable $year, DateTimeImmutable $dtstart ): array {
		$y = (int) $year->format( 'Y' );

		if ( $this->bymonth ) {
			$dates = array();
			foreach ( $this->bymonth as $m ) {
				$month = $year->setDate( $y, $m, 1 );
				if ( ! $this->byday && ! $this->bymonthday ) {
					$day = (int) $dtstart->format( 'j' );
					if ( $day <= (int) $month->format( 't' ) ) {
						$dates[] = $month->setDate( $y, $m, $day );
					}
					continue;
				}
				array_push( $dates, ...$this->month_dates( $month, $dtstart ) );
			}
			return $dates;
		}

		if ( $this->byday ) {
			// Without BYMONTH, numbered weekdays count within the whole year (20MO = 20th Monday).
			$dates = $this->weekday_dates( $year->setDate( $y, 1, 1 ), $year->setDate( $y, 12, 31 ) );
			if ( $this->bymonthday ) {
				$dates = array_values( array_filter( $dates, fn( $d ) => in_array( (int) $d->format( 'j' ), $this->month_days( $d ), true ) ) );
			}
			return $dates;
		}

		if ( $this->bymonthday ) {
			$dates = array();
			for ( $m = 1; $m <= 12; $m++ ) {
				array_push( $dates, ...$this->month_dates( $year->setDate( $y, $m, 1 ), $dtstart ) );
			}
			return $dates;
		}

		$m = (int) $dtstart->format( 'n' );
		$d = (int) $dtstart->format( 'j' );
		// Feb 29 only exists in leap years; RFC 5545 skips the others.
		return checkdate( $m, $d, $y ) ? array( $year->setDate( $y, $m, $d ) ) : array();
	}

	/**
	 * BYDAY dates between $from and $to (inclusive). Ordinals count within that span.
	 *
	 * @return DateTimeImmutable[]
	 */
	private function weekday_dates( DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$by_name = array_fill_keys( self::DAYS, array() );
		for ( $d = $from; $d <= $to; $d = $d->modify( '+1 day' ) ) {
			$by_name[ self::DAYS[ (int) $d->format( 'N' ) - 1 ] ][] = $d;
		}
		$dates = array();
		foreach ( $this->byday as list( $n, $name ) ) {
			$list = $by_name[ $name ];
			if ( 0 === $n ) {
				array_push( $dates, ...$list );
			} else {
				$index = $n > 0 ? $n - 1 : count( $list ) + $n;
				if ( isset( $list[ $index ] ) ) {
					$dates[] = $list[ $index ];
				}
			}
		}
		return $dates;
	}

	/**
	 * @param string[] $dates Sorted dates in one period.
	 * @return string[]
	 */
	private function apply_setpos( array $dates ): array {
		if ( ! $this->bysetpos || ! $dates ) {
			return $dates;
		}
		$picked = array();
		foreach ( $this->bysetpos as $pos ) {
			$index = $pos > 0 ? $pos - 1 : count( $dates ) + $pos;
			if ( isset( $dates[ $index ] ) ) {
				$picked[] = $dates[ $index ];
			}
		}
		$picked = array_values( array_unique( $picked ) );
		sort( $picked );
		return $picked;
	}

	private static function positive( string $value, string $name ): int {
		if ( ! ctype_digit( $value ) || (int) $value < 1 ) {
			throw new InvalidArgumentException( sprintf( '%s must be a positive whole number.', $name ) );
		}
		return (int) $value;
	}

	/**
	 * @return int[]
	 */
	private static function numbers( string $value, string $name, int $min, int $max, bool $signed ): array {
		if ( '' === $value ) {
			return array();
		}
		$out = array();
		foreach ( explode( ',', $value ) as $item ) {
			if ( ! preg_match( $signed ? '/^[+-]?\d+$/' : '/^\+?\d+$/', $item ) ) {
				throw new InvalidArgumentException( sprintf( 'Bad %s value "%s".', $name, $item ) );
			}
			$n = (int) $item;
			if ( abs( $n ) < $min || abs( $n ) > $max ) {
				throw new InvalidArgumentException( sprintf( '%s values must be between %d and %d.', $name, $min, $max ) );
			}
			$out[] = $n;
		}
		return $out;
	}
}
