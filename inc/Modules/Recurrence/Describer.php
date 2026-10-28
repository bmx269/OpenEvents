<?php
/**
 * Plain-language rule descriptions.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Recurrence;

use DateTimeImmutable;

/**
 * Turns a rule into a sentence such as "Every 2 weeks on Monday and Wednesday,
 * until March 1, 2027". Used on event pages, in the editor preview, and in REST.
 */
final class Describer {

	public static function describe( Rule $rule, DateTimeImmutable $dtstart ): string {
		$parts = array( self::frequency( $rule ) );

		$on = self::on( $rule, $dtstart );
		if ( 'WEEKLY' === $rule->freq && 1 === $rule->interval && __( 'weekdays', 'openevents' ) === $on ) {
			$parts[0] = __( 'Every weekday', 'openevents' );
		} elseif ( '' !== $on ) {
			/* translators: %s: days, e.g. "Monday and Wednesday" or "the first Friday" */
			$parts[0] = sprintf( __( '%1$s on %2$s', 'openevents' ), $parts[0], $on );
		}

		if ( $rule->bymonth && 'YEARLY' !== $rule->freq ) {
			/* translators: %s: list of months */
			$parts[] = sprintf( __( 'in %s', 'openevents' ), self::join( array_map( array( self::class, 'month' ), $rule->bymonth ) ) );
		}

		if ( null !== $rule->count ) {
			/* translators: %d: number of times */
			$parts[] = sprintf( _n( '%d time', '%d times', $rule->count, 'openevents' ), $rule->count );
		} elseif ( null !== $rule->until ) {
			$until = $rule->until_in( $dtstart->getTimezone() );
			/* translators: %s: end date */
			$parts[] = sprintf( __( 'until %s', 'openevents' ), wp_date( (string) get_option( 'date_format' ), $until->getTimestamp(), $until->getTimezone() ) );
		}

		return implode( ', ', $parts );
	}

	private static function frequency( Rule $rule ): string {
		$n = $rule->interval;
		switch ( $rule->freq ) {
			case 'DAILY':
				/* translators: %d: number of days */
				return 1 === $n ? __( 'Every day', 'openevents' ) : sprintf( _n( 'Every %d day', 'Every %d days', $n, 'openevents' ), $n );
			case 'WEEKLY':
				/* translators: %d: number of weeks */
				return 1 === $n ? __( 'Every week', 'openevents' ) : sprintf( _n( 'Every %d week', 'Every %d weeks', $n, 'openevents' ), $n );
			case 'MONTHLY':
				/* translators: %d: number of months */
				return 1 === $n ? __( 'Every month', 'openevents' ) : sprintf( _n( 'Every %d month', 'Every %d months', $n, 'openevents' ), $n );
			default:
				/* translators: %d: number of years */
				return 1 === $n ? __( 'Every year', 'openevents' ) : sprintf( _n( 'Every %d year', 'Every %d years', $n, 'openevents' ), $n );
		}
	}

	private static function on( Rule $rule, DateTimeImmutable $dtstart ): string {
		$weekdays = array( array( 0, 'MO' ), array( 0, 'TU' ), array( 0, 'WE' ), array( 0, 'TH' ), array( 0, 'FR' ) );
		$is_weekdays = $rule->byday === $weekdays;

		if ( $rule->bysetpos && $is_weekdays ) {
			/* translators: %s: ordinal(s), e.g. "last" */
			return sprintf( __( 'the %s weekday', 'openevents' ), self::join( array_map( array( self::class, 'ordinal' ), $rule->bysetpos ) ) );
		}
		if ( $is_weekdays && ! $rule->bymonthday ) {
			return __( 'weekdays', 'openevents' );
		}
		if ( $rule->bysetpos && $rule->byday ) {
			$days = self::join( array_map( static fn( $d ) => self::day( $d[1] ), $rule->byday ) );
			$pos  = self::join( array_map( array( self::class, 'ordinal' ), $rule->bysetpos ) );
			/* translators: 1: ordinal(s), e.g. "last"; 2: days, e.g. "Monday, Tuesday, or Friday" */
			return sprintf( __( 'the %1$s of %2$s', 'openevents' ), $pos, $days );
		}

		$items = array();
		foreach ( $rule->byday as list( $n, $code ) ) {
			$items[] = 0 === $n
				? self::day( $code )
				/* translators: 1: ordinal, e.g. "second"; 2: weekday */
				: sprintf( __( 'the %1$s %2$s', 'openevents' ), self::ordinal( $n ), self::day( $code ) );
		}
		foreach ( $rule->bymonthday as $day ) {
			$items[] = $day > 0
				/* translators: %d: day of the month */
				? sprintf( __( 'day %d', 'openevents' ), $day )
				/* translators: %s: ordinal counting back from the end, e.g. "last" */
				: sprintf( __( 'the %s day', 'openevents' ), self::ordinal( $day ) );
		}

		if ( 'YEARLY' === $rule->freq ) {
			$months = $rule->bymonth ? $rule->bymonth : array( (int) $dtstart->format( 'n' ) );
			$months = self::join( array_map( array( self::class, 'month' ), $months ) );
			if ( ! $items ) {
				/* translators: 1: month(s), 2: day of the month */
				return sprintf( __( '%1$s %2$d', 'openevents' ), $months, (int) $dtstart->format( 'j' ) );
			}
			/* translators: 1: days, 2: month(s) */
			return sprintf( __( '%1$s of %2$s', 'openevents' ), self::join( $items ), $months );
		}

		if ( ! $items && 'MONTHLY' === $rule->freq ) {
			/* translators: %d: day of the month */
			return sprintf( __( 'day %d', 'openevents' ), (int) $dtstart->format( 'j' ) );
		}
		if ( ! $items && 'WEEKLY' === $rule->freq ) {
			return self::day( Rule::DAYS[ (int) $dtstart->format( 'N' ) - 1 ] );
		}
		return self::join( $items );
	}

	private static function ordinal( int $n ): string {
		$positive = array(
			1 => __( 'first', 'openevents' ),
			2 => __( 'second', 'openevents' ),
			3 => __( 'third', 'openevents' ),
			4 => __( 'fourth', 'openevents' ),
			5 => __( 'fifth', 'openevents' ),
		);
		if ( -1 === $n ) {
			return __( 'last', 'openevents' );
		}
		if ( -2 === $n ) {
			return __( 'second to last', 'openevents' );
		}
		if ( $n > 0 && isset( $positive[ $n ] ) ) {
			return $positive[ $n ];
		}
		/* translators: %d: position, e.g. 20 for "20th" */
		return $n > 0 ? sprintf( __( 'number %d', 'openevents' ), $n ) : sprintf( __( 'number %d from the end', 'openevents' ), abs( $n ) );
	}

	private static function day( string $code ): string {
		global $wp_locale;
		// Rule::DAYS is Monday-first; WP_Locale::get_weekday() is Sunday-first.
		return $wp_locale->get_weekday( ( array_search( $code, Rule::DAYS, true ) + 1 ) % 7 );
	}

	private static function month( int $month ): string {
		global $wp_locale;
		return $wp_locale->get_month( $month );
	}

	/**
	 * "A", "A and B", "A, B, and C" (wp_sprintf_l uses the locale's list words).
	 *
	 * @param string[] $items Items.
	 */
	private static function join( array $items ): string {
		return wp_sprintf( '%l', $items );
	}
}
