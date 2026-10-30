<?php
/**
 * RSVP business rules.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Rsvp;

use OpenEvents\Attendance\Attendee;
use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Fields;
use OpenEvents\Attendance\TicketType;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;

/**
 * Turns event RSVP settings into a ticket type, and handles responses.
 *
 * Every entry point (REST, the no-JS form, abilities, tests) goes through
 * respond() and cancel(), so validation, spam checks, and seat accounting
 * live in one place.
 */
final class Service {

	public const META_KEY  = '_oe_rsvp';
	public const TYPE_KEY  = 'rsvp';
	public const RATE      = 10;  // Responses per IP per window.
	public const RATE_SPAN = 600; // Window, seconds.

	public function __construct(
		private TicketTypes $types = new TicketTypes(),
		private Attendees $attendees = new Attendees(),
		private ?Mailer $mailer = null,
	) {
		$this->mailer ??= new Mailer();
	}

	/**
	 * Defaults for the _oe_rsvp meta object.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'          => false,
			'capacity'         => 0,     // 0 = unlimited.
			'max_per_response' => 2,     // Respondent plus guests.
			'deadline'         => '',    // Local "Y-m-d H:i:s"; empty = when the event starts.
			'allow_not_going'  => true,
			'show_count'       => true,
			'waitlist'         => false, // When full, let people queue for a spot.
			'attendee_details' => 'none',  // Each guest's own name and email: none, optional, required.
			'fields'           => array(), // Questions for each person; see Attendance\Fields.
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function config( int $event_id ): array {
		$stored = get_post_meta( $event_id, self::META_KEY, true );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Mirrors the event's RSVP settings into its ticket type row.
	 */
	public function sync( int $event_id ): void {
		$event = Event::get( $event_id );
		if ( ! $event ) {
			return;
		}
		$config = self::config( $event_id );
		if ( ! $config['enabled'] ) {
			if ( $this->types->find( $event_id, self::TYPE_KEY ) ) {
				// Keep the row (and its attendees); just stop accepting responses.
				$this->types->set_status( $event_id, self::TYPE_KEY, 'archived' );
			}
			return;
		}

		$deadline = null;
		if ( '' !== (string) $config['deadline'] ) {
			$local = \DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, Meta::sanitize_datetime( $config['deadline'] ), $event->timezone() );
			if ( $local ) {
				$deadline = $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Meta::DATETIME_FORMAT );
			}
		}

		$this->types->upsert(
			$event_id,
			self::TYPE_KEY,
			array(
				'kind'         => 'rsvp',
				'name'         => __( 'RSVP', 'openevents' ),
				'capacity'     => (int) $config['capacity'] > 0 ? (int) $config['capacity'] : null,
				'sale_end_utc' => $deadline,
				'status'       => 'active',
				'settings'     => array(
					'max_per_response' => max( 1, min( 20, (int) $config['max_per_response'] ) ),
					'allow_not_going'  => (bool) $config['allow_not_going'],
					'show_count'       => (bool) $config['show_count'],
					'waitlist'         => (bool) $config['waitlist'],
					'attendee_details' => \OpenEvents\Attendance\Fields::sanitize_details( $config['attendee_details'] ),
					'fields'           => \OpenEvents\Attendance\Fields::sanitize( $config['fields'] ),
				),
			)
		);
	}

	/**
	 * The RSVP type for one date of the event (0 is the event's own date), or
	 * null when RSVPs are off or the event has no such date. Each date of a
	 * repeating event has its own row, created on first use, so only dates
	 * that are really on the schedule may create one.
	 */
	public function ticket_type( int $event_id, int $sequence = 0 ): ?TicketType {
		$type = $this->types->find( $event_id, self::TYPE_KEY );
		if ( ! $type || 'active' !== $type->status ) {
			return null;
		}
		if ( 0 === $sequence ) {
			return $type;
		}
		return openevents()->occurrences()->by_sequence( $event_id, $sequence ) ? $this->types->for_date( $type, $sequence ) : null;
	}

	/**
	 * Why RSVPs are closed for the type's date, or null when open.
	 */
	public function closed_reason( int $event_id, ?TicketType $type = null ): ?string {
		$type     ??= $this->ticket_type( $event_id );
		$occurrence = $type ? TicketTypes::occurrence( $type ) : null;
		if ( ! $type || ! $occurrence ) {
			return 'disabled';
		}
		if ( $occurrence->has_ended() ) {
			return 'ended';
		}
		if ( $occurrence->is_called_off() ) {
			return $occurrence->event_status; // "cancelled" or "postponed".
		}
		$now = gmdate( Meta::DATETIME_FORMAT );
		if ( $type->sale_end_utc ) {
			return $now > $type->sale_end_utc ? 'deadline' : null;
		}
		// Without an explicit deadline, responses close when the event starts.
		return $now >= $occurrence->start_utc()->format( Meta::DATETIME_FORMAT ) ? 'started' : null;
	}

	/**
	 * Handles a response. $input keys: name, email, status ("going"|"not_going"),
	 * quantity, sequence (which date of a repeating event; 0 by default),
	 * token (to change an earlier response, whose date it keeps), website
	 * (honeypot), ts (signed form timestamp), ip.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public function respond( int $event_id, array $input ): Result {
		$token  = (string) ( $input['token'] ?? '' );
		$group  = '' !== $token ? $this->attendees->find_group_by_token( $token ) : null;
		$people = $group ? $this->attendees->group( $group ) : array();
		$type   = $group ? $this->response_type( $event_id, $people ) : $this->ticket_type( $event_id, absint( $input['sequence'] ?? 0 ) );
		if ( ! $type ) {
			return $group
				? Result::error( 'invalid', __( 'That link is no longer valid.', 'openevents' ) )
				: Result::error( 'disabled', __( 'This event is not taking RSVPs.', 'openevents' ) );
		}

		// Bots fill hidden fields; humans can't. Pretend it worked.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) ) {
			return Result::ok( 'going', __( 'Thanks! Your RSVP is confirmed.', 'openevents' ) );
		}
		if ( ! self::verify_timestamp( (string) ( $input['ts'] ?? '' ) ) ) {
			return Result::error( 'invalid', __( 'Please wait a moment and submit the form again.', 'openevents' ) );
		}

		// Full event with a waitlist: queue instead of booking.
		if ( 'waitlist' === ( $input['status'] ?? '' ) && ! $group ) {
			$closed = $this->closed_reason( $event_id, $type );
			if ( $closed ) {
				return Result::error( 'closed', Form::closed_text( $closed ) );
			}
			$joined = ( new \OpenEvents\Attendance\WaitlistService() )->join( $type, $input );
			return $joined->ok ? Result::ok( $joined->code, $joined->message ) : Result::error( 'available' === $joined->code ? 'invalid' : $joined->code, $joined->message );
		}

		$name   = sanitize_text_field( (string) ( $input['name'] ?? ( $people[0]->name ?? '' ) ) );
		$email  = sanitize_email( (string) ( $input['email'] ?? ( $people[0]->email ?? '' ) ) );
		$status = Attendee::NOT_GOING === ( $input['status'] ?? '' ) ? Attendee::NOT_GOING : Attendee::GOING;
		$max    = (int) $type->setting( 'max_per_response', 1 );
		$qty    = Attendee::GOING === $status ? max( 1, (int) ( $input['quantity'] ?? 1 ) ) : 1;

		if ( '' === $name || mb_strlen( $name ) > 200 ) {
			return Result::error( 'invalid', __( 'Please enter your name.', 'openevents' ) );
		}
		if ( ! is_email( $email ) ) {
			return Result::error( 'invalid', __( 'Please enter a valid email address.', 'openevents' ) );
		}
		if ( Attendee::NOT_GOING === $status && ! $type->setting( 'allow_not_going', true ) ) {
			return Result::error( 'invalid', __( 'This event only accepts “going” responses.', 'openevents' ) );
		}
		if ( $qty > $max ) {
			/* translators: %d: maximum number of people per response */
			return Result::error( 'invalid', sprintf( _n( 'You can RSVP for up to %d person.', 'You can RSVP for up to %d people.', $max, 'openevents' ), $max ) );
		}
		$closed = $this->closed_reason( $event_id, $type );
		if ( $closed ) {
			return Result::error( 'closed', Form::closed_text( $closed ) );
		}

		// Attendee questions, for each person going. Checked before any seat is held.
		$answers = array( 'people' => array() );
		if ( Attendee::GOING === $status && Fields::asks( $type ) ) {
			$answers = Fields::people(
				$type,
				$input['attendees'] ?? array(),
				$qty,
				array(
					'name'  => $name,
					'email' => $email,
				),
				true
			);
			if ( $answers['error'] ) {
				return Result::error( 'fields', $answers['error'] );
			}
		}

		$ip_hash = self::hash_ip( (string) ( $input['ip'] ?? '' ) );
		if ( ! $group && $this->attendees->count_recent_by_ip( $ip_hash, self::RATE_SPAN ) >= self::RATE ) {
			return Result::error( 'rate_limited', __( 'Too many responses from your connection. Please try again later.', 'openevents' ) );
		}

		// A new response for an email that already responded: don't let whoever
		// typed it change someone else's RSVP. Email the owner a fresh link instead.
		if ( ! $group ) {
			$existing = $this->attendees->find_group_by_email( $type->id, $email );
			if ( $existing ) {
				$token = self::new_token();
				$this->rotate_token( $existing, $token );
				$this->mailer->manage_link( $event_id, $this->attendees->group( $existing ), $token );
				return Result::ok( 'exists', __( 'You’ve already responded. We’ve emailed you a link to change your RSVP.', 'openevents' ) );
			}
		}

		$seats_before = count( array_filter( $people, static fn( Attendee $a ) => Attendee::GOING === $a->status ) );
		$seats_after  = Attendee::GOING === $status ? $qty : 0;
		$delta        = $seats_after - $seats_before;

		if ( $delta > 0 && ! $this->types->reserve( $type->id, $delta ) ) {
			$remaining = $this->types->get( $type->id )?->remaining() ?? 0;
			return Result::error(
				'full',
				$remaining > 0
					/* translators: %d: seats left */
					? sprintf( _n( 'Only %d spot is left.', 'Only %d spots are left.', $remaining, 'openevents' ), $remaining )
					: __( 'Sorry, this event is full.', 'openevents' )
			);
		}
		if ( $delta < 0 ) {
			$this->types->release( $type->id, -$delta );
		}

		if ( $group ) {
			// Changing a response: keep the same private link.
			$token_hash = $this->current_token_hash( $group );
			$this->attendees->delete_group( $group );
		} else {
			$token      = self::new_token();
			$token_hash = Attendees::hash_token( $token );
		}
		$group = $this->attendees->create_group(
			array(
				'name'           => $name,
				'email'          => $email,
				'status'         => $status,
				'ticket_type_id' => $type->id,
				'event_id'       => $event_id,
				'token_hash'     => $token_hash,
				'ip_hash'        => $ip_hash,
			),
			$seats_after > 0 ? $seats_after : 1,
			$group,
			false,
			$answers['people']
		);

		$people = $this->attendees->group( $group );
		$this->mailer->confirmation( $event_id, $people, $token, '' !== (string) ( $input['token'] ?? '' ) );

		/**
		 * Fires after an RSVP is created or changed.
		 *
		 * @param Attendee[] $people   The response's attendee rows.
		 * @param int        $event_id Event id.
		 */
		do_action( 'openevents/rsvp/responded', $people, $event_id );

		$message = Attendee::GOING === $status
			? ( 1 === $qty
				? __( 'You’re going! We’ve emailed you a confirmation.', 'openevents' )
				/* translators: %d: number of people */
				: sprintf( __( 'You’re going, party of %d! We’ve emailed you a confirmation.', 'openevents' ), $qty ) )
			: __( 'Thanks for letting us know you can’t make it.', 'openevents' );

		return Result::ok( $status, $message, $group );
	}

	/**
	 * Cancels the response behind a private link and frees its seats.
	 */
	public function cancel( string $token ): Result {
		$group = $this->attendees->find_group_by_token( $token );
		if ( ! $group ) {
			return Result::error( 'invalid', __( 'That link is no longer valid.', 'openevents' ) );
		}
		$people = $this->attendees->group( $group );
		$seats  = count( array_filter( $people, static fn( Attendee $a ) => Attendee::GOING === $a->status ) );
		$this->types->release( $people[0]->ticket_type_id, $seats );
		$this->attendees->set_group_status( $group, Attendee::CANCELLED );

		do_action( 'openevents/rsvp/cancelled', $people, $people[0]->event_id );
		return Result::ok( 'cancelled', __( 'Your RSVP has been cancelled.', 'openevents' ), $group );
	}

	/**
	 * The RSVP type (and so the date) a response belongs to, if it's this
	 * event's and RSVPs are still on.
	 *
	 * @param Attendee[] $people Response rows.
	 */
	public function response_type( int $event_id, array $people ): ?TicketType {
		$type = $people ? $this->types->get( $people[0]->ticket_type_id ) : null;
		return $type && $type->event_id === $event_id && self::TYPE_KEY === $type->type_key && 'active' === $type->status ? $type : null;
	}

	/**
	 * The response behind a private link, for prefilling the form.
	 *
	 * @return Attendee[]
	 */
	public function response_for_token( string $token ): array {
		$group = $this->attendees->find_group_by_token( $token );
		$rows  = $group ? $this->attendees->group( $group ) : array();
		return ( $rows && Attendee::CANCELLED !== $rows[0]->status ) ? $rows : array();
	}

	private function rotate_token( string $group, string $token ): void {
		global $wpdb;
		$wpdb->update( \OpenEvents\Attendance\Schema::attendees(), array( 'token_hash' => Attendees::hash_token( $token ) ), array( 'group_uuid' => $group ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function current_token_hash( string $group ): string {
		global $wpdb;
		$table = \OpenEvents\Attendance\Schema::attendees();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$table} WHERE group_uuid = %s LIMIT 1", $group ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function new_token(): string {
		return \OpenEvents\Attendance\Guard::new_token();
	}

	/**
	 * Signed form timestamp (see Attendance\Guard).
	 */
	public static function sign_timestamp( ?int $time = null ): string {
		return \OpenEvents\Attendance\Guard::sign_timestamp( $time );
	}

	public static function verify_timestamp( string $value ): bool {
		return \OpenEvents\Attendance\Guard::verify_timestamp( $value );
	}

	private static function hash_ip( string $ip ): string {
		return \OpenEvents\Attendance\Guard::hash_ip( $ip );
	}
}
