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
}
