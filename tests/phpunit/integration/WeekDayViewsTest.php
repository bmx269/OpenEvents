<?php
/**
 * Week and day calendar views.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Settings;
use OpenEvents\Tests\TestCase;

final class WeekDayViewsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		update_option( 'start_of_week', 1 );
		add_filter( 'openevents/calendar/cache', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		unset( $_GET['oe_view'], $_GET['oe_date'] );
		parent::tear_down();
	}

	private function calendar( string $view, string $date ): string {
		$_GET['oe_view'] = $view;
		$_GET['oe_date'] = $date;
		return do_blocks( '<!-- wp:openevents/calendar {"views":["month","week","day","list"]} /-->' );
	}

	private function dated( string $title, string $start, string $end, array $meta = array() ): int {
		$id = $this->event(
			array_merge(
				array(
					'_oe_start' => $start,
					'_oe_end'   => $end,
				),
				$meta
			),
			array( 'post_title' => $title )
		);
		$this->rows( $id );
		return $id;
	}

	public function test_week_shows_seven_days_from_the_start_of_week(): void {
		// Wednesday 2030-05-08: the week runs Monday 6th to Sunday 12th.
		$this->dated( 'Monday talk', '2030-05-06 18:00:00', '2030-05-06 19:00:00' );
		$this->dated( 'Sunday fair', '2030-05-12 10:00:00', '2030-05-12 12:00:00' );
		$this->dated( 'Following Monday', '2030-05-13 10:00:00', '2030-05-13 11:00:00' );

		$html = $this->calendar( 'week', '2030-05-08' );

		$this->assertStringContainsString( 'oe-calendar--week', $html );
		$this->assertSame( 7, substr_count( $html, '<li class="oe-week__day' ) );
		$this->assertMatchesRegularExpression( '#<time datetime="2030-05-06">6</time>.*Monday talk#s', $html );
		$this->assertMatchesRegularExpression( '#<time datetime="2030-05-12">12</time>.*Sunday fair#s', $html );
		$this->assertStringNotContainsString( 'Following Monday', $html );
		$this->assertStringContainsString( 'oe_date=2030-05-13', $html, 'Next week link.' );
	}

	public function test_week_lists_all_day_and_multi_day_events_first(): void {
		$this->dated( 'Morning class', '2030-05-08 09:00:00', '2030-05-08 10:00:00' );
		$this->dated( 'Book festival', '2030-05-07 00:00:00', '2030-05-09 23:59:59', array( '_oe_all_day' => true ) );

		$html = $this->calendar( 'week', '2030-05-08' );
		preg_match( '#<time datetime="2030-05-08">8</time>.*?</li></ul></li>#s', $html, $wednesday );

		$this->assertNotEmpty( $wednesday );
		$this->assertLessThan( strpos( $wednesday[0], 'Morning class' ), strpos( $wednesday[0], 'Book festival' ) );
		$this->assertSame( 3, substr_count( $html, 'Book festival' ), 'A multi-day event shows on each of its days.' );
	}

	public function test_day_view_lists_the_days_events(): void {
		$this->dated( 'Evening concert', '2030-05-08 19:00:00', '2030-05-08 21:00:00' );
		$this->dated( 'Other day', '2030-05-09 19:00:00', '2030-05-09 21:00:00' );

		$html = $this->calendar( 'day', '2030-05-08' );

		$this->assertStringContainsString( 'Wednesday, May 8, 2030', $html );
		$this->assertStringContainsString( 'Evening concert', $html );
		$this->assertStringNotContainsString( 'Other day', $html );
		$this->assertStringContainsString( 'oe_date=2030-05-07', $html, 'Previous day link.' );
		$this->assertStringContainsString( 'oe_date=2030-05-09', $html, 'Next day link.' );
	}

	public function test_day_view_says_when_nothing_is_on(): void {
		$this->assertStringContainsString( 'No events scheduled on this day.', $this->calendar( 'day', '2030-05-08' ) );
	}

	public function test_month_more_link_opens_the_day(): void {
		foreach ( array( 9, 10, 11, 12 ) as $hour ) {
			$this->dated( "Session {$hour}", sprintf( '2030-05-08 %02d:00:00', $hour ), sprintf( '2030-05-08 %02d:30:00', $hour ) );
		}
		$_GET['oe_view']  = 'month';
		$_GET['oe_month'] = '2030-05';
		$html             = do_blocks( '<!-- wp:openevents/calendar {"views":["month","week","day","list"]} /-->' );
		unset( $_GET['oe_view'], $_GET['oe_month'] );

		$this->assertMatchesRegularExpression( '#class="oe-month__more" href="[^"]*oe_view=day[^"]*oe_date=2030-05-08#', str_replace( '&#038;', '&', $html ) );
	}

	public function test_settings_accept_week_and_day(): void {
		$this->assertSame( 'week', Settings::sanitize( array( 'default_view' => 'week' ) )['default_view'] );
		$this->assertSame( 'day', Settings::sanitize( array( 'default_view' => 'day' ) )['default_view'] );
		$this->assertSame( 'month', Settings::sanitize( array( 'default_view' => 'photo' ) )['default_view'] );
	}
}
