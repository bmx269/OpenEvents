<?php
/**
 * Order storage.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

final class Orders {

	/**
	 * @param array<string, mixed>                                     $data  Order columns.
	 * @param array<int, array{ticket_type_id: int, quantity: int, unit_price_minor: int}> $items Line items.
	 */
	public function create( array $data, array $items ): Order {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::orders(),
			array_merge(
				array(
					'uuid'        => wp_generate_uuid4(),
					'status'      => Order::PENDING,
					'created_utc' => $now,
					'updated_utc' => $now,
					'meta'        => '{}',
				),
				$data
			)
		);
		$order_id = (int) $wpdb->insert_id;
		foreach ( $items as $item ) {
			$wpdb->insert( Schema::order_items(), array_merge( $item, array( 'order_id' => $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $this->get( $order_id );
	}

	public function get( int $id ): ?Order {
		return $this->find_by( 'id', $id );
	}

	public function by_uuid( string $uuid ): ?Order {
		return $this->find_by( 'uuid', $uuid );
	}

	public function by_gateway_ref( string $gateway, string $ref ): ?Order {
		global $wpdb;
		if ( '' === $ref ) {
			return null;
		}
		$table = Schema::orders();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", $gateway, $ref ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Order::from_row( $row ) : null;
	}

	public function by_token( string $token ): ?Order {
		return strlen( $token ) < 20 ? null : $this->find_by( 'token_hash', Guard::hash_token( $token ) );
	}

	private function find_by( string $column, string|int $value ): ?Order {
		global $wpdb;
		$table = Schema::orders();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s LIMIT 1", (string) $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Order::from_row( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $data Columns to update.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$data['meta'] = (string) wp_json_encode( $data['meta'] );
		}
		$data['updated_utc'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Schema::orders(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Moves an order between statuses only if it is still in one of $from.
	 * Returns false when another request got there first, so completion,
	 * expiry, and refunds each happen exactly once.
	 *
	 * @param string[] $from Allowed current statuses.
	 */
	public function transition( int $id, array $from, string $to, array $extra = array() ): bool {
		global $wpdb;
		$table = Schema::orders();
		$in    = "'" . implode( "','", array_map( 'esc_sql', $from ) ) . "'";
		$sets  = array( $wpdb->prepare( 'status = %s', $to ), $wpdb->prepare( 'updated_utc = %s', gmdate( 'Y-m-d H:i:s' ) ) );
		foreach ( $extra as $column => $value ) {
			$sets[] = null === $value ? "{$column} = NULL" : $wpdb->prepare( "{$column} = %s", (string) $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE id = %d AND status IN ({$in})", $id ) );
	}

	/**
	 * @return array<int, object{ticket_type_id: string, quantity: string, unit_price_minor: string}>
	 */
	public function items( int $order_id ): array {
		global $wpdb;
		$table = Schema::order_items();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Pending orders whose hold has run out.
	 *
	 * @return int[]
	 */
	public function expired_ids( int $limit = 50 ): array {
		global $wpdb;
		$table = Schema::orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND expires_utc IS NOT NULL AND expires_utc < %s ORDER BY expires_utc LIMIT %d", Order::PENDING, gmdate( 'Y-m-d H:i:s' ), $limit ) ) );
	}

	/**
	 * Paged list for the admin.
	 *
	 * @param array<string, mixed> $args event_id, status, search, per_page, page.
	 * @return array{items: Order[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'event_id' => 0,
				'status'   => '',
				'search'   => '',
				'per_page' => 50,
				'page'     => 1,
			)
		);
		$table = Schema::orders();
		$where = array( '1=1' );
		if ( $args['event_id'] ) {
			$where[] = $wpdb->prepare( 'event_id = %d', $args['event_id'] );
		}
		if ( $args['status'] ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}
		if ( '' !== (string) $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s OR uuid = %s OR gateway_ref = %s)', $like, $like, (string) $args['search'], (string) $args['search'] );
		}
		$sql   = "FROM {$table} WHERE " . implode( ' AND ', $where );
		$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', max( 1, (int) $args['per_page'] ), max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = (array) $wpdb->get_results( "SELECT * {$sql} ORDER BY id DESC {$limit}" );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$sql}" );
		// phpcs:enable
		return array(
			'items' => array_map( array( Order::class, 'from_row' ), $rows ),
			'total' => $total,
		);
	}
}
