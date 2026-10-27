<?php
/**
 * Rendered output: formatter, calendar views, event details, iCal, colours.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\PostTypes;
use OpenEvents\Frontend\CalendarView;
use OpenEvents\Frontend\CategoryColors;
use OpenEvents\Frontend\EventDetails;
use OpenEvents\Frontend\Formatter;
use OpenEvents\Modules\ICal\Writer;
use OpenEvents\Tests\TestCase;

final class OutputTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		add_filter( 'openevents/calendar/cache', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		unset( $_GET['oe_month'], $_GET['oe_view'] );
		parent::tear_down();
	}

	private function occurrence( array $meta ) {
		$id = $this->event( $meta );
		return openevents()->occurrences()->for_event( $id );
	}

	/**
	 * @dataProvider ranges
	 */
	public function test_format_range( array $meta, string $expected ): void {
		$this->assertSame( $expected, Formatter::range( $this->occurrence( $meta ) ) );
	}

	public static function ranges(): array {
		return array(
			'same day'         => array(
				array(
					'_oe_start' => '2030-11-03 19:00:00',
					'_oe_end'   => '2030-11-03 21:30:00',
				),
				'November 3, 2030, 7:00 pm – 9:30 pm',
			),
			'multi-day'        => array(
				array(
					'_oe_start' => '2030-11-03 19:00:00',
					'_oe_end'   => '2030-11-04 01:00:00',
				),
				'November 3, 2030, 7:00 pm – November 4, 2030, 1:00 am',
			),
			'all day'          => array(
				array(
					'_oe_start'   => '2030-11-03 00:00:00',
					'_oe_all_day' => true,
				),
				'November 3, 2030',
			),
			'all day, 3 days'  => array(
				array(
					'_oe_start'   => '2030-11-03 00:00:00',
					'_oe_end'     => '2030-11-05 00:00:00',
					'_oe_all_day' => true,
				),
				'November 3, 2030 – November 5, 2030',
			),
			'other timezone'   => array(
				array(
					'_oe_start'    => '2030-11-03 19:00:00',
					'_oe_end'      => '2030-11-03 20:00:00',
					'_oe_timezone' => 'America/Toronto',
				),
				'November 3, 2030, 7:00 pm – 8:00 pm EST',
			),
		);
	}

	public function test_month_grid_places_multi_day_events_on_each_day(): void {
		$this->event(
			array(
				'_oe_start'   => '2030-05-12 00:00:00',
				'_oe_end'     => '2030-05-14 00:00:00',
				'_oe_all_day' => true,
			),
			array( 'post_title' => 'Festival' )
		);
		$_GET['oe_month'] = '2030-05';

		$html = ( new CalendarView( array( 'views' => array( 'month' ) ) ) )->render();

		$this->assertSame( 3, substr_count( $html, '>Festival<' ) );
		$this->assertStringContainsString( 'data-wp-router-region=', $html );
		$this->assertStringContainsString( 'May 2030', $html );
	}

	public function test_month_navigation_is_clamped_and_nofollow(): void {
		$_GET['oe_month'] = '2999-01';
		$html             = ( new CalendarView( array( 'views' => array( 'month' ) ) ) )->render();

		$limit = current_datetime()->modify( 'first day of this month' )->modify( '+5 years' );
		$this->assertStringContainsString( wp_date( 'F Y', $limit->getTimestamp() ), $html, 'Out-of-range months clamp.' );
		$this->assertStringNotContainsString( 'aria-label="Next month"', $html, 'No link past the range.' );
		$this->assertStringContainsString( 'aria-label="Previous month"', $html );
		preg_match_all( '/<a class="oe-calendar__nav-link[^"]*" href="[^"]*" rel="([^"]*)"/', $html, $rels );
		$this->assertNotEmpty( $rels[1] );
		$this->assertSame( array( 'nofollow' ), array_values( array_unique( $rels[1] ) ) );
	}

	public function test_navigation_urls_are_noindexed(): void {
		$_GET['oe_month'] = '2030-05';
		$robots           = apply_filters( 'wp_robots', array() );

		$this->assertTrue( $robots['noindex'] ?? false );
		$this->assertTrue( $robots['follow'] ?? false );
	}

	public function test_list_view_paginates(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$day = current_datetime()->modify( "+{$i} days" )->format( 'Y-m-d' );
			$this->event(
				array(
					'_oe_start' => "{$day} 10:00:00",
					'_oe_end'   => "{$day} 11:00:00",
				)
			);
		}
		$html = ( new CalendarView(
			array(
				'views'   => array( 'list' ),
				'perPage' => 2,
			)
		) )->render();

		$this->assertSame( 2, substr_count( $html, '<article class="oe-event-card' ) );
		$this->assertStringContainsString( 'Page 1 of 3', $html );
		$this->assertStringContainsString( 'oe_page=2', $html );
	}

	public function test_event_details_rows_and_extension_filters(): void {
		$venue = self::factory()->post->create(
			array(
				'post_type'  => PostTypes::VENUE,
				'post_title' => 'Main Branch',
				'meta_input' => array(
					'_oe_address' => '1950 Marine Dr',
					'_oe_city'    => 'West Vancouver',
				),
			)
		);
		$id    = $this->event(
			array(
				'_oe_start'    => '2030-05-05 10:00:00',
				'_oe_end'      => '2030-05-05 11:00:00',
				'_oe_venue_id' => $venue,
				'_oe_cost'     => '0',
			)
		);
		$add   = static function ( array $sections ): array {
			$sections['rsvp'] = '<form class="rsvp-test"></form>';
			return $sections;
		};
		add_filter( 'openevents/event_details/sections', $add );

		$html = EventDetails::render( $id );
		remove_filter( 'openevents/event_details/sections', $add );

		$this->assertStringContainsString( 'Main Branch', $html );
		$this->assertStringContainsString( '1950 Marine Dr, West Vancouver', $html );
		$this->assertStringContainsString( '<dd>Free</dd>', $html );
		$this->assertStringContainsString( 'class="rsvp-test"', $html, 'Modules can add sections.' );
		$this->assertStringContainsString( 'Download .ics', $html, 'iCal module adds links.' );
	}

	public function test_existing_post_without_date_is_untouched(): void {
		$this->settings( array( 'event_post_types' => array( 'post' ) ) );
		$id = $this->event(
			array(),
			array(
				'post_type'    => 'post',
				'post_content' => 'Hello',
			)
		);
		$this->go_to( get_permalink( $id ) );
		the_post();

		$this->assertSame( '', EventDetails::render( $id ) );
		$this->assertStringNotContainsString( 'oe-details', apply_filters( 'the_content', 'Hello' ) );
	}

	public function test_ical_document_is_valid(): void {
		$id   = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			),
			array( 'post_title' => 'Read, Write; Repeat' )
		);
		$all  = $this->event(
			array(
				'_oe_start'   => '2030-05-06 00:00:00',
				'_oe_all_day' => true,
			)
		);
		$body = Writer::calendar( openevents()->occurrences()->query( array( 'per_page' => -1 ) )['items'] );

		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $body );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $body );
		$this->assertSame( 2, substr_count( $body, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'DTSTART:20300505T170000Z', $body, 'Timed events in UTC.' );
		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:20300506', $body, 'All-day events as dates.' );
		$this->assertStringContainsString( 'DTEND;VALUE=DATE:20300507', $body, 'All-day DTEND is exclusive.' );
		$this->assertStringContainsString( 'SUMMARY:Read\, Write\; Repeat', $body, 'TEXT escaping.' );
		$this->assertStringContainsString( "UID:{$id}-0@", $body );
		$this->assertStringContainsString( "UID:{$all}-0@", $body );
	}

	public function test_ical_lines_fold_at_75_octets_without_splitting_utf8(): void {
		$line   = 'DESCRIPTION:' . str_repeat( 'é', 60 );
		$folded = Writer::fold( $line );

		foreach ( explode( "\r\n", $folded ) as $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
			$this->assertTrue( mb_check_encoding( $part, 'UTF-8' ) );
		}
		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );
	}

	/**
	 * @dataProvider colors
	 */
	public function test_category_colour_text_contrast( string $background, string $text ): void {
		$this->assertSame( $text, CategoryColors::contrast( $background ) );
	}

	public static function colors(): array {
		return array(
			'yellow' => array( '#f59e0b', '#000000' ),
			'purple' => array( '#7c3aed', '#ffffff' ),
			'white'  => array( '#fff', '#000000' ),
			'navy'   => array( '#1e3a8a', '#ffffff' ),
		);
	}

	public function test_event_details_show_coloured_category_badges(): void {
		$music = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'name' => 'Music' ) );
		$plain = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'name' => 'Talks' ) );
		update_term_meta( $music, CategoryColors::META_KEY, '#7c3aed' );
		$id = $this->event( array( '_oe_start' => '2030-05-05 10:00:00' ) );
		wp_set_object_terms( $id, array( $music, $plain ), PostTypes::CATEGORY );

		$html = EventDetails::render( $id );

		$this->assertStringContainsString( '<dt>Categories</dt>', $html );
		$this->assertMatchesRegularExpression( '/<a class="oe-badge has-color" href="[^"]+" style="--oe-event-bg:#7c3aed;--oe-event-fg:#ffffff;">Music<\/a>/', $html );
		$this->assertMatchesRegularExpression( '/<a class="oe-badge" href="[^"]+">Talks<\/a>/', $html );
	}

	public function test_category_colour_style_on_events(): void {
		$term = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY ) );
		update_term_meta( $term, CategoryColors::META_KEY, '#7c3aed' );
		$id = $this->event( array( '_oe_start' => '2030-05-05 10:00:00' ) );
		wp_set_object_terms( $id, array( $term ), PostTypes::CATEGORY );

		$this->assertSame( '--oe-event-bg:#7c3aed;--oe-event-fg:#ffffff;', CategoryColors::style_for( $id ) );
	}
}
