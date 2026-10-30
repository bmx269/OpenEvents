<?php
/**
 * Door check-in: look people up by code and check them in.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

use OpenEvents\Content\PostTypes;
use OpenEvents\Frontend\Formatter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Events → Check-in, and its REST routes:
 *
 * GET  /openevents/v1/check-in?code=   Who a code belongs to.
 * POST /openevents/v1/check-in         Check in (code), or undo (code, undo).
 *
 * Staff only see attendees of events they can edit; any other code reads as
 * not found, so codes can't be probed. Opening a scanned QR link only looks
 * the person up; checking in is a deliberate tap (or a scan made on the
 * screen itself), so a link can't check anyone in.
 */
final class CheckIn {

	public const SLUG = 'openevents-check-in';

	public static function register(): void {
		$self = new self();
		add_action( 'rest_api_init', array( $self, 'routes' ) );
		add_action( 'admin_menu', array( $self, 'menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $self, 'enqueue' ) );
	}

	public function __construct( private Attendees $attendees = new Attendees() ) {}

	public function routes(): void {
		$code = array(
			'type'      => 'string',
			'required'  => true,
			'minLength' => 1,
			'maxLength' => 2000, // A scanned check-in link, or the code alone.
		);
		register_rest_route(
			'openevents/v1',
			'/check-in',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'look_up' ),
					'permission_callback' => array( self::class, 'can_check_in' ),
					'args'                => array( 'code' => $code ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'check_in' ),
					'permission_callback' => array( self::class, 'can_check_in' ),
					'args'                => array(
						'code' => $code,
						'undo' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Anyone who can edit events may open the screen; each code is then
	 * checked against that event (see attendee()).
	 */
	public static function can_check_in(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function look_up( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attendee = $this->attendee( (string) $request['code'] );
		return $attendee ? $this->response( $attendee, 'found' ) : self::not_found();
	}

	public function check_in( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attendee = $this->attendee( (string) $request['code'] );
		if ( null === $attendee ) {
			return self::not_found();
		}

		if ( $request['undo'] ) {
			$this->attendees->set_checked_in( $attendee->id, false );
			return $this->response( $this->attendees->get( $attendee->id ), 'undone', __( 'Check-in undone.', 'openevents' ) );
		}
		if ( Attendee::GOING !== $attendee->status ) {
			return new WP_Error(
				'openevents_not_going',
				Attendee::CANCELLED === $attendee->status || 'refunded' === $attendee->status
					? __( 'This booking was cancelled. Don’t check them in.', 'openevents' )
					: __( 'This booking isn’t confirmed (payment may not have gone through). Don’t check them in.', 'openevents' ),
				array(
					'status' => 409,
					'data'   => $this->data( $attendee ),
				)
			);
		}
		if ( ! $this->attendees->check_in( $attendee->id ) ) {
			$attendee = $this->attendees->get( $attendee->id );
			return $this->response(
				$attendee,
				'already',
				/* translators: %s: time */
				sprintf( __( 'Already checked in at %s.', 'openevents' ), self::time( (string) $attendee->checked_in_utc ) )
			);
		}

		$attendee = $this->attendees->get( $attendee->id );

		/**
		 * Fires when someone is checked in at the door.
		 *
		 * @param Attendee $attendee Attendee.
		 */
		do_action( 'openevents/checked_in', $attendee );

		return $this->response( $attendee, 'checked_in', __( 'Checked in.', 'openevents' ) );
	}

	/**
	 * The attendee for a code (or a scanned check-in link), if the user may
	 * check in for their event.
	 */
	private function attendee( string $input ): ?Attendee {
		$code = $input;
		if ( str_contains( $input, 'code=' ) ) {
			parse_str( (string) wp_parse_url( $input, PHP_URL_QUERY ), $query );
			$code = is_string( $query['code'] ?? null ) ? $query['code'] : '';
		}
		$attendee = $this->attendees->find_by_code( $code );
		return $attendee && current_user_can( 'edit_post', $attendee->event_id ) ? $attendee : null;
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'openevents_not_found', __( 'No booking with that code. Check the code, or look the person up in Attendees.', 'openevents' ), array( 'status' => 404 ) );
	}

	private function response( Attendee $attendee, string $result, string $message = '' ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'result'  => $result,
				'message' => $message,
			) + $this->data( $attendee )
		);
	}

	/**
	 * Who it is, what for, warnings for the door, and how many are in.
	 *
	 * @return array<string, mixed>
	 */
	private function data( Attendee $attendee ): array {
		$type       = ( new TicketTypes() )->get( $attendee->ticket_type_id );
		$occurrence = $type ? TicketTypes::date_of( $type ) : null;
		$warnings   = array();

		if ( $occurrence && $occurrence->is_called_off() ) {
			$warnings[] = array(
				'code'    => 'called_off',
				'message' => \OpenEvents\Content\EventStatus::CANCELLED === $occurrence->event_status
					? __( 'This date is cancelled.', 'openevents' )
					: __( 'This date is postponed.', 'openevents' ),
			);
		}
		if ( $occurrence && ! self::is_today( $occurrence ) ) {
			$warnings[] = array(
				'code'    => 'other_date',
				/* translators: %s: date and time */
				'message' => sprintf( __( 'This booking is for %s, not today.', 'openevents' ), Formatter::range( $occurrence ) ),
			);
		}
		$order = ( new Orders() )->by_uuid( $attendee->group_uuid ); // An order's attendees share its uuid; RSVPs have none.
		if ( $order && Order::UNPAID === $order->status ) {
			$warnings[] = array(
				'code'    => 'unpaid',
				/* translators: %s: amount */
				'message' => sprintf( __( 'Payment due at the door: %s for the whole order.', 'openevents' ), Money::format( $order->total_minor, $order->currency ) ),
			);
		}

		$counts = $this->attendees->counts( $attendee->event_id, $attendee->ticket_type_id );
		return array(
			'attendee' => array(
				'id'            => $attendee->id,
				'name'          => $attendee->name,
				'code'          => $attendee->security_code,
				'status'        => $attendee->status,
				'checked_in'    => null !== $attendee->checked_in_utc,
				'checked_in_at' => $attendee->checked_in_utc ? self::time( $attendee->checked_in_utc ) : '',
				'ticket'        => $type ? ( 'rsvp' === $type->kind ? __( 'RSVP', 'openevents' ) : $type->name ) : '',
				'event'         => html_entity_decode( get_the_title( $attendee->event_id ), ENT_QUOTES ),
				'date'          => $occurrence ? Formatter::range( $occurrence ) : '',
			),
			'warnings' => $warnings,
			'counts'   => array(
				'checked_in' => $counts['checked_in'],
				'going'      => $counts['going'],
			),
		);
	}

	/**
	 * Whether the date is on today, in its own timezone (or runs over it).
	 */
	private static function is_today( \OpenEvents\Occurrences\Occurrence $occurrence ): bool {
		$today = ( new \DateTimeImmutable( 'now', $occurrence->start->getTimezone() ) )->format( 'Y-m-d' );
		return $occurrence->start->format( 'Y-m-d' ) <= $today && $today <= $occurrence->end->format( 'Y-m-d' );
	}

	private static function time( string $utc ): string {
		return wp_date( (string) get_option( 'time_format' ), (int) strtotime( $utc . ' UTC' ) );
	}

	public function menu(): void {
		add_submenu_page(
			PostTypes::menu_parent( openevents()->settings() ),
			__( 'Check-in', 'openevents' ),
			__( 'Check-in', 'openevents' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			\OpenEvents\Modules\Rsvp\Admin::enqueue_bundle();
		}
	}

	public function render(): void {
		// A scanned pass opens this page with its code: look it up, don't check in.
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['code'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to letters and digits.
		printf(
			'<div id="openevents-attendance" class="openevents-admin" data-screen="check-in" data-code="%s"><noscript>%s</noscript></div>',
			esc_attr( substr( $code, 0, 32 ) ),
			esc_html__( 'Check-in needs JavaScript. You can also check people in from the Attendees list.', 'openevents' )
		);
	}
}
