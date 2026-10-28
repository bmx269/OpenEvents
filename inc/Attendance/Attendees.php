<?php
/**
 * Attendee storage.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Reads and writes oe_attendees. Responses and orders are groups of rows (one
 * per person) sharing a group_uuid.
 */
final class Attendees {

	/**
	 * Inserts `count` people for one response or order line. The first row is
	 * the respondent; extra rows are their guests ("Guest of …") unless
	 * $same_name (tickets bought by one person keep the buyer's name).
	 *
	 * @param array<string, mixed> $data name, email, status, ticket_type_id, event_id, token_hash, ip_hash.
	 * @return string Group uuid.
	 */
	public function create_group( array $data, int $count, ?string $group_uuid = null, bool $same_name = false ): string {
		global $wpdb;
		$group = $group_uuid ?? wp_generate_uuid4();
		$now   = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 0; $i < max( 1, $count ); $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::attendees(),
				array(
					'uuid'           => wp_generate_uuid4(),
					'group_uuid'     => $group,
					'token_hash'     => (string) ( $data['token_hash'] ?? '' ),
					'order_id'       => (int) ( $data['order_id'] ?? 0 ),
					'ticket_type_id' => (int) $data['ticket_type_id'],
					'event_id'       => (int) $data['event_id'],
					'occurrence_id'  => (int) ( $data['occurrence_id'] ?? 0 ),
					'name'           => 0 === $i || $same_name ? (string) $data['name'] : sprintf(
						/* translators: %s: name of the person who RSVPed */
						__( 'Guest of %s', 'openevents' ),
						(string) $data['name']
					),
					'email'          => (string) $data['email'],
					'status'         => (string) $data['status'],
					'security_code'  => self::security_code(),
					'ip_hash'        => (string) ( $data['ip_hash'] ?? '' ),
					'created_utc'    => $now,
					'updated_utc'    => $now,
					'fields'         => '{}',
				)
			);
		}
		return $group;
	}

	/**
	 * @return Attendee[]
	 */
	public function group( string $group_uuid ): array {
		global $wpdb;
		$table = Schema::attendees();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE group_uuid = %s ORDER BY id", $group_uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( Attendee::class, 'from_row' ), (array) $rows );
	}

	public function delete_group( string $group_uuid ): void {
		global $wpdb;
		$wpdb->delete( Schema::attendees(), array( 'group_uuid' => $group_uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The respondent's existing (not cancelled) group for an RSVP, by email.
	 */
	public function find_group_by_email( int $ticket_type_id, string $email ): ?string {
		global $wpdb;
		$table = Schema::attendees();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$group = $wpdb->get_var( $wpdb->prepare( "SELECT group_uuid FROM {$table} WHERE ticket_type_id = %d AND email = %s AND status <> %s ORDER BY id DESC LIMIT 1", $ticket_type_id, $email, Attendee::CANCELLED ) );
		return $group ?: null;
	}

	public function find_group_by_token( string $token ): ?string {
		global $wpdb;
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$table = Schema::attendees();
		$group = $wpdb->get_var( $wpdb->prepare( "SELECT group_uuid FROM {$table} WHERE token_hash = %s LIMIT 1", self::hash_token( $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $group ?: null;
	}

	public function set_group_status( string $group_uuid, string $status ): void {
		global $wpdb;
		$wpdb->update( Schema::attendees(), array( 'status' => $status, 'updated_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'group_uuid' => $group_uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function set_checked_in( int $id, bool $checked_in ): bool {
		global $wpdb;
		return false !== $wpdb->update( Schema::attendees(), array( 'checked_in_utc' => $checked_in ? gmdate( 'Y-m-d H:i:s' ) : null ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function set_status( int $id, string $status ): bool {
		global $wpdb;
		return false !== $wpdb->update( Schema::attendees(), array( 'status' => $status, 'updated_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function get( int $id ): ?Attendee {
		global $wpdb;
		$table = Schema::attendees();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Attendee::from_row( $row ) : null;
	}

	/**
	 * Recent responses from one (hashed) IP, for rate limiting.
	 */
	public function count_recent_by_ip( string $ip_hash, int $seconds ): int {
		global $wpdb;
		if ( '' === $ip_hash ) {
			return 0;
		}
		$table = Schema::attendees();
		$since = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT group_uuid) FROM {$table} WHERE ip_hash = %s AND created_utc >= %s", $ip_hash, $since ) );
	}

	/**
	 * Paged, filterable list for the admin and REST.
	 *
	 * @param array<string, mixed> $args event_id, status, search, checked_in, per_page, page, orderby, order.
	 * @return array{items: Attendee[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'event_id'   => 0,
				'event_ids'  => array(),
				'status'     => '',
				'search'     => '',
				'checked_in' => null,
				'per_page'   => 50,
				'page'       => 1,
				'orderby'    => 'created_utc',
				'order'      => 'DESC',
			)
		);
		$table = Schema::attendees();
		$where = array( '1=1' );
		if ( $args['event_id'] ) {
			$where[] = $wpdb->prepare( 'event_id = %d', $args['event_id'] );
		}
		$ids = array_filter( array_map( 'absint', (array) $args['event_ids'] ) );
		if ( $ids ) {
			$where[] = 'event_id IN (' . implode( ',', $ids ) . ')';
		}
		if ( $args['status'] ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}
		if ( null !== $args['checked_in'] ) {
			$where[] = $args['checked_in'] ? 'checked_in_utc IS NOT NULL' : 'checked_in_utc IS NULL';
		}
		if ( '' !== (string) $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s OR security_code = %s)', $like, $like, (string) $args['search'] );
		}
		$orderby  = in_array( $args['orderby'], array( 'created_utc', 'name', 'email', 'status', 'checked_in_utc' ), true ) ? $args['orderby'] : 'created_utc';
		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql      = "FROM {$table} WHERE " . implode( ' AND ', $where );
		$per_page = (int) $args['per_page'];
		$limit    = $per_page > 0 ? $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, max( 0, ( (int) $args['page'] - 1 ) * $per_page ) ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = (array) $wpdb->get_results( "SELECT * {$sql} ORDER BY {$orderby} {$order}, id {$order} {$limit}" );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$sql}" );
		// phpcs:enable

		return array(
			'items' => array_map( array( Attendee::class, 'from_row' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Going / not going counts for an event in one query.
	 *
	 * @return array{going: int, not_going: int, checked_in: int}
	 */
	public function counts( int $event_id ): array {
		global $wpdb;
		$table = Schema::attendees();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'going') AS going, SUM(status = 'not_going') AS not_going, SUM(status = 'going' AND checked_in_utc IS NOT NULL) AS checked_in FROM {$table} WHERE event_id = %d", $event_id ) );
		return array(
			'going'      => (int) ( $row->going ?? 0 ),
			'not_going'  => (int) ( $row->not_going ?? 0 ),
			'checked_in' => (int) ( $row->checked_in ?? 0 ),
		);
	}

	public static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Short, unambiguous code for door check-in (no 0/O, 1/I).
	 */
	public static function security_code(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code     = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $code;
	}
}
