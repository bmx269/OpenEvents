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
 * and IP hash. Waitlist entries (including any guests' details and answers)
 * hold nothing else of value and are deleted.
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
			} elseif ( 'waitlist' === $row->kind ) {
				$entry  = WaitlistEntry::from_row( $row );
				$fields = array(
					array( 'name' => __( 'Event', 'openevents' ), 'value' => $title ),
					array( 'name' => __( 'Name', 'openevents' ), 'value' => $entry->name ),
					array( 'name' => __( 'Email', 'openevents' ), 'value' => $entry->email ),
					array( 'name' => __( 'Status', 'openevents' ), 'value' => $entry->status ),
					array( 'name' => __( 'People', 'openevents' ), 'value' => (string) $entry->quantity ),
					array( 'name' => __( 'Joined (UTC)', 'openevents' ), 'value' => $entry->created_utc ),
				);
				if ( $entry->people ) {
					if ( ! array_key_exists( $entry->ticket_type_id, $types ) ) {
						$types[ $entry->ticket_type_id ] = ( new TicketTypes() )->get( $entry->ticket_type_id );
					}
					foreach ( $entry->people as $i => $person ) {
						$who = Fields::person_label( (int) $i, true );
						if ( $i > 0 ) {
							foreach ( array( 'name' => __( 'Name', 'openevents' ), 'email' => __( 'Email', 'openevents' ) ) as $key => $label ) {
								if ( '' !== (string) ( $person[ $key ] ?? '' ) ) {
									/* translators: 1: who, e.g. "Guest 2", 2: what, e.g. "Name" */
									$fields[] = array( 'name' => sprintf( __( '%1$s: %2$s', 'openevents' ), $who, $label ), 'value' => (string) $person[ $key ] );
								}
							}
						}
						foreach ( Fields::labelled( (array) ( $person['fields'] ?? array() ), $types[ $entry->ticket_type_id ] ) as $answer ) {
							/* translators: 1: who, e.g. "You" or "Guest 2", 2: question */
							$fields[] = array( 'name' => sprintf( __( '%1$s: %2$s', 'openevents' ), $who, $answer['label'] ), 'value' => $answer['value'] );
						}
					}
				}
				$data[] = array(
					'group_id'    => 'openevents-waitlist',
					'group_label' => __( 'Event waitlists', 'openevents' ),
					'item_id'     => 'openevents-waitlist-' . $entry->id,
					'data'        => $fields,
				);
			} else {
				// A guest on someone else's waitlist entry: only their own part.
				$entry  = WaitlistEntry::from_row( $row );
				$person = $entry->people[ $row->guest ];
				$fields = array(
					array( 'name' => __( 'Event', 'openevents' ), 'value' => $title ),
					array( 'name' => __( 'Name', 'openevents' ), 'value' => (string) ( $person['name'] ?? '' ) ),
					array( 'name' => __( 'Email', 'openevents' ), 'value' => (string) ( $person['email'] ?? '' ) ),
					array( 'name' => __( 'Status', 'openevents' ), 'value' => $entry->status ),
					array( 'name' => __( 'Joined (UTC)', 'openevents' ), 'value' => $entry->created_utc ),
				);
				if ( ! array_key_exists( $entry->ticket_type_id, $types ) ) {
					$types[ $entry->ticket_type_id ] = ( new TicketTypes() )->get( $entry->ticket_type_id );
				}
				foreach ( Fields::labelled( (array) ( $person['fields'] ?? array() ), $types[ $entry->ticket_type_id ] ) as $answer ) {
					$fields[] = array(
						'name'  => $answer['label'],
						'value' => $answer['value'],
					);
				}
				$data[] = array(
					'group_id'    => 'openevents-waitlist',
					'group_label' => __( 'Event waitlists', 'openevents' ),
					'item_id'     => 'openevents-waitlist-' . $entry->id . '-guest-' . (int) $row->guest,
					'data'        => $fields,
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
		foreach ( self::guest_entries( $email ) as $match ) {
			$people = $match['entry']->people;
			foreach ( $match['guests'] as $i ) {
				$people[ $i ] = array(
					'name'   => '',
					'email'  => '',
					'fields' => array(),
				);
			}
			$waitlist += (int) $wpdb->update( Schema::waitlist(), array( 'people' => (string) wp_json_encode( $people ), 'updated_utc' => $now ), array( 'id' => $match['entry']->id ) );
		}
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
		$guests = array();
		foreach ( self::guest_entries( $email ) as $match ) {
			foreach ( $match['guests'] as $i ) {
				$guests[] = (object) array_merge(
					(array) $match['row'],
					array(
						'kind'  => 'waitlist_guest',
						'guest' => $i,
					)
				);
			}
		}
		return array_slice( array_merge( $attendees, $orders, $waitlist, $guests ), $offset, self::PAGE );
	}

	/**
	 * Waitlist entries that list $email as a guest (not the person who
	 * joined), with the guests' places in the party. Guests' details are kept
	 * as JSON, so the LIKE only narrows the search; the decoded list decides.
	 *
	 * @return array<int, array{row: object, entry: WaitlistEntry, guests: int[]}>
	 */
	private static function guest_entries( string $email ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"email":' . wp_json_encode( $email ) ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE people LIKE %s AND email <> %s ORDER BY id', Schema::waitlist(), $like, $email ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$entry  = WaitlistEntry::from_row( $row );
			$guests = array();
			foreach ( $entry->people as $i => $person ) {
				if ( $i > 0 && 0 === strcasecmp( (string) ( $person['email'] ?? '' ), $email ) ) {
					$guests[] = (int) $i;
				}
			}
			if ( $guests ) {
				$out[] = array(
					'row'    => $row,
					'entry'  => $entry,
					'guests' => $guests,
				);
			}
		}
		return $out;
	}

	private static function installed(): bool {
		return (bool) get_option( Schema::VERSION_OPTION );
	}
}
