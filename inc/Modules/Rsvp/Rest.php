<?php
/**
 * RSVP and attendee REST routes.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Rsvp;

use OpenEvents\Attendance\Attendee;
use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\TicketTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /openevents/v1/events/{id}/rsvp       Respond, change, or cancel (public).
 * GET  /openevents/v1/events/{id}/rsvp       Counts and open/closed state (public).
 * GET  /openevents/v1/attendees              Attendee list (needs edit rights on the events).
 * POST /openevents/v1/attendees/{id}         Check in/out or change status.
 */
final class Rest {

	private const NS = 'openevents/v1';

	public function __construct( private Service $service, private Attendees $attendees = new Attendees() ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/rsvp',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'summary' ),
					'permission_callback' => '__return_true',
					'args'                => array( 'sequence' => self::sequence_arg() ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'respond' ),
					// Public form; protected by honeypot, signed timestamp, and rate limit.
					'permission_callback' => '__return_true',
					'args'                => array(
						'name'     => array( 'type' => 'string' ),
						'email'    => array( 'type' => 'string' ),
						'status'   => array(
							'type' => 'string',
							'enum' => array( 'going', 'not_going', 'waitlist' ),
						),
						'quantity' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'sequence' => self::sequence_arg(),
						'token'    => array( 'type' => 'string' ),
						'website'  => array( 'type' => 'string' ),
						'ts'       => array( 'type' => 'string' ),
						'cancel'   => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/attendees',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_attendees' ),
				'permission_callback' => array( $this, 'can_list' ),
				'args'                => array(
					'event'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'status'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'search'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'checked_in' => array( 'type' => 'boolean' ),
					'sequence'   => array(
						'description' => __( 'One date of a repeating event (an occurrence sequence); needs event.', 'openevents' ),
						'type'        => 'integer',
						'minimum'     => 0,
					),
					'page'       => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'   => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'orderby'    => array(
						'type'    => 'string',
						'default' => 'created_utc',
					),
					'order'      => array(
						'type'    => 'string',
						'default' => 'desc',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/attendees/dates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'booked_dates' ),
				'permission_callback' => array( $this, 'can_list' ),
				'args'                => array(
					'event' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/attendees/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_attendee' ),
				'permission_callback' => array( $this, 'can_edit_attendee' ),
				'args'                => array(
					'checked_in' => array( 'type' => 'boolean' ),
					'status'     => array(
						'type' => 'string',
						'enum' => array( Attendee::GOING, Attendee::CANCELLED ),
					),
				),
			)
		);
	}

	/**
	 * Which date of a repeating event (an occurrence's sequence; 0, the
	 * default, is the event's own date).
	 *
	 * @return array<string, mixed>
	 */
	private static function sequence_arg(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 0,
			'default' => 0,
		);
	}

	public function summary( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$event_id = (int) $request['id'];
		if ( 'publish' !== get_post_status( $event_id ) ) {
			return new WP_Error( 'openevents_rsvp_disabled', __( 'This event is not taking RSVPs.', 'openevents' ), array( 'status' => 404 ) );
		}
		$type = $this->service->ticket_type( $event_id, (int) $request['sequence'] );
		if ( ! $type ) {
			return new WP_Error( 'openevents_rsvp_disabled', __( 'This event is not taking RSVPs.', 'openevents' ), array( 'status' => 404 ) );
		}
		$counts = $this->attendees->counts( $event_id, $type->id );
		return new WP_REST_Response(
			array(
				'open'      => null === $this->service->closed_reason( $event_id, $type ),
				'going'     => $type->setting( 'show_count', true ) ? $counts['going'] : null,
				'remaining' => $type->remaining(),
				'capacity'  => $type->capacity,
			)
		);
	}

	public function respond( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request['id'];
		if ( 'publish' !== get_post_status( $event_id ) ) {
			return new WP_REST_Response( Result::error( 'disabled', __( 'This event is not taking RSVPs.', 'openevents' ) )->to_array(), 404 );
		}
		$input = array(
			'name'     => (string) $request['name'],
			'email'    => (string) $request['email'],
			'status'   => (string) ( $request['status'] ?: 'going' ),
			'quantity' => (int) ( $request['quantity'] ?: 1 ),
			'sequence' => (int) $request['sequence'],
			'attendees' => (array) $request['attendees'],
			'token'    => (string) $request['token'],
			'website'  => (string) $request['website'],
			'ts'       => (string) $request['ts'],
			'ip'       => Module::client_ip(),
		);
		$result = $request['cancel'] ? $this->service->cancel( $input['token'] ) : $this->service->respond( $event_id, $input );

		// Counts for the date the response is for.
		$type = $result->group
			? $this->service->response_type( $event_id, $this->attendees->group( $result->group ) )
			: $this->service->ticket_type( $event_id, $input['sequence'] );
		$data = array_merge(
			$result->to_array(),
			array(
				'going'     => $type && $type->setting( 'show_count', true ) ? $this->attendees->counts( $event_id, $type->id )['going'] : null,
				'remaining' => $type?->remaining(),
			)
		);
		if ( null !== $data['going'] ) {
			$data['count_text'] = Form::count_text( (int) $data['going'], $data['remaining'] );
		}

		$codes = array(
			'full'         => 409,
			'closed'       => 403,
			'disabled'     => 404,
			'rate_limited' => 429,
			'invalid'      => 422,
		);
		return new WP_REST_Response( $data, $result->ok ? 200 : ( $codes[ $result->code ] ?? 400 ) );
	}

	/**
	 * Attendee lists need edit rights on the event (or, without an event filter,
	 * on others' events).
	 */
	public function can_list( WP_REST_Request $request ): bool {
		$event_id = (int) $request['event'];
		return $event_id ? current_user_can( 'edit_post', $event_id ) : current_user_can( self::capability() );
	}

	/**
	 * The event's dates that have attendees: value (sequence) and label.
	 */
	public function booked_dates( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( ( new TicketTypes() )->booked_dates( (int) $request['event'] ) );
	}

	public function can_edit_attendee( WP_REST_Request $request ): bool {
		$attendee = $this->attendees->get( (int) $request['id'] );
		return $attendee && current_user_can( 'edit_post', $attendee->event_id );
	}

	public function list_attendees( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->attendees->query(
			array(
				'event_id'   => (int) $request['event'],
				'status'     => sanitize_key( (string) $request['status'] ),
				'search'     => (string) $request['search'],
				'checked_in' => $request->has_param( 'checked_in' ) ? (bool) $request['checked_in'] : null,
				'sequence'   => $request->has_param( 'sequence' ) ? (int) $request['sequence'] : null,
				'page'       => (int) $request['page'],
				'per_page'   => (int) $request['per_page'],
				'orderby'    => sanitize_key( (string) $request['orderby'] ),
				'order'      => (string) $request['order'],
			)
		);

		// One query for all event titles on the page.
		$event_ids = array_values( array_unique( array_map( static fn( Attendee $a ) => $a->event_id, $result['items'] ) ) );
		if ( $event_ids ) {
			_prime_post_caches( $event_ids, false, false );
		}

		$types    = array();
		$dates    = array();
		$repo     = new TicketTypes();
		$items    = array_map(
			static function ( Attendee $a ) use ( &$types, &$dates, $repo ) {
				return array_merge(
					$a->to_array(),
					array(
						'event_title' => html_entity_decode( get_the_title( $a->event_id ), ENT_QUOTES ),
						'date'        => $repo->date_label( $a->ticket_type_id, $dates ),
						'code'        => $a->security_code,
						// For organisers to send again when someone can't find their email.
						'pass_url'    => \OpenEvents\Attendance\Pass::url( $a ),
						'answers'     => \OpenEvents\Attendance\Fields::answers( $a, $types ),
					)
				);
			},
			$result['items']
		);
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ) ) );
		return $response;
	}

	public function update_attendee( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = (int) $request['id'];
		$attendee = $this->attendees->get( $id );
		$types    = new TicketTypes();

		if ( $request->has_param( 'checked_in' ) ) {
			$this->attendees->set_checked_in( $id, (bool) $request['checked_in'] );
		}
		if ( $request->has_param( 'status' ) && $request['status'] !== $attendee->status ) {
			if ( Attendee::GOING === $request['status'] ) {
				if ( ! $types->reserve( $attendee->ticket_type_id, 1 ) ) {
					return new WP_Error( 'openevents_full', __( 'There are no spots left.', 'openevents' ), array( 'status' => 409 ) );
				}
			} elseif ( Attendee::GOING === $attendee->status ) {
				$types->release( $attendee->ticket_type_id, 1 );
			}
			$this->attendees->set_status( $id, (string) $request['status'] );
		}

		return new WP_REST_Response( $this->attendees->get( $id )->to_array() );
	}

	public static function capability(): string {
		/**
		 * Capability needed to view attendees across all events.
		 *
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'openevents/attendees/capability', 'edit_others_posts' );
	}
}
