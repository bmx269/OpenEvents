<?php
/**
 * Waitlist storage.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

final class Waitlist {

	/**
	 * @param array<string, mixed> $data Columns.
	 */
	public function add( array $data ): WaitlistEntry {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::waitlist(),
			array_merge(
				array(
					'uuid'        => wp_generate_uuid4(),
					'status'      => WaitlistEntry::WAITING,
					'created_utc' => $now,
					'updated_utc' => $now,
				),
				$data
			)
		);
		return $this->get( (int) $wpdb->insert_id );
	}

	public function get( int $id ): ?WaitlistEntry {
		return $this->find_by( 'id', $id );
	}

	public function by_token( string $token ): ?WaitlistEntry {
		return strlen( $token ) < 20 ? null : $this->find_by( 'token_hash', Guard::hash_token( $token ) );
	}

	private function find_by( string $column, string|int $value ): ?WaitlistEntry {
		global $wpdb;
		$table = Schema::waitlist();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s LIMIT 1", (string) $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? WaitlistEntry::from_row( $row ) : null;
	}

	/**
	 * Someone already waiting (or holding an offer) for this type with this email.
	 */
	public function active_for_email( int $ticket_type_id, string $email ): ?WaitlistEntry {
		global $wpdb;
		$table = Schema::waitlist();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_type_id = %d AND email = %s AND status IN ('waiting','offered') ORDER BY id LIMIT 1", $ticket_type_id, $email ) );
		return $row ? WaitlistEntry::from_row( $row ) : null;
	}

	/**
	 * Waiting entries for a ticket type, oldest first.
	 *
	 * @return WaitlistEntry[]
	 */
	public function queue( int $ticket_type_id, int $limit = 100 ): array {
		global $wpdb;
		$table = Schema::waitlist();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_type_id = %d AND status = 'waiting' ORDER BY id LIMIT %d", $ticket_type_id, $limit ) );
		return array_map( array( WaitlistEntry::class, 'from_row' ), $rows );
	}

	/**
	 * 1-based place in line among waiting entries.
	 */
	public function position( WaitlistEntry $entry ): int {
		global $wpdb;
		$table = Schema::waitlist();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ticket_type_id = %d AND status = 'waiting' AND id < %d", $entry->ticket_type_id, $entry->id ) );
	}

	/**
	 * People waiting (sum of quantities) for one ticket type.
	 */
	public function waiting_count( int $ticket_type_id ): int {
		global $wpdb;
		$table = Schema::waitlist();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity), 0) FROM {$table} WHERE ticket_type_id = %d AND status = 'waiting'", $ticket_type_id ) );
	}

	/**
	 * Conditional status change; false if someone else changed it first.
	 *
	 * @param string[]             $from  Allowed current statuses.
	 * @param array<string, mixed> $extra Other columns to set.
	 */
	public function transition( int $id, array $from, string $to, array $extra = array() ): bool {
		global $wpdb;
		$table = Schema::waitlist();
		$in    = "'" . implode( "','", array_map( 'esc_sql', $from ) ) . "'";
		$sets  = array( $wpdb->prepare( 'status = %s', $to ), $wpdb->prepare( 'updated_utc = %s', gmdate( 'Y-m-d H:i:s' ) ) );
		foreach ( $extra as $column => $value ) {
			$sets[] = null === $value ? "{$column} = NULL" : $wpdb->prepare( "{$column} = %s", (string) $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE id = %d AND status IN ({$in})", $id ) );
	}

	/**
	 * @return int[] Offered entries whose offer has run out.
	 */
	public function expired_offer_ids( int $limit = 100 ): array {
		global $wpdb;
		$table = Schema::waitlist();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'offered' AND offer_expires_utc < %s ORDER BY offer_expires_utc LIMIT %d", gmdate( 'Y-m-d H:i:s' ), $limit ) ) );
	}

	/**
	 * Admin list.
	 *
	 * @param array<string, mixed> $args event_id, status, search, per_page, page.
	 * @return array{items: WaitlistEntry[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'event_id' => 0, 'status' => '', 'search' => '', 'per_page' => 50, 'page' => 1 ) );
		$table = Schema::waitlist();
		$where = array( '1=1' );
		if ( $args['event_id'] ) {
			$where[] = $wpdb->prepare( 'event_id = %d', $args['event_id'] );
		}
		if ( $args['status'] ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}
		if ( '' !== (string) $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s)', $like, $like );
		}
		$sql   = "FROM {$table} WHERE " . implode( ' AND ', $where );
		$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', max( 1, (int) $args['per_page'] ), max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = (array) $wpdb->get_results( "SELECT * {$sql} ORDER BY id ASC {$limit}" );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$sql}" );
		// phpcs:enable
		return array(
			'items' => array_map( array( WaitlistEntry::class, 'from_row' ), $rows ),
			'total' => $total,
		);
	}
}
