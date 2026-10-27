<?php
/**
 * Calendar filter and search.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\PostTypes;
use OpenEvents\Frontend\CalendarView;
use OpenEvents\Tests\TestCase;

final class CalendarFilterTest extends TestCase {

	private int $music;
	private int $jazz;
	private int $talks;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'openevents/calendar/cache', '__return_false' );
		$this->music = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'name' => 'Music', 'slug' => 'music' ) );
		$this->jazz  = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'name' => 'Jazz', 'slug' => 'jazz', 'parent' => $this->music ) );
		$this->talks = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'name' => 'Talks', 'slug' => 'talks' ) );

		$day = '2030-05-1';
		foreach ( array( array( 'Big Band Night', $this->jazz, 2 ), array( 'Author Talk', $this->talks, 3 ), array( 'Choir Concert', $this->music, 4 ) ) as $e ) {
			$id = $this->event( array( '_oe_start' => "{$day}{$e[2]} 19:00:00", '_oe_end' => "{$day}{$e[2]} 20:00:00" ), array( 'post_title' => $e[0] ) );
			wp_set_object_terms( $id, array( $e[1] ), PostTypes::CATEGORY );
		}
		$_GET['oe_month'] = '2030-05';
	}

	public function tear_down(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		unset( $_GET['oe_month'], $_GET['oe_cat'], $_GET['oe_s'], $_GET['oe_view'] );
		parent::tear_down();
	}

	private function render( array $attributes = array() ): string {
		return ( new CalendarView( array_merge( array( 'views' => array( 'month', 'list' ), 'showFilters' => true ), $attributes ) ) )->render();
	}

	public function test_filter_form_is_labelled_and_lists_categories_hierarchically(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'role="search" aria-label="Filter events"', $html );
		$this->assertMatchesRegularExpression( '/<label for="(oe-filters-\d+)-cat">Category<\/label><select id="\1-cat" name="oe_cat">/', $html );
		$this->assertMatchesRegularExpression( '/<label for="(oe-filters-\d+)-s">Search events<\/label><input id="\1-s" type="search"/', $html );
		$this->assertStringContainsString( '<option value="jazz">— Jazz</option>', $html, 'Children are indented under parents.' );
		$this->assertStringContainsString( '<input type="hidden" name="oe_month" value="2030-05">', $html, 'Filtering keeps the month.' );
	}

	public function test_category_filter_includes_child_categories(): void {
		$_GET['oe_cat'] = 'music';
		$html           = $this->render();

		$this->assertStringContainsString( 'Big Band Night', $html );
		$this->assertStringContainsString( 'Choir Concert', $html );
		$this->assertStringNotContainsString( 'Author Talk', $html );
		$this->assertStringContainsString( 'Showing Music events', $html );
		$this->assertStringContainsString( '<option value="music" selected=\'selected\'>', $html );
	}

	public function test_search_filters_by_keyword_and_combines_with_category(): void {
		$_GET['oe_s'] = 'night';
		$this->assertStringContainsString( 'Big Band Night', $this->render() );
		$this->assertStringNotContainsString( 'Choir Concert', $this->render() );

		$_GET['oe_cat'] = 'talks';
		$html           = $this->render();
		$this->assertStringContainsString( 'Showing Talks events matching “night”', $html );
		$this->assertStringContainsString( 'No events match these filters this month.', $html );
		$this->assertStringContainsString( 'Clear filters', $html );
	}

	public function test_block_category_restriction_cannot_be_bypassed(): void {
		$_GET['oe_cat'] = 'talks';
		$html           = $this->render( array( 'categories' => array( $this->music ) ) );

		$this->assertStringNotContainsString( 'Author Talk', $html, 'A category outside the block’s scope is ignored.' );
		$this->assertStringNotContainsString( 'value="talks"', $html, 'And not offered.' );
		$this->assertStringContainsString( 'Choir Concert', $html );
	}

	public function test_filters_persist_through_navigation_and_are_noindexed(): void {
		$_GET['oe_cat'] = 'music';
		$html           = $this->render();

		$this->assertMatchesRegularExpression( '/href="[^"]*oe_month=2030-06[^"]*oe_cat=music|href="[^"]*oe_cat=music[^"]*oe_month=2030-06/', $html );
		$this->assertTrue( apply_filters( 'wp_robots', array() )['noindex'] ?? false );
	}

	public function test_filtering_past_events_stays_on_past_events(): void {
		$_GET['oe_view'] = 'list';
		$_GET['oe_past'] = '1';
		$html            = $this->render();
		$this->assertStringContainsString( '<input type="hidden" name="oe_past" value="1">', $html );
		unset( $_GET['oe_past'] );
	}

	public function test_unknown_category_is_ignored(): void {
		$_GET['oe_cat'] = 'does-not-exist';
		$html           = $this->render();
		$this->assertStringContainsString( 'Author Talk', $html );
		$this->assertStringNotContainsString( 'Showing', $html );
	}

	public function test_legend_links_filter_in_place(): void {
		update_term_meta( $this->music, 'oe_color', '#7c3aed' );
		$html = $this->render( array( 'showLegend' => true ) );
		$this->assertMatchesRegularExpression( '/<ul class="oe-legend"[^>]*>.*href="[^"]*oe_cat=music[^"]*" rel="nofollow" data-wp-on--click="actions.navigate">Music</s', $html );
	}

	public function test_popup_mode_adds_dialog_and_keeps_real_links(): void {
		$page = $this->render();
		$this->assertStringNotContainsString( '<dialog', $page, 'Default: links go to the event page.' );

		$html = $this->render( array( 'clickAction' => 'popup' ) );
		$this->assertStringContainsString( '<dialog class="oe-popup" aria-labelledby="', $html );
		$this->assertMatchesRegularExpression( '/<a class="oe-month__event" href="http[^"]+" aria-haspopup="dialog" data-wp-on--click="actions.openEvent" data-wp-context="[^"]*Big Band Night/', $html, 'Links keep their href for no-JS and modifier clicks.' );
		$this->assertStringContainsString( '<span class="oe-visually-hidden">Close</span>', $html );
	}

	public function test_site_setting_sets_the_default_click_action(): void {
		$this->settings( array( 'click_action' => 'popup' ) );
		$this->assertStringContainsString( '<dialog', $this->render() );
		$this->assertStringNotContainsString( '<dialog', $this->render( array( 'clickAction' => 'page' ) ), 'Blocks can override.' );
	}
}
