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
		// Late, as TEC does: page-builder archive layouts built around another
		// plugin's loop would otherwise replace the calendar with an empty loop.
		add_filter( 'template_include', array( $this, 'classic_archive' ), 50 );
		add_shortcode( 'openevents_calendar', array( self::class, 'calendar_shortcode' ) );
		add_filter( 'the_content', array( $this, 'prepend_details' ), 5 );
		add_filter( 'posts_pre_query', array( $this, 'skip_archive_query' ), 10, 2 );
		add_filter( 'single_template_hierarchy', array( $this, 'single_hierarchy' ) );
		add_filter( 'default_template_types', array( $this, 'template_types' ) );
	}

	/**
	 * Adds event templates to the single-post hierarchy, so themes and the Site
	 * Editor can target events by category or post type:
	 *
	 *   single-{type}-{slug}
	 *   single-event-category-{category}   (each assigned category, then its parents)
	 *   single-{type}                       (e.g. single-oe_event, single-post)
	 *   single-event                        (base template for every event type*)
	 *   single
	 *
	 * *For existing post types only when "Use the event template" is on, so
	 * enabling events on Posts never changes how dated posts look by default.
	 *
	 * @param string[] $templates Candidate templates, most specific first.
	 * @return string[]
	 */
	public function single_hierarchy( array $templates ): array {
		$post  = get_queried_object();
		$event = $post instanceof \WP_Post ? openevents_get_event( $post ) : null;
		if ( ! $event ) {
			return $templates;
		}
		$type = $post->post_type;

		$categories = array();
		$terms      = get_the_terms( $post, PostTypes::CATEGORY );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$categories[] = 'single-event-category-' . $term->slug . '.php';
			foreach ( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) as $ancestor ) {
				$parent = get_term( $ancestor, PostTypes::CATEGORY );
				if ( $parent instanceof \WP_Term ) {
					$categories[] = 'single-event-category-' . $parent->slug . '.php';
				}
			}
		}

		$use_base = PostTypes::EVENT === $type || openevents()->settings()->get( 'event_template' );
		$out      = array();
		foreach ( $templates as $template ) {
			if ( "single-{$type}.php" === $template ) {
				array_push( $out, ...array_unique( $categories ) );
				$out[] = $template;
				if ( $use_base ) {
					$out[] = 'single-event.php';
				}
				continue;
			}
			$out[] = $template;
		}

		/**
		 * Filters the template hierarchy for a single event.
		 *
		 * @param string[] $out       Templates, most specific first.
		 * @param \WP_Post $post      Event post.
		 * @param string[] $templates Core's original hierarchy.
		 */
		return apply_filters( 'openevents/single_template_hierarchy', array_values( array_unique( $out ) ), $post, $templates );
	}

	/**
	 * Offers the event templates under "Add template" in the Site Editor.
	 *
	 * @param array<string, array{title: string, description: string}> $types Template types.
	 * @return array<string, array{title: string, description: string}>
	 */
	public function template_types( array $types ): array {
		$types['single-event'] = array(
			'title'       => __( 'Single Event', 'openevents' ),
			'description' => __( 'Displays a single event of any event post type.', 'openevents' ),
		);
		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$types[ 'single-event-category-' . $term->slug ] = array(
				/* translators: %s: event category name */
				'title'       => sprintf( __( 'Single Event: %s', 'openevents' ), $term->name ),
				/* translators: %s: event category name */
				'description' => sprintf( __( 'Displays events in the %s category (and its subcategories).', 'openevents' ), $term->name ),
			);
		}
		return $types;
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
			&& apply_filters( 'openevents/skip_archive_query', true )
		) {
			$query->found_posts   = 0;
			$query->max_num_pages = 0;
			return array();
		}
		return $posts;
	}

	public function register_block_templates(): void {
		// Classic themes can report block-template support, but they have no
		// header or footer template parts for these templates to use: they keep
		// their own single.php (with the details prepended) and the classic archive.
		if ( ! wp_is_block_theme() ) {
			return;
		}
		// The base template serves every event post type; the rest belong to oe_event.
		$templates = array( 'single-event' => __( 'Single Event (all event types)', 'openevents' ) );
		if ( openevents()->settings()->registers_event_type() ) {
			$templates += array(
				'single-' . PostTypes::EVENT      => __( 'Single Event', 'openevents' ),
				'archive-' . PostTypes::EVENT     => __( 'Events Calendar', 'openevents' ),
				'taxonomy-' . PostTypes::CATEGORY => __( 'Event Category', 'openevents' ),
			);
		}
		foreach ( $templates as $slug => $title ) {
			$file = PLUGIN_DIR . '/templates/' . $slug . '.html';
			if ( is_readable( $file ) ) {
				register_block_template(
					'openevents//' . $slug,
					array(
						'title'   => $title,
						'content' => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					)
				);
			}
		}
	}

	public function classic_archive( string $template ): string {
		/**
		 * Filters whether classic themes get the plugin's events archive. Return
		 * false to design the archive in a page builder (place the calendar with
		 * the [openevents_calendar] shortcode).
		 *
		 * @param bool $use Whether to use the plugin's archive template.
		 */
		if ( wp_is_block_theme() || ! apply_filters( 'openevents/classic_archive', true ) ) {
			return $template;
		}
		if ( is_post_type_archive( PostTypes::EVENT ) || is_tax( PostTypes::CATEGORY ) ) {
			$theme = locate_template( array( 'openevents/archive.php' ) );
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
			|| ! openevents()->settings()->get( 'append_details' )
			|| EventDetails::was_rendered( $post_id )
			|| has_block( 'openevents/event-details', $content )
		) {
			return $content;
		}
		$details = EventDetails::render( $post_id );
		if ( $details ) {
			// The block isn't on the page, so its stylesheet hasn't been enqueued.
			wp_enqueue_style( generate_block_asset_handle( 'openevents/event-details', 'style' ) );
		}
		return $details ? '<div class="wp-block-openevents-event-details oe-details">' . $details . '</div>' . $content : $content;
	}

	/**
	 * [openevents_calendar] places the calendar anywhere shortcodes run (page
	 * builders, widgets). Attributes: views ("month,list"), view (default
	 * view), categories (ids or slugs), legend, filters (yes/no), per_page.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function calendar_shortcode( array|string $atts ): string {
		$atts       = shortcode_atts(
			array(
				'views'      => 'month,list',
				'view'       => '',
				'categories' => '',
				'legend'     => 'yes',
				'filters'    => 'yes',
				'per_page'   => 0,
			),
			is_array( $atts ) ? $atts : array(),
			'openevents_calendar'
		);
		$categories = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['categories'] ) ) ) as $item ) {
			$term = is_numeric( $item ) ? get_term( (int) $item, PostTypes::CATEGORY ) : get_term_by( 'slug', $item, PostTypes::CATEGORY );
			if ( $term instanceof \WP_Term ) {
				$categories[] = $term->term_id;
			}
		}
		if ( ! $categories && is_tax( PostTypes::CATEGORY ) ) {
			$categories[] = get_queried_object_id();
		}
		$attributes = array(
			'views'       => array_values( array_intersect( array_map( 'trim', explode( ',', (string) $atts['views'] ) ), array( 'month', 'list' ) ) ) ?: array( 'month', 'list' ),
			'defaultView' => in_array( $atts['view'], array( 'month', 'list' ), true ) ? $atts['view'] : '',
			'categories'  => $categories,
			'showLegend'  => 'no' !== $atts['legend'],
			'showFilters' => 'no' !== $atts['filters'],
			'perPage'     => absint( $atts['per_page'] ),
		);
		return do_blocks( '<!-- wp:openevents/calendar ' . wp_json_encode( $attributes ) . ' /-->' );
	}
}
