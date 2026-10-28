<?php
/**
 * Waitlist rules for RSVPs and tickets.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Joins, leaves, and fills from the queue whenever seats are released.
 *
 * - RSVP (free): the next person who fits is promoted straight to "going".
 * - Tickets: seats are held for the next person, who gets a claim link valid
 *   for a set number of hours; unclaimed holds pass down the queue.
 *
 * First come, first served: an entry that needs more seats than are free
 * keeps its place while smaller entries behind it are served. Every entry
 * changes state through a conditional UPDATE and seats are taken with the
 * same atomic reserve as checkout, so concurrent releases can't promote
 * anyone twice or overfill an event.
 */
final class WaitlistService {

	public const CRON_HOOK = 'openevents_waitlist_sweep';

	private static bool $registered = false;

	/** @var array<int, true> Ticket types being processed (re-entrancy guard). */
	private static array $processing = array();

	public function __construct(
		private Waitlist $waitlist = new Waitlist(),
		private TicketTypes $types = new TicketTypes(),
		private Attendees $attendees = new Attendees(),
		private WaitlistMailer $mailer = new WaitlistMailer(),
	) {}

	/**
	 * Hooks the queue to seat releases and schedules the offer sweep. Safe to
	 * call from several modules.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		WaitlistController::register();
		add_action( 'openevents/seats_released', array( self::class, 'on_seats_released' ) );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON_HOOK, static fn() => ( new self() )->expire_offers() );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'openevents_five_minutes', self::CRON_HOOK );
		}
		add_action( 'openevents/uninstall', static fn() => wp_clear_scheduled_hook( self::CRON_HOOK ) );
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules Cron schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public static function cron_schedule( array $schedules ): array {
		$schedules['openevents_five_minutes'] ??= array(
			'interval' => 300,
			'display'  => __( 'Every five minutes', 'openevents' ),
		);
		return $schedules;
	}

	public static function on_seats_released( int $ticket_type_id ): void {
		( new self() )->process( $ticket_type_id );
	}

	public static function enabled( TicketType $type ): bool {
		return 'active' === $type->status && (bool) $type->setting( 'waitlist', false );
	}

	public static function offer_hours(): int {
		return max( 1, min( 168, (int) ( openevents()->settings()->get( 'waitlist_offer_hours' ) ?: 24 ) ) );
	}

	/**
	 * Adds someone to the queue. $input: name, email, quantity, ip.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public function join( TicketType $type, array $input ): WaitlistResult {
		if ( ! self::enabled( $type ) ) {
			return WaitlistResult::error( 'disabled', __( 'There’s no waitlist for this.', 'openevents' ) );
		}
		$name     = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$quantity = max( 1, (int) ( $input['quantity'] ?? 1 ) );
		$max      = (int) ( 'rsvp' === $type->kind ? $type->setting( 'max_per_response', 1 ) : $type->setting( 'max_per_order', 10 ) );

		if ( '' === $name || mb_strlen( $name ) > 200 ) {
			return WaitlistResult::error( 'invalid', __( 'Please enter your name.', 'openevents' ) );
		}
		if ( ! is_email( $email ) ) {
			return WaitlistResult::error( 'invalid', __( 'Please enter a valid email address.', 'openevents' ) );
		}
		if ( $quantity > $max ) {
			/* translators: %d: maximum people */
			return WaitlistResult::error( 'invalid', sprintf( _n( 'You can join for up to %d person.', 'You can join for up to %d people.', $max, 'openevents' ), $max ) );
		}
		if ( null === $type->remaining() || $type->remaining() >= $quantity ) {
			return WaitlistResult::error( 'available', __( 'There’s room now, so you can book directly.', 'openevents' ) );
		}

		// Same privacy rule as RSVP: never let someone else change an existing entry.
		$existing = $this->waitlist->active_for_email( $type->id, $email );
		if ( $existing ) {
			$token = Guard::new_token();
			$this->set_token( $existing->id, $token );
			$this->mailer->manage( $existing, $token );
			return WaitlistResult::ok( 'exists', __( 'You’re already on the waitlist. We’ve emailed you a link to manage your place.', 'openevents' ) );
		}

		$token = Guard::new_token();
		$entry = $this->waitlist->add(
			array(
				'ticket_type_id' => $type->id,
				'event_id'       => $type->event_id,
				'name'           => $name,
				'email'          => $email,
				'quantity'       => $quantity,
				'token_hash'     => Guard::hash_token( $token ),
				'ip_hash'        => Guard::hash_ip( (string) ( $input['ip'] ?? '' ) ),
			)
		);
		$position = $this->waitlist->position( $entry );
		$this->mailer->joined( $entry, $position, $token );

		/**
		 * Fires when someone joins a waitlist.
		 *
		 * @param WaitlistEntry $entry Entry.
		 */
		do_action( 'openevents/waitlist/joined', $entry );

		return WaitlistResult::ok(
			'waitlisted',
			/* translators: %d: place in line */
			sprintf( __( 'You’re on the waitlist (#%d). We’ll email you if a spot opens up.', 'openevents' ), $position ),
			$entry,
			$token,
			$position
		);
	}

	/**
	 * Leaves the queue (or gives up a held offer, which passes it on).
	 */
	public function leave( string $token ): WaitlistResult {
		$entry = $this->waitlist->by_token( $token );
		if ( ! $entry || ! $this->waitlist->transition( $entry->id, array( WaitlistEntry::WAITING, WaitlistEntry::OFFERED ), WaitlistEntry::LEFT ) ) {
			return WaitlistResult::error( 'invalid', __( 'That link is no longer valid.', 'openevents' ) );
		}
		if ( WaitlistEntry::OFFERED === $entry->status ) {
			$this->types->release( $entry->ticket_type_id, $entry->quantity );
		}
		return WaitlistResult::ok( 'left', __( 'You’ve left the waitlist.', 'openevents' ) );
	}

	/**
	 * Organiser removed someone from the waitlist.
	 */
	public function remove( WaitlistEntry $entry ): bool {
		if ( ! $this->waitlist->transition( $entry->id, array( WaitlistEntry::WAITING, WaitlistEntry::OFFERED ), WaitlistEntry::LEFT ) ) {
			return false;
		}
		if ( WaitlistEntry::OFFERED === $entry->status ) {
			$this->types->release( $entry->ticket_type_id, $entry->quantity );
		}
		return true;
	}

	/**
	 * Fills free seats from the front of the queue.
	 *
	 * @return int Entries promoted or offered.
	 */
	public function process( int $ticket_type_id ): int {
		if ( isset( self::$processing[ $ticket_type_id ] ) ) {
			return 0;
		}
		self::$processing[ $ticket_type_id ] = true;
		$filled                              = 0;
		try {
			$type = $this->types->get( $ticket_type_id );
			if ( ! $type || ! self::enabled( $type ) ) {
				return 0;
			}
			foreach ( $this->waitlist->queue( $ticket_type_id ) as $entry ) {
				$type      = $this->types->get( $ticket_type_id );
				$remaining = $type->remaining();
				if ( null !== $remaining && $remaining <= 0 ) {
					break;
				}
				if ( null !== $remaining && $entry->quantity > $remaining ) {
					continue; // Keeps its place; smaller entries behind it can fit.
				}
				$filled += (int) ( 'rsvp' === $type->kind ? $this->promote( $type, $entry ) : $this->offer( $entry ) );
			}
		} finally {
			unset( self::$processing[ $ticket_type_id ] );
		}
		return $filled;
	}

	/**
	 * RSVP: move straight to "going" and send the usual confirmation link.
	 */
	private function promote( TicketType $type, WaitlistEntry $entry ): bool {
		if ( ! $this->waitlist->transition( $entry->id, array( WaitlistEntry::WAITING ), WaitlistEntry::CLAIMED ) ) {
			return false;
		}
		if ( ! $this->types->reserve( $type->id, $entry->quantity ) ) {
			$this->waitlist->transition( $entry->id, array( WaitlistEntry::CLAIMED ), WaitlistEntry::WAITING );
			return false;
		}
		$token = Guard::new_token();
		$this->attendees->create_group(
			array(
				'name'           => $entry->name,
				'email'          => $entry->email,
				'status'         => Attendee::GOING,
				'ticket_type_id' => $type->id,
				'event_id'       => $type->event_id,
				'token_hash'     => Attendees::hash_token( $token ),
			),
			$entry->quantity,
			$entry->uuid
		);
		$this->mailer->promoted( $entry, $token );

		/**
		 * Fires when a waitlisted RSVP is promoted to going.
		 *
		 * @param WaitlistEntry $entry Entry.
		 */
		do_action( 'openevents/waitlist/promoted', $entry );
		return true;
	}

	/**
	 * Tickets: hold the seats and send a time-limited claim link.
	 */
	private function offer( WaitlistEntry $entry ): bool {
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::offer_hours() * HOUR_IN_SECONDS );
		if ( ! $this->waitlist->transition( $entry->id, array( WaitlistEntry::WAITING ), WaitlistEntry::OFFERED, array( 'offer_expires_utc' => $expires ) ) ) {
			return false;
		}
		if ( ! $this->types->reserve( $entry->ticket_type_id, $entry->quantity ) ) {
			$this->waitlist->transition( $entry->id, array( WaitlistEntry::OFFERED ), WaitlistEntry::WAITING, array( 'offer_expires_utc' => null ) );
			return false;
		}
		$token = Guard::new_token();
		$this->set_token( $entry->id, $token );
		$this->mailer->offered( $this->waitlist->get( $entry->id ), $token );

		/**
		 * Fires when waitlisted ticket buyers are offered held seats.
		 *
		 * @param WaitlistEntry $entry Entry.
		 */
		do_action( 'openevents/waitlist/offered', $this->waitlist->get( $entry->id ) );
		return true;
	}

	/**
	 * A live ticket offer behind a claim link, or null.
	 */
	public function offer_for_token( string $token ): ?WaitlistEntry {
		$entry = $this->waitlist->by_token( $token );
		return $entry && $entry->offer_is_live() ? $entry : null;
	}

	/**
	 * The offer was turned into an order: the held seats now belong to it.
	 */
	public function mark_claimed( WaitlistEntry $entry, int $order_id ): bool {
		return $this->waitlist->transition( $entry->id, array( WaitlistEntry::OFFERED ), WaitlistEntry::CLAIMED, array( 'order_id' => $order_id, 'offer_expires_utc' => null ) );
	}

	/**
	 * Expires unclaimed offers and passes their seats down the queue.
	 */
	public function expire_offers( int $limit = 100 ): int {
		$count = 0;
		foreach ( $this->waitlist->expired_offer_ids( $limit ) as $id ) {
			$entry = $this->waitlist->get( $id );
			if ( $entry && $this->waitlist->transition( $id, array( WaitlistEntry::OFFERED ), WaitlistEntry::EXPIRED ) ) {
				$this->types->release( $entry->ticket_type_id, $entry->quantity );
				++$count;
			}
		}
		return $count;
	}

	public function waiting_count( int $ticket_type_id ): int {
		return $this->waitlist->waiting_count( $ticket_type_id );
	}

	private function set_token( int $entry_id, string $token ): void {
		global $wpdb;
		$wpdb->update( Schema::waitlist(), array( 'token_hash' => Guard::hash_token( $token ) ), array( 'id' => $entry_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
