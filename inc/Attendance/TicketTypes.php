<?php
/**
 * Ticket type storage.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Reads ticket types and reserves seats atomically.
 *
 * Each (event, key) has a row for the event's own date (sequence 0). A
 * repeating event's other dates get their own rows (see for_date()), which
 * share its definition but count their own seats.
 */
final class TicketTypes {

	/**
	 * Creates or updates the type for (event, key), keeping `sold`. Changes
	 * apply to every date's row.
	 *
	 * @param array<string, mixed> $data Columns to write.
	 */
	public function upsert( int $event_id, string $type_key, array $data ): int {
		global $wpdb;
		$table    = Schema::ticket_types();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d AND type_key = %s AND sequence = 0", $event_id, $type_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$data = array_merge(
			array(
				'description' => '',
				'settings'    => '{}',
			),
			$data
		);
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$data['settings'] = (string) wp_json_encode( $data['settings'] );
		}

		if ( $existing ) {
			$where  = array(
				'event_id' => $event_id,
				'type_key' => $type_key,
			);
			$before = $this->all_dates( $event_id, $type_key );
			$wpdb->update( $table, $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $this->all_dates( $event_id, $type_key ) as $id => $after ) {
				$was = $before[ $id ] ?? null;
				// More room (capacity raised or made unlimited): let the waitlist fill it.
				if ( $was && 'active' === $after->status && ( null === $after->capacity ? null !== $was->capacity : ( null !== $was->capacity && $after->capacity > $was->capacity ) ) ) {
					do_action( 'openevents/seats_released', $id, 0 );
				}
			}
			return (int) $existing;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array_merge(
				$data,
				array(
					'event_id' => $event_id,
					'type_key' => $type_key,
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function set_status( int $event_id, string $type_key, string $status ): void {
		global $wpdb;
		$wpdb->update( Schema::ticket_types(), array( 'status' => $status ), array( 'event_id' => $event_id, 'type_key' => $type_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function get( int $id ): ?TicketType {
		global $wpdb;
		$table = Schema::ticket_types();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? TicketType::from_row( $row ) : null;
	}

	/**
	 * The type's row for the event's own date (sequence 0).
	 */
	public function find( int $event_id, string $type_key ): ?TicketType {
		global $wpdb;
		$table = Schema::ticket_types();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND type_key = %s AND sequence = 0", $event_id, $type_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? TicketType::from_row( $row ) : null;
	}

	/**
	 * The row for one date of $type's event, created from $type on first use
	 * (with no seats taken). Two requests creating it at once get the same
	 * row: the unique key decides, and the loser reads the winner's.
	 */
	public function for_date( TicketType $type, int $sequence ): ?TicketType {
		global $wpdb;
		if ( $sequence === $type->sequence ) {
			return $type;
		}
		$table = Schema::ticket_types();
		$find  = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND type_key = %s AND sequence = %d", $type->event_id, $type->type_key, $sequence ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = $find();
		if ( ! $row ) {
			$columns = 'event_id, occurrence_id, type_key, kind, name, description, price_minor, currency, capacity, sale_start_utc, sale_end_utc, status, settings';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} ({$columns}, sequence, sold) SELECT {$columns}, %d, 0 FROM {$table} WHERE event_id = %d AND type_key = %s AND sequence = %d", $sequence, $type->event_id, $type->type_key, $type->sequence ) );
			$row = $find();
		}
		return $row ? TicketType::from_row( $row ) : null;
	}

	/**
	 * Every date's row of a type, by id.
	 *
	 * @return array<int, TicketType>
	 */
	public function all_dates( int $event_id, string $type_key ): array {
		global $wpdb;
		$table = Schema::ticket_types();
		$out   = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND type_key = %s ORDER BY sequence", $event_id, $type_key ) ) as $row ) {
			$out[ (int) $row->id ] = TicketType::from_row( $row );
		}
		return $out;
	}

	/**
	 * The date a row sells seats for: the occurrence with its sequence, or
	 * for a series pass, the series' last date (passes sell until it starts).
	 */
	public static function occurrence( TicketType $type ): ?\OpenEvents\Occurrences\Occurrence {
		return self::is_series( $type ) ? openevents()->occurrences()->last_in_series( $type->event_id ) : self::date_of( $type );
	}

	/**
	 * The one date a row is for, to show people (null for a series pass,
	 * which covers many).
	 */
	public static function date_of( TicketType $type ): ?\OpenEvents\Occurrences\Occurrence {
		return self::is_series( $type ) ? null : openevents()->occurrences()->by_sequence( $type->event_id, $type->sequence );
	}

	/**
	 * The page to book a row on: its date's page, or the event's (or series').
	 */
	public static function url( TicketType $type ): string {
		$occurrence = self::date_of( $type );
		return $occurrence ? openevents_occurrence_url( $occurrence ) : (string) get_permalink( $type->event_id );
	}

	/**
	 * The date a row is for, as text ("November 5, 2026, 7:00 pm – 9:00 pm");
	 * empty for a series pass or a date no longer on the schedule.
	 *
	 * @param array<int, string> $cache Labels by type id (a cache the caller keeps).
	 */
	public function date_label( int $ticket_type_id, array &$cache ): string {
		if ( ! array_key_exists( $ticket_type_id, $cache ) ) {
			$type                     = $this->get( $ticket_type_id );
			$occurrence               = $type ? self::date_of( $type ) : null;
			$cache[ $ticket_type_id ] = $occurrence ? \OpenEvents\Frontend\Formatter::range( $occurrence ) : '';
		}
		return $cache[ $ticket_type_id ];
	}

	/**
	 * The dates of an event that have attendees, in date order, for filtering
	 * attendee lists.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function booked_dates( int $event_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sequences = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT t.sequence FROM %i t INNER JOIN %i a ON a.ticket_type_id = t.id WHERE t.event_id = %d ORDER BY t.sequence', Schema::ticket_types(), Schema::attendees(), $event_id ) );
		$out       = array();
		foreach ( array_map( 'intval', $sequences ) as $sequence ) {
			$occurrence = openevents()->occurrences()->by_sequence( $event_id, $sequence );
			$out[]      = array(
				'value' => $sequence,
				'label' => $occurrence ? \OpenEvents\Frontend\Formatter::range( $occurrence ) : __( 'A date no longer on the schedule', 'openevents' ),
			);
		}
		return $out;
	}

	private static function is_series( TicketType $type ): bool {
		return \OpenEvents\Modules\Series\Module::POST_TYPE === get_post_type( $type->event_id );
	}

	/**
	 * Takes `quantity` seats if they are available. One conditional UPDATE: the
	 * database decides, so two people can never both get the last seat.
	 */
	public function reserve( int $id, int $quantity ): bool {
		global $wpdb;
		if ( $quantity <= 0 ) {
			return true;
		}
		$table = Schema::ticket_types();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sold = sold + %d WHERE id = %d AND status = 'active' AND ( capacity IS NULL OR sold + %d <= capacity )", $quantity, $id, $quantity ) );
		return 1 === (int) $changed;
	}

	/**
	 * Adds seats regardless of capacity. Only for honouring a payment that
	 * arrived after its hold expired and the seats were resold.
	 */
	public function force_add( int $id, int $quantity ): void {
		global $wpdb;
		$table = Schema::ticket_types();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sold = sold + %d WHERE id = %d", $quantity, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function release( int $id, int $quantity ): void {
		global $wpdb;
		if ( $quantity <= 0 ) {
			return;
		}
		$table = Schema::ticket_types();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sold = CASE WHEN sold >= %d THEN sold - %d ELSE 0 END WHERE id = %d", $quantity, $quantity, $id ) );

		/**
		 * Fires when seats are given back (cancellation, refund, expired hold).
		 * The waitlist listens here to fill them.
		 *
		 * @param int $id       Ticket type id.
		 * @param int $quantity Seats released.
		 */
		do_action( 'openevents/seats_released', $id, $quantity );
	}

	/**
	 * Recomputes `sold` from attendees (repairs drift after manual edits or imports).
	 */
	public function recount( int $id ): int {
		global $wpdb;
		$types     = Schema::ticket_types();
		$attendees = Schema::attendees();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attendees} WHERE ticket_type_id = %d AND status = 'going'", $id ) );
		$wpdb->update( $types, array( 'sold' => $count ), array( 'id' => $id ) );
		// phpcs:enable
		return $count;
	}
}
