<?php
/**
 * Template hierarchy for events.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\PostTypes;
use OpenEvents\Tests\TestCase;

final class TemplateTest extends TestCase {

	private function hierarchy_for( int $post_id ): array {
		$this->go_to( get_permalink( $post_id ) );
		$post = get_post( $post_id );
		return apply_filters( 'single_template_hierarchy', array( "single-{$post->post_type}-{$post->post_name}.php", "single-{$post->post_type}.php", 'single.php' ) );
	}

	public function test_events_get_category_and_base_templates(): void {
		$parent = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'slug' => 'learning' ) );
		$child  = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'slug' => 'workshops', 'parent' => $parent ) );
		$id     = $this->event( array( '_oe_start' => '2030-05-05 10:00:00' ), array( 'post_name' => 'pottery' ) );
		wp_set_object_terms( $id, array( $child ), PostTypes::CATEGORY );

		$this->assertSame(
			array(
				'single-oe_event-pottery.php',
				'single-event-category-workshops.php',
				'single-event-category-learning.php',
				'single-oe_event.php',
				'single-event.php',
				'single.php',
			),
			$this->hierarchy_for( $id )
		);
	}

	public function test_existing_post_types_keep_their_templates_unless_opted_in(): void {
		$this->settings( array( 'event_post_types' => array( 'post' ) ) );
		$id = $this->event( array( '_oe_start' => '2030-05-05 10:00:00' ), array( 'post_type' => 'post', 'post_name' => 'launch' ) );

		$this->assertNotContains( 'single-event.php', $this->hierarchy_for( $id ), 'Off by default.' );

		$this->settings( array( 'event_post_types' => array( 'post' ), 'event_template' => true ) );
		$hierarchy = $this->hierarchy_for( $id );
		$this->assertSame( array_search( 'single-post.php', $hierarchy, true ) + 1, array_search( 'single-event.php', $hierarchy, true ), 'After the post type template.' );
	}

	public function test_posts_without_dates_are_untouched(): void {
		$this->settings( array( 'event_post_types' => array( 'post' ), 'event_template' => true ) );
		$id = $this->event( array(), array( 'post_type' => 'post', 'post_name' => 'plain' ) );

		$this->assertSame( array( 'single-post-plain.php', 'single-post.php', 'single.php' ), $this->hierarchy_for( $id ) );
	}

	public function test_site_editor_offers_event_templates(): void {
		self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'name' => 'Workshops', 'slug' => 'workshops' ) );
		$types = get_default_block_template_types();

		$this->assertArrayHasKey( 'single-event', $types );
		$this->assertSame( 'Single Event: Workshops', $types['single-event-category-workshops']['title'] );
	}

	public function test_classic_themes_dont_get_plugin_block_templates(): void {
		$registry = \WP_Block_Templates_Registry::get_instance();
		$this->assertFalse( wp_is_block_theme() );
		$this->assertFalse( $registry->is_registered( 'openevents//single-event' ), 'Classic themes have no header or footer parts for them.' );

		switch_theme( 'twentytwentyfive' );
		( new \OpenEvents\Frontend\Templates() )->register_block_templates();
		$this->assertTrue( $registry->is_registered( 'openevents//single-event' ) );
		foreach ( array( 'single-event', 'single-oe_event', 'archive-oe_event', 'taxonomy-oe_event_category' ) as $slug ) {
			if ( $registry->is_registered( 'openevents//' . $slug ) ) {
				unregister_block_template( 'openevents//' . $slug );
			}
		}
		switch_theme( 'default' );
	}

	public function test_classic_archive_can_be_turned_off(): void {
		$templates = new \OpenEvents\Frontend\Templates();
		$this->go_to( get_post_type_archive_link( \OpenEvents\Content\PostTypes::EVENT ) );
		$this->assertStringEndsWith( 'templates/classic/archive.php', $templates->classic_archive( 'theme-archive.php' ) );

		add_filter( 'openevents/classic_archive', '__return_false' );
		$this->assertSame( 'theme-archive.php', $templates->classic_archive( 'theme-archive.php' ) );
		remove_filter( 'openevents/classic_archive', '__return_false' );
	}

	public function test_calendar_shortcode(): void {
		add_filter( 'openevents/calendar/cache', '__return_false' );
		$term  = self::factory()->term->create(
			array(
				'taxonomy' => \OpenEvents\Content\PostTypes::CATEGORY,
				'slug'     => 'music',
			)
		);
		$day   = current_datetime()->modify( '+3 days' )->format( 'Y-m-d' );
		$music = $this->event( array( '_oe_start' => "{$day} 19:00:00" ), array( 'post_title' => 'Choir night' ) );
		$other = $this->event( array( '_oe_start' => "{$day} 10:00:00" ), array( 'post_title' => 'Book sale' ) );
		wp_set_object_terms( $music, array( $term ), \OpenEvents\Content\PostTypes::CATEGORY );
		$this->rows( $music );
		$this->rows( $other );

		$html = do_shortcode( '[openevents_calendar view="list" categories="music,not-a-category" legend="no"]' );
		remove_filter( 'openevents/calendar/cache', '__return_false' );

		$this->assertStringContainsString( 'oe-calendar--list', $html );
		$this->assertStringContainsString( 'Choir night', $html, 'Category slugs limit the calendar.' );
		$this->assertStringNotContainsString( 'Book sale', $html );
	}
}
