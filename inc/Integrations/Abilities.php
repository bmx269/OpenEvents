<?php
/**
 * Abilities API.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Integrations;

use OpenEvents\Content\Meta;
use OpenEvents\Occurrences\Repository;
use OpenEvents\Rest\OccurrencesController;
use WP_Error;

/**
 * Registers event abilities so AI assistants, automation tools, and other
 * plugins get a typed, permission-checked interface (also exposed at
 * /wp-json/wp-abilities/v1).
 */
final class Abilities {

	private const CATEGORY = 'openevents';

	public function __construct( private OccurrencesController $presenter ) {}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Events', 'openevents' ),
				'description' => __( 'Find, read, and create calendar events.', 'openevents' ),
			)
		);
	}

	public function register_abilities(): void {
		$occurrence_schema = array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'event_id'  => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'link'      => array( 'type' => 'string' ),
				'start'     => array( 'type' => 'string', 'format' => 'date-time' ),
				'end'       => array( 'type' => 'string', 'format' => 'date-time' ),
				'timezone'  => array( 'type' => 'string' ),
				'all_day'   => array( 'type' => 'boolean' ),
				'cost'      => array( 'type' => 'string' ),
			),
		);

		wp_register_ability(
			'openevents/list-events',
			array(
				'label'               => __( 'List events', 'openevents' ),
				'description'         => __( 'Lists published events. Without dates, returns upcoming events soonest first.', 'openevents' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'start'      => array(
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'First day (Y-m-d) of a date range.', 'openevents' ),
						),
						'end'        => array(
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'Last day (Y-m-d) of a date range, inclusive.', 'openevents' ),
						),
						'past'       => array(
							'type'        => 'boolean',
							'description' => __( 'Return past events, newest first.', 'openevents' ),
						),
						'search'     => array( 'type' => 'string' ),
						'categories' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => $occurrence_schema,
				),
				'execute_callback'    => array( $this, 'list_events' ),
				'permission_callback' => '__return_true', // Published data only.
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			'openevents/create-event',
			array(
				'label'               => __( 'Create event', 'openevents' ),
				'description'         => __( 'Creates an event. Saved as a draft unless status is "publish" and the user can publish.', 'openevents' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'title', 'start' ),
					'properties'           => array(
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'start'     => array(
							'type'        => 'string',
							'description' => __( 'Local start, "Y-m-d H:i" in the event timezone.', 'openevents' ),
						),
						'end'       => array( 'type' => 'string' ),
						'all_day'   => array( 'type' => 'boolean' ),
						'timezone'  => array(
							'type'        => 'string',
							'description' => __( 'IANA timezone. Defaults to the site timezone.', 'openevents' ),
						),
						'venue_id'  => array( 'type' => 'integer' ),
						'post_type' => array(
							'type' => 'string',
							'enum' => openevents_event_post_types(),
						),
						'status'    => array(
							'type'    => 'string',
							'enum'    => array( 'draft', 'publish' ),
							'default' => 'draft',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'edit_url' => array( 'type' => 'string' ),
						'link'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'create_event' ),
				'permission_callback' => static function ( $input ): bool {
					$type   = get_post_type_object( (string) ( $input['post_type'] ?? openevents_event_post_types()[0] ?? '' ) );
					return $type && current_user_can( $type->cap->edit_posts );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_events( $input = array() ): array {
		$input = (array) $input;
		$args  = array(
			'search'     => (string) ( $input['search'] ?? '' ),
			'categories' => (array) ( $input['categories'] ?? array() ),
			'per_page'   => (int) ( $input['limit'] ?? 20 ),
		);
		if ( ! empty( $input['start'] ) ) {
			$args['local_from'] = $input['start'] . ' 00:00:00';
			$args['local_to']   = ( $input['end'] ?? $input['start'] ) . ' 23:59:59';
		} elseif ( ! empty( $input['past'] ) ) {
			$args['ends_before'] = Repository::now();
			$args['order']       = 'DESC';
		} else {
			$args['ends_after'] = Repository::now();
		}
		return array_map( array( $this->presenter, 'prepare_item' ), openevents_get_occurrences( $args )['items'] );
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_event( $input ) {
		$input     = (array) $input;
		$post_type = (string) ( $input['post_type'] ?? openevents_event_post_types()[0] ?? '' );
		$type      = get_post_type_object( $post_type );
		if ( ! $type ) {
			return new WP_Error( 'openevents_no_event_type', __( 'No event post type is enabled.', 'openevents' ) );
		}

		$start = Meta::sanitize_datetime( $input['start'] ?? '' );
		if ( '' === $start ) {
			return new WP_Error( 'openevents_invalid_start', __( 'Start must be a date like "2026-11-03 19:00".', 'openevents' ) );
		}
		$end = Meta::sanitize_datetime( $input['end'] ?? '' ) ?: $start;

		$status = ( 'publish' === ( $input['status'] ?? '' ) && current_user_can( $type->cap->publish_posts ) ) ? 'publish' : 'draft';

		$id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => sanitize_text_field( (string) $input['title'] ),
				'post_content' => wp_kses_post( (string) ( $input['content'] ?? '' ) ),
				'post_status'  => $status,
				'meta_input'   => array(
					'_oe_start'    => $start,
					'_oe_end'      => $end,
					'_oe_all_day'  => ! empty( $input['all_day'] ),
					'_oe_timezone' => Meta::sanitize_timezone( $input['timezone'] ?? '' ),
					'_oe_venue_id' => absint( $input['venue_id'] ?? 0 ),
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array(
			'id'       => $id,
			'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			'link'     => (string) get_permalink( $id ),
		);
	}
}
