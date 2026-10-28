<?php
/**
 * Waitlist endpoints, forms, and admin screen.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

use OpenEvents\Content\PostTypes;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /openevents/v1/events/{id}/waitlist  Join a ticket type's waitlist (public).
 * POST /openevents/v1/waitlist/leave        Leave, with the private token (public).
 * GET  /openevents/v1/waitlist              Organiser list.
 * POST /openevents/v1/waitlist/{id}         Organiser: remove.
 *
 * RSVP waitlists join through the RSVP form (status "waitlist"), so one form
 * covers both. No-JS fallbacks post to admin-post.php.
 */
final class WaitlistController {

	public const SLUG = 'openevents-waitlist';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		$self             = new self();
		add_action( 'rest_api_init', array( $self, 'routes' ) );
		add_action( 'admin_post_openevents_waitlist', array( $self, 'handle_join' ) );
		add_action( 'admin_post_nopriv_openevents_waitlist', array( $self, 'handle_join' ) );
		add_action( 'admin_post_openevents_waitlist_leave', array( $self, 'handle_leave' ) );
		add_action( 'admin_post_nopriv_openevents_waitlist_leave', array( $self, 'handle_leave' ) );
		add_filter( 'wp_robots', array( $self, 'robots' ) );
		add_action( 'template_redirect', array( $self, 'no_cache' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $self, 'menu' ), 22 );
			add_action( 'admin_enqueue_scripts', array( $self, 'enqueue' ) );
		}
	}

	public function routes(): void {
		register_rest_route(
			'openevents/v1',
			'/events/(?P<id>\d+)/waitlist',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'join' ),
				'permission_callback' => '__return_true', // Honeypot, signed timestamp, and rate limit.
				'args'                => array(
					'ticket_type_id' => array( 'type' => 'integer', 'required' => true ),
					'name'           => array( 'type' => 'string' ),
					'email'          => array( 'type' => 'string' ),
					'quantity'       => array( 'type' => 'integer', 'minimum' => 1 ),
					'website'        => array( 'type' => 'string' ),
					'ts'             => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			'openevents/v1',
			'/waitlist/leave',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'leave' ),
				'permission_callback' => '__return_true', // The token is the authorisation.
				'args'                => array( 'token' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			'openevents/v1',
			'/waitlist',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_entries' ),
				'permission_callback' => static fn( WP_REST_Request $r ) => (int) $r['event'] ? current_user_can( 'edit_post', (int) $r['event'] ) : current_user_can( (string) apply_filters( 'openevents/attendees/capability', 'edit_others_posts' ) ),
				'args'                => array(
					'event'    => array( 'type' => 'integer', 'default' => 0 ),
					'status'   => array( 'type' => 'string', 'default' => '' ),
					'search'   => array( 'type' => 'string', 'default' => '' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
				),
			)
		);
		register_rest_route(
			'openevents/v1',
			'/waitlist/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'entry_action' ),
				'permission_callback' => static function ( WP_REST_Request $r ): bool {
					$entry = ( new Waitlist() )->get( (int) $r['id'] );
					return $entry && current_user_can( 'edit_post', $entry->event_id );
				},
				'args'                => array( 'action' => array( 'type' => 'string', 'enum' => array( 'remove' ), 'required' => true ) ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Raw input.
	 */
	private function do_join( int $event_id, array $input ): WaitlistResult {
		if ( Guard::is_bot( $input ) ) {
			return WaitlistResult::ok( 'waitlisted', __( 'You’re on the waitlist.', 'openevents' ) );
		}
		if ( ! Guard::verify_timestamp( (string) ( $input['ts'] ?? '' ) ) ) {
			return WaitlistResult::error( 'invalid', __( 'Please wait a moment and submit the form again.', 'openevents' ) );
		}
		$type = ( new TicketTypes() )->get( (int) ( $input['ticket_type_id'] ?? 0 ) );
		if ( ! $type || $type->event_id !== $event_id || 'publish' !== get_post_status( $event_id ) ) {
			return WaitlistResult::error( 'disabled', __( 'There’s no waitlist for this.', 'openevents' ) );
		}
		return ( new WaitlistService() )->join( $type, array_merge( $input, array( 'ip' => Guard::client_ip() ) ) );
	}

	public function join( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->do_join( (int) $request['id'], $request->get_params() );
		$codes  = array( 'invalid' => 422, 'disabled' => 404, 'available' => 409 );
		return new WP_REST_Response( $result->to_array(), $result->ok ? 200 : ( $codes[ $result->code ] ?? 400 ) );
	}

	public function leave( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new WaitlistService() )->leave( (string) $request['token'] );
		return new WP_REST_Response( $result->to_array(), $result->ok ? 200 : 404 );
	}

	public function handle_join(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; see do_join().
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$result   = $this->do_join(
			$event_id,
			array(
				'ticket_type_id' => absint( $_POST['ticket_type_id'] ?? 0 ),
				'name'           => wp_unslash( $_POST['name'] ?? '' ),
				'email'          => wp_unslash( $_POST['email'] ?? '' ),
				'quantity'       => absint( $_POST['quantity'] ?? 1 ),
				'website'        => wp_unslash( $_POST['website'] ?? '' ),
				'ts'             => sanitize_text_field( wp_unslash( $_POST['ts'] ?? '' ) ),
			)
		);
		// phpcs:enable
		wp_safe_redirect( add_query_arg( 'oe_waitlist_result', $result->code, (string) get_permalink( $event_id ) ) . '#tickets', 303 );
		exit;
	}

	public function handle_leave(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the private token authorises this.
		$token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$anchor   = sanitize_key( wp_unslash( $_POST['anchor'] ?? 'tickets' ) );
		// phpcs:enable
		$result = ( new WaitlistService() )->leave( $token );
		wp_safe_redirect( add_query_arg( 'oe_waitlist_result', $result->ok ? 'left' : 'invalid', (string) get_permalink( $event_id ) ) . '#' . $anchor, 303 );
		exit;
	}

	/**
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['oe_waitlist'] ) || isset( $_GET['oe_waitlist_result'] ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * Private links and outcomes are per person: never page-cache them.
	 */
	public function no_cache(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['oe_waitlist'] ) || isset( $_GET['oe_waitlist_result'] ) ) {
			nocache_headers();
		}
	}

	public function list_entries( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new Waitlist() )->query(
			array(
				'event_id' => (int) $request['event'],
				'status'   => sanitize_key( (string) $request['status'] ),
				'search'   => (string) $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);
		$types = new TicketTypes();
		$items = array_map(
			static function ( WaitlistEntry $e ) use ( $types ): array {
				$type = $types->get( $e->ticket_type_id );
				return array_merge(
					$e->to_array(),
					array(
						'event_title' => html_entity_decode( get_the_title( $e->event_id ), ENT_QUOTES ),
						'type_name'   => $type ? ( 'rsvp' === $type->kind ? __( 'RSVP', 'openevents' ) : $type->name ) : '',
					)
				);
			},
			$result['items']
		);
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}

	public function entry_action( WP_REST_Request $request ): WP_REST_Response {
		$entry = ( new Waitlist() )->get( (int) $request['id'] );
		if ( ! ( new WaitlistService() )->remove( $entry ) ) {
			return new WP_REST_Response( array( 'message' => __( 'This person is no longer waiting.', 'openevents' ) ), 409 );
		}
		return new WP_REST_Response( ( new Waitlist() )->get( $entry->id )->to_array() );
	}

	public function menu(): void {
		add_submenu_page( PostTypes::menu_parent( openevents()->settings() ), __( 'Waitlist', 'openevents' ), __( 'Waitlist', 'openevents' ), 'edit_posts', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			\OpenEvents\Modules\Rsvp\Admin::enqueue_bundle();
		}
	}

	public function render(): void {
		printf(
			'<div id="openevents-attendance" class="openevents-admin" data-screen="waitlist" data-events="%s" data-event="%d"></div>',
			esc_attr( (string) wp_json_encode( \OpenEvents\Modules\Rsvp\Admin::events() ) ),
			absint( $_GET['event'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * The "leave the waitlist" form shown behind a private link.
	 */
	public static function leave_form( WaitlistEntry $entry, string $token, string $anchor ): string {
		$waitlist = new Waitlist();
		$type     = ( new TicketTypes() )->get( $entry->ticket_type_id );
		$what     = $type && 'paid' === $type->kind ? $entry->quantity . ' × ' . $type->name : sprintf(
			/* translators: %d: number of people */
			_n( '%d person', '%d people', $entry->quantity, 'openevents' ),
			$entry->quantity
		);
		$statuses = array(
			/* translators: 1: what they're waiting for, 2: place in line */
			WaitlistEntry::WAITING => sprintf( __( 'You’re on the waitlist for %1$s (#%2$d in line).', 'openevents' ), $what, $waitlist->position( $entry ) ),
			WaitlistEntry::OFFERED => __( 'Don’t need them any more? Release them for the next person in line.', 'openevents' ),
			WaitlistEntry::CLAIMED => __( 'You got a spot from the waitlist.', 'openevents' ),
			WaitlistEntry::EXPIRED => __( 'Your hold expired and passed to the next person in line.', 'openevents' ),
		);
		$status   = $statuses[ $entry->status ] ?? __( 'You’re no longer on the waitlist.', 'openevents' );

		$html = '<div class="oe-waitlist__entry" role="status" tabindex="-1"><p>' . esc_html( $status ) . '</p>';
		if ( in_array( $entry->status, array( WaitlistEntry::WAITING, WaitlistEntry::OFFERED ), true ) ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wp-on--submit="actions.leaveWaitlist">';
			$html .= '<input type="hidden" name="action" value="openevents_waitlist_leave"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
			$html .= '<input type="hidden" name="event_id" value="' . esc_attr( (string) $entry->event_id ) . '"><input type="hidden" name="anchor" value="' . esc_attr( $anchor ) . '">';
			$html .= '<button type="submit" class="oe-waitlist__leave">' . esc_html( WaitlistEntry::OFFERED === $entry->status ? __( 'No thanks, release these tickets', 'openevents' ) : __( 'Leave the waitlist', 'openevents' ) ) . '</button></form>';
		}
		return $html . '</div>';
	}

	public static function result_text( string $code ): string {
		$messages = array(
			'waitlisted' => __( 'You’re on the waitlist. We’ll email you if a spot opens up.', 'openevents' ),
			'exists'     => __( 'You’re already on the waitlist. We’ve emailed you a link to manage your place.', 'openevents' ),
			'left'       => __( 'You’ve left the waitlist.', 'openevents' ),
			'available'  => __( 'Good news: there’s room now, so you can book directly.', 'openevents' ),
		);
		return $messages[ $code ] ?? __( 'Please check the form and try again.', 'openevents' );
	}
}
