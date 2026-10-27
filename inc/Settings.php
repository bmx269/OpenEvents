<?php
/**
 * Typed access to plugin options.
 *
 * @package OpenEvents
 */

namespace OpenEvents;

/**
 * Reads and sanitises the openevents_settings option.
 */
final class Settings {

	public const OPTION         = 'openevents_settings';
	public const MODULES_OPTION = 'openevents_modules';
	public const SETUP_OPTION   = 'openevents_setup_complete';

	/** The dedicated post type the plugin can register. */
	public const EVENT_TYPE = 'oe_event';

	/**
	 * Defaults for every setting. Keys not listed here are dropped on save.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'register_event_type'      => true,
			'event_post_types'         => array(), // Existing post types that also carry event data.
			'events_slug'              => 'events',
			'append_details'           => true,
			'event_template'           => false, // Use the Single Event template for events in existing post types.
			'calendar_page'            => 0,
			'per_page'                 => 10,
			'default_view'             => 'month',
			'click_action'             => 'page', // "page" or "popup".
			'date_format'              => '',
			'time_format'              => '',
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public function get( string $key ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	public function events_slug(): string {
		return (string) $this->get( 'events_slug' ) ?: 'events';
	}

	public function registers_event_type(): bool {
		return (bool) $this->get( 'register_event_type' );
	}

	/**
	 * Every post type that can hold event data: the dedicated type (when enabled)
	 * plus any existing types chosen in setup. A post in one of these types is an
	 * event only once it has a start date, so enabling a type never changes posts
	 * that already exist.
	 *
	 * @return string[]
	 */
	public function event_post_types(): array {
		$types = (array) $this->get( 'event_post_types' );
		if ( $this->registers_event_type() ) {
			array_unshift( $types, self::EVENT_TYPE );
		}

		/**
		 * Filters the post types that can hold event data.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = apply_filters( 'openevents/event_post_types', $types );

		return array_values( array_unique( array_filter( $types, 'is_string' ) ) );
	}

	/**
	 * Existing post types that may be event-enabled: public and REST-enabled (the
	 * editor sidebar saves through REST), excluding media and plugin types.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function eligible_post_types(): array {
		$types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'objects'
		);
		return array_filter(
			$types,
			static fn( \WP_Post_Type $type ): bool => 'attachment' !== $type->name && ! str_starts_with( $type->name, 'oe_' )
		);
	}

	public function is_setup_complete(): bool {
		return (bool) get_option( self::SETUP_OPTION );
	}

	public function date_format(): string {
		return (string) $this->get( 'date_format' ) ?: (string) get_option( 'date_format' );
	}

	public function time_format(): string {
		return (string) $this->get( 'time_format' ) ?: (string) get_option( 'time_format' );
	}

	/**
	 * @param mixed $input Raw option value from the settings form.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$slug = sanitize_title( (string) ( $input['events_slug'] ?? '' ) );
		$view = (string) ( $input['default_view'] ?? '' );

		// Validated against registered types at save time; unknown names are kept out.
		$types = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) ( $input['event_post_types'] ?? array() ) ),
				array_keys( self::eligible_post_types() )
			)
		);

		return array(
			'register_event_type'      => ! empty( $input['register_event_type'] ),
			'event_post_types'         => $types,
			'events_slug'              => '' !== $slug ? $slug : $defaults['events_slug'],
			'append_details'           => ! empty( $input['append_details'] ),
			'event_template'           => ! empty( $input['event_template'] ),
			'calendar_page'            => absint( $input['calendar_page'] ?? 0 ),
			'per_page'                 => max( 1, min( 100, absint( $input['per_page'] ?? $defaults['per_page'] ) ) ),
			'default_view'             => in_array( $view, array( 'month', 'list' ), true ) ? $view : $defaults['default_view'],
			'click_action'             => 'popup' === ( $input['click_action'] ?? '' ) ? 'popup' : 'page',
			'date_format'              => sanitize_text_field( (string) ( $input['date_format'] ?? '' ) ),
			'time_format'              => sanitize_text_field( (string) ( $input['time_format'] ?? '' ) ),
			'remove_data_on_uninstall' => ! empty( $input['remove_data_on_uninstall'] ),
		);
	}

	/**
	 * @return string[]
	 */
	public function enabled_modules(): array {
		$stored = get_option( self::MODULES_OPTION, array( 'ical' ) );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}
}
