<?php
/**
 * Registered post meta.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Content;

/**
 * Registers event, venue, and organizer meta so it is typed, sanitised, and
 * editable over REST (which the editor sidebar uses).
 */
final class Meta {

	public function __construct( private \OpenEvents\Settings $settings ) {}

	public const DATETIME_FORMAT = 'Y-m-d H:i:s';

	/** Keys whose change requires regenerating occurrences. */
	public const SCHEDULE_KEYS = array( '_oe_start', '_oe_end', '_oe_timezone', '_oe_all_day', '_oe_hide_from_listings', '_oe_recurrence' );

	public function register(): void {
		// After every post type (ours and other plugins') is registered, so revision support can be checked.
		add_action( 'init', array( $this, 'register_meta' ), 50 );
	}

	/**
	 * @return array<string, array<string, mixed>> Meta key => register_post_meta args.
	 */
	public static function event_fields(): array {
		return array(
			'_oe_start'              => array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_datetime' ),
			),
			'_oe_end'                => array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_datetime' ),
			),
			'_oe_timezone'           => array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_timezone' ),
			),
			'_oe_all_day'            => array( 'type' => 'boolean' ),
			'_oe_venue_id'           => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'_oe_organizer_ids'      => array(
				'type'         => 'array',
				'default'      => array(),
				'show_in_rest' => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			),
			'_oe_cost'               => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_oe_currency_symbol'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_oe_url'                => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'_oe_featured'           => array( 'type' => 'boolean' ),
			'_oe_hide_from_listings' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function venue_fields(): array {
		$text = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);
		return array(
			'_oe_address'     => $text,
			'_oe_city'        => $text,
			'_oe_region'      => $text,
			'_oe_postal_code' => $text,
			'_oe_country'     => $text,
			'_oe_phone'       => $text,
			'_oe_website'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			// Strings so "not set" can be stored as empty rather than 0,0.
			'_oe_lat'         => array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_coordinate' ),
			),
			'_oe_lng'         => array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_coordinate' ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function organizer_fields(): array {
		return array(
			'_oe_phone'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_oe_email'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
			'_oe_website' => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
		);
	}

	public function register_meta(): void {
		$groups = array(
			PostTypes::VENUE     => self::venue_fields(),
			PostTypes::ORGANIZER => self::organizer_fields(),
		);
		foreach ( $this->settings->event_post_types() as $post_type ) {
			$groups[ $post_type ] = self::event_fields();
		}

		foreach ( $groups as $post_type => $fields ) {
			/**
			 * Filters the meta fields registered for a plugin post type. Add-ons can
			 * add fields here to get REST exposure and sanitisation for free.
			 *
			 * @param array  $fields    Meta key => register_post_meta args.
			 * @param string $post_type Post type.
			 */
			$fields = apply_filters( 'openevents/meta_fields', $fields, $post_type );

			// Track event dates and details in revisions where the post type keeps them.
			$revisions = in_array( $post_type, $this->settings->event_post_types(), true ) && post_type_supports( $post_type, 'revisions' );

			foreach ( $fields as $key => $args ) {
				if ( $revisions ) {
					$args += array( 'revisions_enabled' => true );
				}
				register_post_meta(
					$post_type,
					$key,
					array_merge(
						array(
							'single'        => true,
							'show_in_rest'  => true,
							// Underscore keys are protected; grant access to anyone who can edit the post.
							'auth_callback' => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
						),
						$args
					)
				);
			}
		}
	}

	/**
	 * Accepts "Y-m-d H:i:s", "Y-m-d\TH:i:s", or "Y-m-d H:i" and stores "Y-m-d H:i:s".
	 */
	public static function sanitize_datetime( mixed $value ): string {
		$value = trim( str_replace( 'T', ' ', (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		foreach ( array( self::DATETIME_FORMAT, 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
			if ( $date && $date->format( $format ) === $value ) {
				return $date->format( self::DATETIME_FORMAT );
			}
		}
		return '';
	}

	public static function sanitize_coordinate( mixed $value ): string {
		return is_numeric( $value ) ? (string) (float) $value : '';
	}

	public static function sanitize_timezone( mixed $value ): string {
		$value = (string) $value;
		return in_array( $value, timezone_identifiers_list(), true ) ? $value : '';
	}
}
