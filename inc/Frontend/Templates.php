<?php
/**
 * Templates for block and classic themes.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Frontend;

use OpenEvents\Content\PostTypes;

use const OpenEvents\PLUGIN_DIR;

/**
 * Block themes: plugin-registered templates for the Events post type, editable
 * in the Site Editor. Classic themes: an archive template that renders the
 * calendar block, and event details prepended to the content.
 *
 * Existing post types that are event-enabled keep their own templates; they
 * only gain the details panel (unless turned off or already placed as a block).
 */
final class Templates {

	public function register(): void {
		add_action( 'init', array( $this, 'register_block_templates' ), 20 );
		add_filter( 'template_include', array( $this, 'classic_archive' ) );
		add_filter( 'the_content', array( $this, 'prepend_details' ), 5 );
		add_filter( 'posts_pre_query', array( $this, 'skip_archive_query' ), 10, 2 );
	}

	/**
	 * The events archive and category pages render through the calendar block,
	 * which runs its own indexed query. Skip WordPress's main posts query there
	 * (a SQL_CALC_FOUND_ROWS scan of every event) since nothing uses its results.
	 *
	 * @param \WP_Post[]|null $posts Short-circuit value.
	 * @return \WP_Post[]|null
	 */
	public function skip_archive_query( ?array $posts, \WP_Query $query ): ?array {
		if (
			null === $posts
			&& ! is_admin()
			&& $query->is_main_query()
			&& ! $query->is_feed()
			&& ( $query->is_post_type_archive( PostTypes::EVENT ) || $query->is_tax( PostTypes::CATEGORY ) )
			/**
			 * Filters whether the main archive query is skipped. Return false if your
			 * theme's events archive runs a classic posts loop.
			 *
			 * @param bool $skip Whether to skip.
			 */
			&& apply_filters( 'open_events_calendar/skip_archive_query', true )
		) {
			$query->found_posts   = 0;
			$query->max_num_pages = 0;
			return array();
		}
		return $posts;
	}

	public function register_block_templates(): void {
		if ( ! open_events_calendar()->settings()->registers_event_type() ) {
			return;
		}
		$templates = array(
			'single-' . PostTypes::EVENT              => __( 'Single Event', 'open-events-calendar' ),
			'archive-' . PostTypes::EVENT             => __( 'Events Calendar', 'open-events-calendar' ),
			'taxonomy-' . PostTypes::CATEGORY         => __( 'Event Category', 'open-events-calendar' ),
		);
		foreach ( $templates as $slug => $title ) {
			$file = PLUGIN_DIR . '/templates/' . $slug . '.html';
			if ( is_readable( $file ) ) {
				register_block_template(
					'open-events-calendar//' . $slug,
					array(
						'title'   => $title,
						'content' => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					)
				);
			}
		}
	}

	public function classic_archive( string $template ): string {
		if ( wp_is_block_theme() ) {
			return $template;
		}
		if ( is_post_type_archive( PostTypes::EVENT ) || is_tax( PostTypes::CATEGORY ) ) {
			$theme = locate_template( array( 'open-events-calendar/archive.php' ) );
			return $theme ?: PLUGIN_DIR . '/templates/classic/archive.php';
		}
		return $template;
	}

	public function prepend_details( string $content ): string {
		$post_id = get_the_ID();
		if (
			! $post_id
			|| ! is_singular()
			|| ! in_the_loop()
			|| ! is_main_query()
			|| get_queried_object_id() !== $post_id
			|| ! open_events_calendar()->settings()->get( 'append_details' )
			|| EventDetails::was_rendered( $post_id )
			|| has_block( 'open-events-calendar/event-details', $content )
		) {
			return $content;
		}
		$details = EventDetails::render( $post_id );
		if ( $details ) {
			// The block isn't on the page, so its stylesheet hasn't been enqueued.
			wp_enqueue_style( generate_block_asset_handle( 'open-events-calendar/event-details', 'style' ) );
		}
		return $details ? '<div class="wp-block-open-events-calendar-event-details oe-details">' . $details . '</div>' . $content : $content;
	}
}
