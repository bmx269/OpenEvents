<?php
/**
 * RRULE parsing and expansion, mostly against the examples in RFC 5545 §3.8.5.3.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OpenEvents\Modules\Recurrence\Rule;
use PHPUnit\Framework\TestCase;

final class RuleTest extends TestCase {

	/**
	 * Dates ("Y-m-d") a rule produces from a start, up to a limit.
	 *
	 * @return string[]
	 */
	private function dates( string $rrule, string $start, string $limit = '2000-12-31', string $tz = 'America/New_York' ): array {
		$zone = new DateTimeZone( $tz );
		$rule = Rule::parse( $rrule );
		return array_map(
			static fn( DateTimeImmutable $d ) => $d->format( 'Y-m-d' ),
			$rule->starts( new DateTimeImmutable( $start, $zone ), new DateTimeImmutable( $limit . ' 23:59:59', $zone ) )
		);
	}

	public function test_daily_count(): void {
		$this->assertSame(
			array( '1997-09-02', '1997-09-03', '1997-09-04', '1997-09-05', '1997-09-06', '1997-09-07', '1997-09-08', '1997-09-09', '1997-09-10', '1997-09-11' ),
			$this->dates( 'FREQ=DAILY;COUNT=10', '1997-09-02 09:00:00' )
		);
	}

	public function test_every_other_day_until_limit(): void {
		$dates = $this->dates( 'FREQ=DAILY;INTERVAL=2', '1997-09-02 09:00:00', '1997-09-12' );
		$this->assertSame( array( '1997-09-02', '1997-09-04', '1997-09-06', '1997-09-08', '1997-09-10', '1997-09-12' ), $dates );
	}

	public function test_until_date_is_inclusive(): void {
		$dates = $this->dates( 'FREQ=DAILY;UNTIL=19970905', '1997-09-02 09:00:00' );
		$this->assertSame( array( '1997-09-02', '1997-09-03', '1997-09-04', '1997-09-05' ), $dates );
	}

	public function test_utc_until_converts_to_the_event_timezone(): void {
		// 1997-09-05 13:00 UTC is 09:00 in New York, so the 5th is included; 12:59 UTC would not be.
		$this->assertSame( '1997-09-05', array_slice( $this->dates( 'FREQ=DAILY;UNTIL=19970905T130000Z', '1997-09-02 09:00:00' ), -1 )[0] );
		$this->assertSame( '1997-09-04', array_slice( $this->dates( 'FREQ=DAILY;UNTIL=19970905T125900Z', '1997-09-02 09:00:00' ), -1 )[0] );
	}

	public function test_weekly_on_tuesday_and_thursday_for_five_weeks(): void {
		$this->assertSame(
			array( '1997-09-02', '1997-09-04', '1997-09-09', '1997-09-11', '1997-09-16', '1997-09-18', '1997-09-23', '1997-09-25', '1997-09-30', '1997-10-02' ),
			$this->dates( 'FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH', '1997-09-02 09:00:00' )
		);
	}

	public function test_every_other_week_monday_wednesday_friday(): void {
		$this->assertSame(
			array( '1997-09-01', '1997-09-03', '1997-09-05', '1997-09-15', '1997-09-17', '1997-09-19', '1997-09-29', '1997-10-01', '1997-10-03' ),
			$this->dates( 'FREQ=WEEKLY;INTERVAL=2;WKST=SU;BYDAY=MO,WE,FR;COUNT=9', '1997-09-01 09:00:00' )
		);
	}

	public function test_wkst_changes_which_week_a_day_falls_in(): void {
		// RFC 5545's WKST example: the same rule gives different dates for MO and SU week starts.
		$this->assertSame( array( '1997-08-05', '1997-08-10', '1997-08-19', '1997-08-24' ), $this->dates( 'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO', '1997-08-05 09:00:00' ) );
		$this->assertSame( array( '1997-08-05', '1997-08-17', '1997-08-19', '1997-08-31' ), $this->dates( 'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU', '1997-08-05 09:00:00' ) );
	}

	public function test_monthly_on_first_friday(): void {
		$this->assertSame(
			array( '1997-09-05', '1997-10-03', '1997-11-07', '1997-12-05', '1998-01-02', '1998-02-06', '1998-03-06', '1998-04-03', '1998-05-01', '1998-06-05' ),
			$this->dates( 'FREQ=MONTHLY;COUNT=10;BYDAY=1FR', '1997-09-05 09:00:00' )
		);
	}

	public function test_monthly_on_second_to_last_monday(): void {
		$this->assertSame(
			array( '1997-09-22', '1997-10-20', '1997-11-17', '1997-12-22', '1998-01-19', '1998-02-16' ),
			$this->dates( 'FREQ=MONTHLY;COUNT=6;BYDAY=-2MO', '1997-09-22 09:00:00' )
		);
	}

	public function test_monthly_on_the_third_to_last_day(): void {
		$this->assertSame( array( '1997-09-28', '1997-10-29', '1997-11-28', '1997-12-29', '1998-01-29', '1998-02-26' ), $this->dates( 'FREQ=MONTHLY;BYMONTHDAY=-3;COUNT=6', '1997-09-28 09:00:00' ) );
	}

	public function test_monthly_on_the_31st_skips_short_months(): void {
		$this->assertSame( array( '1997-01-31', '1997-03-31', '1997-05-31', '1997-07-31' ), $this->dates( 'FREQ=MONTHLY;COUNT=4', '1997-01-31 09:00:00' ) );
	}

	public function test_friday_the_13th(): void {
		$this->assertSame(
			array( '1998-02-13', '1998-03-13', '1998-11-13', '1999-08-13', '2000-10-13' ),
			array_slice( $this->dates( 'FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13', '1997-09-02 09:00:00' ), 0, 5 )
		);
	}

	public function test_last_weekday_of_the_month_with_setpos(): void {
		$this->assertSame(
			array( '1997-09-30', '1997-10-31', '1997-11-28', '1997-12-31', '1998-01-30', '1998-02-27', '1998-03-31' ),
			array_slice( $this->dates( 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1', '1997-09-29 09:00:00' ), 0, 7 )
		);
	}

	public function test_yearly_in_june_and_july(): void {
		$this->assertSame(
			array( '1997-06-10', '1997-07-10', '1998-06-10', '1998-07-10', '1999-06-10', '1999-07-10' ),
			$this->dates( 'FREQ=YEARLY;COUNT=6;BYMONTH=6,7', '1997-06-10 09:00:00' )
		);
	}

	public function test_yearly_twentieth_monday(): void {
		$this->assertSame( array( '1997-05-19', '1998-05-18', '1999-05-17' ), $this->dates( 'FREQ=YEARLY;BYDAY=20MO;COUNT=3', '1997-05-19 09:00:00' ) );
	}

	public function test_us_presidential_election_day(): void {
		// Every 4 years, the first Tuesday after a Monday in November.
		$this->assertSame(
			array( '1996-11-05', '2000-11-07', '2004-11-02' ),
			$this->dates( 'FREQ=YEARLY;INTERVAL=4;BYMONTH=11;BYDAY=TU;BYMONTHDAY=2,3,4,5,6,7,8;COUNT=3', '1996-11-05 09:00:00', '2010-01-01' )
		);
	}

	public function test_yearly_on_february_29_skips_non_leap_years(): void {
		$this->assertSame( array( '2024-02-29', '2028-02-29', '2032-02-29' ), $this->dates( 'FREQ=YEARLY;COUNT=3', '2024-02-29 18:00:00', '2040-01-01' ) );
	}

	public function test_wall_clock_time_holds_across_dst(): void {
		$zone   = new DateTimeZone( 'America/Toronto' );
		$starts = Rule::parse( 'FREQ=WEEKLY;COUNT=3' )->starts( new DateTimeImmutable( '2026-10-25 19:00:00', $zone ), new DateTimeImmutable( '2027-01-01', $zone ) );
		$this->assertSame( array( '19:00', '19:00', '19:00' ), array_map( static fn( $d ) => $d->format( 'H:i' ), $starts ) );
		// DST ends in Toronto on Nov 1, 2026: the UTC offset changes, the local time does not.
		$this->assertSame( array( '-04:00', '-05:00', '-05:00' ), array_map( static fn( $d ) => $d->format( 'P' ), $starts ) );
	}

	public function test_open_ended_rules_stop_at_the_limit_and_cap(): void {
		$this->assertCount( 31, $this->dates( 'FREQ=DAILY', '2026-01-01 09:00:00', '2026-01-31' ) );
		$zone = new DateTimeZone( 'UTC' );
		$this->assertCount( 50, Rule::parse( 'FREQ=DAILY' )->starts( new DateTimeImmutable( '2026-01-01', $zone ), new DateTimeImmutable( '2030-01-01', $zone ), 50 ) );
	}

	public function test_impossible_rule_terminates(): void {
		$this->assertSame( array(), $this->dates( 'FREQ=MONTHLY;BYMONTH=2;BYMONTHDAY=30', '2026-01-01 09:00:00', '2030-01-01' ) );
	}

	public function test_round_trips_to_a_canonical_string(): void {
		$this->assertSame( 'FREQ=MONTHLY;INTERVAL=2;BYDAY=-1FR,2TU;COUNT=4', (string) Rule::parse( 'rrule:freq=monthly;byday=-1FR,+2TU;interval=2;count=4' ) );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20270101;WKST=SU', (string) Rule::parse( 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20270101;WKST=SU' ) );
	}

	/**
	 * @dataProvider invalid_rules
	 */
	public function test_rejects_invalid_or_unsupported_rules( string $rrule ): void {
		$this->expectException( InvalidArgumentException::class );
		Rule::parse( $rrule );
	}

	public static function invalid_rules(): array {
		return array(
			'empty'                => array( '' ),
			'no freq'              => array( 'COUNT=3' ),
			'hourly'               => array( 'FREQ=HOURLY' ),
			'count and until'      => array( 'FREQ=DAILY;COUNT=2;UNTIL=20270101' ),
			'zero interval'        => array( 'FREQ=DAILY;INTERVAL=0' ),
			'unsupported part'     => array( 'FREQ=YEARLY;BYWEEKNO=20' ),
			'numbered weekly day'  => array( 'FREQ=WEEKLY;BYDAY=2MO' ),
			'bad day'              => array( 'FREQ=WEEKLY;BYDAY=XX' ),
			'month out of range'   => array( 'FREQ=YEARLY;BYMONTH=13' ),
			'monthday zero'        => array( 'FREQ=MONTHLY;BYMONTHDAY=0' ),
			'malformed until'      => array( 'FREQ=DAILY;UNTIL=2027-01-01' ),
			'malformed part'       => array( 'FREQ=DAILY;COUNT' ),
		);
	}
}
