<?php
/**
 * TEC importer: parsing TEC Pro rsets and Event Tickets Plus questions.
 *
 * The full conversion is exercised against a real TEC site; these cover the
 * format translations, which are where data could quietly change.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Import\Tec;
use OpenEvents\Tests\TestCase;

final class TecImportTest extends TestCase {

	public function test_rset_with_a_weekly_rule(): void {
		$rset = "DTSTART;TZID=America/Toronto:20261027T103000\nDTEND;TZID=America/Toronto:20261027T113000\nRRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL=20261202T065959Z;WKST=SU;BYDAY=TU";
		$this->assertSame(
			array(
				'rrule'   => 'FREQ=WEEKLY;BYDAY=TU;UNTIL=20261202T065959Z;WKST=SU',
				'rdates'  => array(),
				'exdates' => array(),
			),
			Tec::recurrence( $rset )
		);
	}

	public function test_rset_lines_can_be_escaped_newlines(): void {
		// TEC stores some rsets with literal "\n" between lines.
		$rset = 'DTSTART;TZID=America/Toronto:20261027T103000\nRRULE:FREQ=DAILY;COUNT=3';
		$this->assertSame( 'FREQ=DAILY;COUNT=3', Tec::recurrence( $rset )['rrule'] );
	}

	public function test_rdate_periods_and_exdates(): void {
		$rset = "DTSTART;TZID=America/Toronto:20260101T180000\nRRULE:FREQ=WEEKLY;COUNT=4\nRDATE;TZID=America/Toronto;VALUE=PERIOD:20260210T180000/20260210T193000,20260215T100000/20260215T110000\nEXDATE;TZID=America/Toronto:20260108T180000\nEXDATE:20260115T230000Z";
		$this->assertSame(
			array(
				'rrule'   => 'FREQ=WEEKLY;COUNT=4',
				'rdates'  => array( '2026-02-10 18:00:00', '2026-02-15 10:00:00' ),
				'exdates' => array( '2026-01-08', '2026-01-15' ),
			),
			Tec::recurrence( $rset ),
			'UTC exdates are read in the event timezone (23:00Z is 18:00 in Toronto).'
		);
	}

	public function test_rset_without_a_repeat_is_null(): void {
		$this->assertNull( Tec::recurrence( "DTSTART;TZID=America/Toronto:20260101T180000\nDTEND;TZID=America/Toronto:20260101T190000" ) );
	}

	public function test_event_tickets_plus_questions(): void {
		$long   = str_repeat( 'i-understand-this-is-collected-', 5 );
		$result = Tec::questions(
			array(
				array(
					'type'     => 'text',
					'label'    => 'Name',
					'slug'     => 'name',
					'required' => 'on',
				),
				array(
					'type'     => 'telephone',
					'label'    => 'Phone Number',
					'slug'     => 'phone-number',
					'required' => '',
				),
				array(
					'type'     => 'checkbox',
					'label'    => 'Consent',
					'slug'     => $long,
					'required' => 'on',
					'extra'    => array( 'options' => array( 'Yes' ) ),
				),
				array(
					'type'  => 'hidden',
					'label' => 'Ignored',
					'slug'  => 'ignored',
				),
			)
		);

		$this->assertSame( array( 'text', 'tel', 'checkbox' ), array_column( $result['fields'], 'type' ) );
		$this->assertSame( array( true, false, true ), array_column( $result['fields'], 'required' ) );
		$this->assertSame( substr( $long, 0, 40 ), $result['keys'][ $long ], 'Long ET+ slugs are shortened to a stable key.' );
	}

	public function test_event_tickets_plus_answers(): void {
		$long = str_repeat( 'consent-', 10 );
		$keys = Tec::questions(
			array(
				array(
					'type'  => 'telephone',
					'label' => 'Phone',
					'slug'  => 'phone-number',
				),
				array(
					'type'  => 'checkbox',
					'label' => 'Days',
					'slug'  => $long,
					'extra' => array( 'options' => array( 'Mon', 'Tue' ) ),
				),
			)
		)['keys'];

		$answers = Tec::answers(
			array(
				'tribe-tickets-plus-iac-name'      => 'Kid One',
				'phone-number'                     => '604 555 0100',
				$long . '_' . md5( 'Mon' )         => 'Mon',
				$long . '_' . md5( 'Tue' )         => 'Tue',
				'removed-question'                 => 'Old answer',
				0                                  => '',
			),
			$keys
		);

		$this->assertSame(
			array(
				'phone-number'          => '604 555 0100',
				$keys[ $long ]          => array( 'Mon', 'Tue' ),
				'removed-question'      => 'Old answer',
			),
			$answers,
			'Per-option checkbox answers join into one list; IAC name and email are not answers; removed questions are kept.'
		);
	}
}
