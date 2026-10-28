<?php
/**
 * Ticket type storage.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Reads ticket types and reserves seats atomically.
 */
final class TicketTypes {

	/**
	 * Creates or updates the row for (event, key), keeping `sold`.
	 *
	 * @param array<string, mixed> $data Columns to write.
	 */
	public function upsert( int $event_id, string $type_key, array $data ): int {
		global $wpdb;
		$table    = Schema::ticket_types();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d AND type_key = %s", $event_id, $type_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

	public function find( int $event_id, string $type_key ): ?TicketType {
		global $wpdb;
		$table = Schema::ticket_types();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND type_key = %s", $event_id, $type_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? TicketType::from_row( $row ) : null;
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
