<?php
/**
 * REST routes for repeating events.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Recurrence;

use DateTimeImmutable;
use InvalidArgumentException;
use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;
use OpenEvents\Occurrences\Occurrence;
use OpenEvents\Rest\OccurrencesController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * - POST /openevents/v1/recurrence/preview: dates and a description for a rule,
 *   without saving (the editor's live preview; also useful to validate a rule).
 * - POST /openevents/v1/events/{id}/occurrences/{occurrence}/{skip|detach|split}:
 *   skip one date, make one date its own event, or start a new series from it.
 *
 * Rules themselves are read and written as the `_oe_recurrence` meta on the
 * core post routes (e.g. /wp/v2/events/{id}).
 */
final class Rest {

	private const PREVIEW_LIMIT = 100;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'init', array( $this, 'validate_on_save' ), 60 );
	}

	public function routes(): void {
		register_rest_route(
			OccurrencesController::NAMESPACE,
			'/recurrence/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'start'    => array(
						'description' => __( 'First start, "Y-m-d H:i:s" in the event timezone.', 'openevents' ),
						'type'        => 'string',
						'required'    => true,
					),
					'end'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'timezone' => array(
						'type'    => 'string',
						'default' => '',
					),
					'all_day'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'rrule'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'rdates'   => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
					'exdates'  => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
					'limit'    => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => self::PREVIEW_LIMIT,
					),
				),
			)
		);

		foreach ( array( 'skip', 'detach', 'split' ) as $action ) {
			register_rest_route(
				OccurrencesController::NAMESPACE,
				'/events/(?P<id>\d+)/occurrences/(?P<occurrence>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action ),
					'permission_callback' => static fn( WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request['id'] ),
				)
			);
		}
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tz    = Event::resolve_timezone( (string) $request['timezone'] );
		$start = DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, Meta::sanitize_datetime( $request['start'] ), $tz );
		if ( ! $start ) {
			return new WP_Error( 'openevents_invalid_start', __( 'Start must be a date and time ("Y-m-d H:i:s").', 'openevents' ), array( 'status' => 400 ) );
		}
		$end = DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, Meta::sanitize_datetime( $request['end'] ), $tz );
		$end = ( $end && $end >= $start ) ? $end : $start;

		$rule = null;
		if ( '' !== trim( (string) $request['rrule'] ) ) {
			try {
				$rule = Rule::parse( (string) $request['rrule'] );
			} catch ( InvalidArgumentException $e ) {
				return new WP_Error( 'openevents_invalid_rrule', $e->getMessage(), array( 'status' => 400 ) );
			}
		}

		$recurrence = new Recurrence( $rule, (array) $request['rdates'], (array) $request['exdates'] );
		$recurrence = $recurrence->with( array() ); // Sanitises rdates/exdates.
		$limit      = (int) $request['limit'];
		$starts     = $recurrence->starts( $start, Module::horizon( $start ), $limit + 1 );

		if ( $request['all_day'] ) {
			$start = $start->setTime( 0, 0 );
			$end   = $end->setTime( 23, 59, 59 );
		}
		$length = Module::length( $start, $end );
		$dates  = array();
		foreach ( array_slice( $starts, 0, $limit ) as $date ) {
			$date    = $request['all_day'] ? $date->setTime( 0, 0 ) : $date;
			$dates[] = array(
				'start' => $date->format( Meta::DATETIME_FORMAT ),
				'end'   => Module::end_for( $date, $length )->format( Meta::DATETIME_FORMAT ),
			);
		}

		return new WP_REST_Response(
			array(
				'rrule'       => $rule ? (string) $rule : '',
				'description' => $rule ? Describer::describe( $rule, $start ) : '',
				'finite'      => ! $recurrence->is_open_ended(),
				'dates'       => $dates,
				'more'        => count( $starts ) > $limit,
			)
		);
	}

	public function skip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act(
			$request,
			static function ( Event $event, Occurrence $occurrence ) {
				$result = Series::skip( $event, $occurrence );
				return is_wp_error( $result ) ? $result : array( 'skipped' => $occurrence->start->format( 'Y-m-d' ) );
			}
		);
	}

	public function detach( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act( $request, static fn( Event $event, Occurrence $occurrence ) => self::created( Series::detach( $event, $occurrence ) ) );
	}

	public function split( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act( $request, static fn( Event $event, Occurrence $occurrence ) => self::created( Series::split( $event, $occurrence ) ) );
	}

	/**
	 * @param callable(Event, Occurrence): (array<string, mixed>|WP_Error) $callback Action.
	 */
	private function act( WP_REST_Request $request, callable $callback ): WP_REST_Response|WP_Error {
		$event      = Event::get( (int) $request['id'] );
		$occurrence = openevents()->occurrences()->get( (int) $request['occurrence'] );
		if ( ! $event || ! $occurrence || $occurrence->event_id !== $event->id() ) {
			return new WP_Error( 'openevents_not_found', __( 'That date does not belong to this event.', 'openevents' ), array( 'status' => 404 ) );
		}
		$result = $callback( $event, $occurrence );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private static function created( int|WP_Error $id ): array|WP_Error {
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array(
			'id'        => $id,
			'link'      => (string) get_permalink( $id ),
			'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * Rejects an invalid rule on the core post routes with a 400 instead of the
	 * sanitiser silently dropping it.
	 */
	public function validate_on_save(): void {
		foreach ( openevents_event_post_types() as $post_type ) {
			add_filter(
				"rest_pre_insert_{$post_type}",
				static function ( $prepared, WP_REST_Request $request ) {
					$rrule = $request['meta'][ Recurrence::META_KEY ]['rrule'] ?? '';
					if ( is_string( $rrule ) && '' !== trim( $rrule ) ) {
						try {
							Rule::parse( $rrule );
						} catch ( InvalidArgumentException $e ) {
							return new WP_Error( 'openevents_invalid_rrule', $e->getMessage(), array( 'status' => 400 ) );
						}
					}
					return $prepared;
				},
				10,
				2
			);
		}
	}
}
