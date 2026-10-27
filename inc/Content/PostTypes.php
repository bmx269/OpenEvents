<?php
/**
 * Post types and taxonomies.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Content;

use OpenEvents\Installer;
use OpenEvents\Settings;

/**
 * Registers the optional Events post type, venues, organizers, and event
 * categories, and attaches event support to existing post types chosen in setup.
 */
final class PostTypes {

	public const EVENT     = Settings::EVENT_TYPE;
	public const VENUE     = 'oe_venue';
	public const ORGANIZER = 'oe_organizer';
	public const CATEGORY  = 'oe_event_category';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_types' ), 5 );
		// Late, so post types registered by themes and other plugins exist.
		add_action( 'init', array( $this, 'attach_to_existing_types' ), 50 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'flush_on_change' ), 10, 2 );
	}

	/**
	 * Admin menu that venues, organizers, and settings live under: the Events post
	 * type menu when it is registered, otherwise the plugin's own top-level page.
	 */
	public static function menu_parent( Settings $settings ): string {
		return $settings->registers_event_type() ? 'edit.php?post_type=' . self::EVENT : 'open-events-calendar';
	}

	public function register_types(): void {
		$slug   = $this->settings->events_slug();
		$parent = self::menu_parent( $this->settings );

		if ( $this->settings->registers_event_type() ) {
			$this->register_event_type( $slug );
		}

		register_post_type(
			self::VENUE,
			array(
				'labels'       => array(
					'name'          => __( 'Venues', 'open-events-calendar' ),
					'singular_name' => __( 'Venue', 'open-events-calendar' ),
					'add_new_item'  => __( 'Add New Venue', 'open-events-calendar' ),
					'edit_item'     => __( 'Edit Venue', 'open-events-calendar' ),
					'all_items'     => __( 'Venues', 'open-events-calendar' ),
					'not_found'     => __( 'No venues found.', 'open-events-calendar' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'venues',
				'show_in_menu' => $parent,
				'rewrite'      => array(
					'slug'       => 'venue',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			)
		);

		register_post_type(
			self::ORGANIZER,
			array(
				'labels'       => array(
					'name'          => __( 'Organizers', 'open-events-calendar' ),
					'singular_name' => __( 'Organizer', 'open-events-calendar' ),
					'add_new_item'  => __( 'Add New Organizer', 'open-events-calendar' ),
					'edit_item'     => __( 'Edit Organizer', 'open-events-calendar' ),
					'all_items'     => __( 'Organizers', 'open-events-calendar' ),
					'not_found'     => __( 'No organizers found.', 'open-events-calendar' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'organizers',
				'show_in_menu' => $parent,
				'rewrite'      => array(
					'slug'       => 'organizer',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			)
		);

		register_taxonomy(
			self::CATEGORY,
			$this->settings->registers_event_type() ? array( self::EVENT ) : array(),
			array(
				'labels'            => array(
					'name'          => __( 'Event Categories', 'open-events-calendar' ),
					'singular_name' => __( 'Event Category', 'open-events-calendar' ),
					'menu_name'     => __( 'Event Categories', 'open-events-calendar' ),
				),
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'rest_base'         => 'event-categories',
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $slug . '/category',
					'with_front' => false,
				),
			)
		);
	}

	private function register_event_type( string $slug ): void {
		register_post_type(
			self::EVENT,
			array(
				'labels'        => array(
					'name'                  => __( 'Events', 'open-events-calendar' ),
					'singular_name'         => __( 'Event', 'open-events-calendar' ),
					'add_new_item'          => __( 'Add New Event', 'open-events-calendar' ),
					'edit_item'             => __( 'Edit Event', 'open-events-calendar' ),
					'new_item'              => __( 'New Event', 'open-events-calendar' ),
					'view_item'             => __( 'View Event', 'open-events-calendar' ),
					'view_items'            => __( 'View Events', 'open-events-calendar' ),
					'search_items'          => __( 'Search Events', 'open-events-calendar' ),
					'not_found'             => __( 'No events found.', 'open-events-calendar' ),
					'not_found_in_trash'    => __( 'No events found in Trash.', 'open-events-calendar' ),
					'all_items'             => __( 'All Events', 'open-events-calendar' ),
					'archives'              => __( 'Event Archives', 'open-events-calendar' ),
					'item_published'        => __( 'Event published.', 'open-events-calendar' ),
					'item_updated'          => __( 'Event updated.', 'open-events-calendar' ),
					'item_scheduled'        => __( 'Event scheduled.', 'open-events-calendar' ),
					'menu_name'             => __( 'Events', 'open-events-calendar' ),
					'filter_items_list'     => __( 'Filter events list', 'open-events-calendar' ),
					'items_list_navigation' => __( 'Events list navigation', 'open-events-calendar' ),
					'items_list'            => __( 'Events list', 'open-events-calendar' ),
				),
				'public'        => true,
				'show_in_rest'  => true,
				'rest_base'     => 'events',
				'menu_icon'     => 'dashicons-calendar-alt',
				'menu_position' => 6,
				'has_archive'   => $slug,
				'rewrite'       => array(
					'slug'       => $slug,
					'with_front' => false,
				),
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
				'taxonomies'    => array( 'post_tag' ),
			)
		);
	}

	/**
	 * Gives existing event-enabled post types the category taxonomy and the
	 * custom-fields support that REST meta requires. Nothing else about those
	 * post types changes.
	 */
	public function attach_to_existing_types(): void {
		foreach ( $this->settings->event_post_types() as $post_type ) {
			if ( self::EVENT === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}
			register_taxonomy_for_object_type( self::CATEGORY, $post_type );
			add_post_type_support( $post_type, 'custom-fields' );
		}
	}

	/**
	 * Schedules a rewrite flush (never flushes inline) when settings that affect
	 * permalinks change.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public function flush_on_change( mixed $old_value, mixed $value ): void {
		foreach ( array( 'events_slug', 'register_event_type' ) as $key ) {
			if ( ( $old_value[ $key ] ?? null ) !== ( $value[ $key ] ?? null ) ) {
				update_option( Installer::FLUSH_OPTION, 1 );
				return;
			}
		}
	}
}
