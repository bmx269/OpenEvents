<?php
/**
 * REST: occurrences.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Rest;

use OpenEvents\Content\Event;
use OpenEvents\Content\PostTypes;
use OpenEvents\Occurrences\Occurrence;
use OpenEvents\Occurrences\Repository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /open-events-calendar/v1/occurrences
 *
 * Dated, published event instances for calendars, apps, and integrations. Event
 * content and meta are editable through the core routes (/wp/v2/events etc.).
 */
final class OccurrencesController {

	public const NAMESPACE = 'open-events-calendar/v1';

	public function __construct( private Repository $repository ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/occurrences',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => '__return_true', // Only published events are returned.
				'args'                => array(
					'start'      => array(
						'description' => __( 'Local date (Y-m-d). Returns occurrences overlapping [start, end]. Defaults to upcoming.', 'open-events-calendar' ),
						'type'        => 'string',
						'format'      => 'date',
					),
					'end'        => array(
						'description' => __( 'Local date (Y-m-d), inclusive.', 'open-events-calendar' ),
						'type'        => 'string',
						'format'      => 'date',
					),
					'past'       => array(
						'description' => __( 'Return past occurrences, newest first.', 'open-events-calendar' ),
						'type'        => 'boolean',
						'default'     => false,
					),
					'categories' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
					'venue'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'event'      => array(
						'description' => __( 'Limit to one event id.', 'open-events-calendar' ),
						'type'        => 'integer',
						'default'     => 0,
					),
					'search'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'post_type'  => array(
						'description' => __( 'Limit to event-enabled post types.', 'open-events-calendar' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'default'     => array(),
					),
					'per_page'   => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'       => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
	}

	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'categories' => $request['categories'],
			'venue'      => $request['venue'],
			'event_ids'  => $request['event'] ? array( $request['event'] ) : array(),
			'search'     => $request['search'],
			'post_types' => array_values( array_intersect( (array) $request['post_type'], open_events_calendar()->settings()->event_post_types() ) ),
			'per_page'     => $request['per_page'],
			'page'         => $request['page'],
			'prime_images' => true,
		);

		if ( $request['start'] ) {
			$args['local_from'] = $request['start'] . ' 00:00:00';
			$args['local_to']   = ( $request['end'] ?: $request['start'] ) . ' 23:59:59';
		} elseif ( $request['past'] ) {
			$args['ends_before'] = Repository::now();
			$args['order']       = 'DESC';
		} else {
			$args['ends_after'] = Repository::now();
		}

		$result   = $this->repository->query( $args );
		$response = new WP_REST_Response( array_map( array( $this, 'prepare_item' ), $result['items'] ) );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );
		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function prepare_item( Occurrence $occurrence ): array {
		$event = $occurrence->event();
		$venue = $event?->venue();

		$data = array(
			'id'         => $occurrence->id,
			'event_id'   => $occurrence->event_id,
			'post_type'  => $event ? $event->post->post_type : '',
			'title'      => $event ? get_the_title( $event->post ) : '',
			'link'       => $event ? get_permalink( $event->post ) : '',
			'excerpt'    => $event ? get_the_excerpt( $event->post ) : '',
			'start'      => $occurrence->start->format( DATE_ATOM ),
			'end'        => $occurrence->end->format( DATE_ATOM ),
			'start_utc'  => $occurrence->start_utc()->format( 'Y-m-d\TH:i:s\Z' ),
			'end_utc'    => $occurrence->end_utc()->format( 'Y-m-d\TH:i:s\Z' ),
			'timezone'   => $occurrence->start->getTimezone()->getName(),
			'all_day'    => $occurrence->all_day,
			'cost'       => $event ? $event->cost() : '',
			'image'      => $event ? get_the_post_thumbnail_url( $event->post, 'large' ) : false,
			'venue'      => $venue ? array(
				'id'      => $venue->ID,
				'title'   => get_the_title( $venue ),
				'address' => Event::venue_address( $venue ),
				'link'    => get_permalink( $venue ),
			) : null,
			// get_the_terms() reads the primed term cache; wp_get_post_terms() would query per item.
			'categories' => $event ? array_map( 'intval', wp_list_pluck( (array) ( get_the_terms( $event->post, PostTypes::CATEGORY ) ?: array() ), 'term_id' ) ) : array(),
		);

		/**
		 * Filters a REST occurrence payload.
		 *
		 * @param array      $data       Response data.
		 * @param Occurrence $occurrence Occurrence.
		 */
		return apply_filters( 'open_events_calendar/rest/occurrence', $data, $occurrence );
	}
}
