<?php
/**
 * WordPress privacy tools for attendance data.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Hooks attendees, orders, and waitlist entries into Tools → Export Personal
 * Data and Erase Personal Data, and suggests privacy policy text.
 *
 * Erasing anonymises attendees and orders instead of deleting them: the rows
 * keep seat counts and order totals right, but lose the name, email, answers,
 * and IP hash. Waitlist entries hold nothing else of value and are deleted.
 *
 * Registered by core, so requests are answered even with RSVP and Tickets
 * switched off.
 */
final class Privacy {

	private const PAGE = 100;

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'policy' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function exporters( array $exporters ): array {
		$exporters['openevents-attendance'] = array(
			'exporter_friendly_name' => __( 'Event attendance (OpenEvents)', 'openevents' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function erasers( array $erasers ): array {
		$erasers['openevents-attendance'] = array(
			'eraser_friendly_name' => __( 'Event attendance (OpenEvents)', 'openevents' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports attendees, then orders, then waitlist entries for an email, a page
	 * at a time (the page counts across all three).
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		if ( ! self::installed() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$rows = self::rows( $email, $page );
		$data = array();
		$types = array();
		foreach ( $rows as $row ) {
			$title = html_entity_decode( (string) get_the_title( (int) $row->event_id ), ENT_QUOTES ) ?: sprintf( '#%d', (int) $row->event_id );
			if ( 'attendee' === $row->kind ) {
				$attendee = Attendee::from_row( $row );
				$type     = ( new TicketTypes() )->get( $attendee->ticket_type_id );
				$fields   = array(
					array( 'name' => __( 'Event', 'openevents' ), 'value' => $title ),
					array( 'name' => __( 'Ticket', 'openevents' ), 'value' => $type ? $type->name : '' ),
					array( 'name' => __( 'Name', 'openevents' ), 'value' => $attendee->name ),
					array( 'name' => __( 'Email', 'openevents' ), 'value' => $attendee->email ),
					array( 'name' => __( 'Status', 'openevents' ), 'value' => $attendee->status ),
					array( 'name' => __( 'Check-in code', 'openevents' ), 'value' => $attendee->security_code ),
					array( 'name' => __( 'Checked in (UTC)', 'openevents' ), 'value' => (string) $attendee->checked_in_utc ),
					array( 'name' => __( 'Created (UTC)', 'openevents' ), 'value' => $attendee->created_utc ),
				);
				foreach ( Fields::answers( $attendee, $types ) as $answer ) {
					$fields[] = array(
						'name'  => $answer['label'],
						'value' => $answer['value'],
					);
				}
				$data[] = array(
					'group_id'    => 'openevents-attendees',
					'group_label' => __( 'Event attendees', 'openevents' ),
					'item_id'     => 'openevents-attendee-' . $attendee->id,
					'data'        => $fields,
				);
			} elseif ( 'order' === $row->kind ) {
				$data[] = array(
					'group_id'    => 'openevents-orders',
					'group_label' => __( 'Event orders', 'openevents' ),
					'item_id'     => 'openevents-order-' . (int) $row->id,
					'data'        => array(
						array( 'name' => __( 'Event', 'openevents' ), 'value' => $title ),
						array( 'name' => __( 'Name', 'openevents' ), 'value' => (string) $row->name ),
						array( 'name' => __( 'Email', 'openevents' ), 'value' => (string) $row->email ),
						array( 'name' => __( 'Status', 'openevents' ), 'value' => (string) $row->status ),
						array( 'name' => __( 'Total', 'openevents' ), 'value' => Money::format( (int) $row->total_minor, (string) $row->currency ) ),
						array( 'name' => __( 'Payment method', 'openevents' ), 'value' => (string) $row->gateway ),
						array( 'name' => __( 'Created (UTC)', 'openevents' ), 'value' => (string) $row->created_utc ),
					),
				);
			} else {
				$data[] = array(
					'group_id'    => 'openevents-waitlist',
					'group_label' => __( 'Event waitlists', 'openevents' ),
					'item_id'     => 'openevents-waitlist-' . (int) $row->id,
					'data'        => array(
						array( 'name' => __( 'Event', 'openevents' ), 'value' => $title ),
						array( 'name' => __( 'Name', 'openevents' ), 'value' => (string) $row->name ),
						array( 'name' => __( 'Email', 'openevents' ), 'value' => (string) $row->email ),
						array( 'name' => __( 'Status', 'openevents' ), 'value' => (string) $row->status ),
						array( 'name' => __( 'People', 'openevents' ), 'value' => (string) $row->quantity ),
						array( 'name' => __( 'Joined (UTC)', 'openevents' ), 'value' => (string) $row->created_utc ),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE,
		);
	}

	/**
	 * Anonymises attendees and orders and deletes waitlist entries for an email.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$email  = trim( $email );
		if ( ! self::installed() || '' === $email ) {
			return $result;
		}
		$now  = gmdate( 'Y-m-d H:i:s' );
		$anon = array(
			'name'       => wp_privacy_anonymize_data( 'text', '' ),
			'email'      => wp_privacy_anonymize_data( 'email', $email ),
			'ip_hash'    => '',
			'token_hash' => '',
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$attendees = (int) $wpdb->update( Schema::attendees(), $anon + array( 'fields' => '{}', 'updated_utc' => $now ), array( 'email' => $email ) );
		$orders    = (int) $wpdb->update( Schema::orders(), $anon + array( 'updated_utc' => $now ), array( 'email' => $email ) );
		$waitlist  = (int) $wpdb->delete( Schema::waitlist(), array( 'email' => $email ) );
		// phpcs:enable

		if ( $attendees || $orders || $waitlist ) {
			$result['items_removed'] = true;
			if ( $attendees || $orders ) {
				$result['messages'][] = __( 'Event attendance and orders were anonymised rather than deleted, so seat counts and order totals stay correct.', 'openevents' );
			}
		}
		return $result;
	}

	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'OpenEvents',
			'<p>' . esc_html__( 'When you RSVP, buy tickets, or join a waitlist for an event, we collect your name and email address, and the answers to any questions the event asks (for example a phone number or a consent). We use them to manage your place at the event and to email you about it. Payments are handled by the payment provider you choose; we don’t receive card details.', 'openevents' ) . '</p>'
			. '<p>' . esc_html__( 'We keep this information with the event. When an event is deleted, its attendee information is deleted too. You can ask us for a copy of your information or to erase it.', 'openevents' ) . '</p>'
		);
	}

	/**
	 * One page of an email's attendees, orders, and waitlist entries, tagged by kind.
	 *
	 * @return object[]
	 */
	private static function rows( string $email, int $page ): array {
		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * self::PAGE );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attendees = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::attendees() . ' WHERE email = %s ORDER BY id', $email ) );
		$orders    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::orders() . ' WHERE email = %s ORDER BY id', $email ) );
		$waitlist  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::waitlist() . ' WHERE email = %s ORDER BY id', $email ) );
		// phpcs:enable
		foreach ( $attendees as $row ) {
			$row->kind = 'attendee';
		}
		foreach ( $orders as $row ) {
			$row->kind = 'order';
		}
		foreach ( $waitlist as $row ) {
			$row->kind = 'waitlist';
		}
		return array_slice( array_merge( $attendees, $orders, $waitlist ), $offset, self::PAGE );
	}

	private static function installed(): bool {
		return (bool) get_option( Schema::VERSION_OPTION );
	}
}
