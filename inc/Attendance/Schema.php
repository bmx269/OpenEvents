<?php
/**
 * Attendance tables shared by RSVP and Tickets.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Creates oe_ticket_types and oe_attendees. An RSVP is a free ticket type, so
 * attendee lists, emails, exports, and check-in are written once for both.
 */
final class Schema {

	public const VERSION        = '6';
	public const VERSION_OPTION = 'openevents_attendance_db_version';

	public static function ticket_types(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_ticket_types';
	}

	public static function attendees(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_attendees';
	}

	public static function orders(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_orders';
	}

	public static function waitlist(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_waitlist';
	}

	public static function order_items(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_order_items';
	}

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		// Ticket types are derived from event meta (like occurrences), keyed by
		// (event_id, type_key, sequence) so edits update rows in place and `sold`
		// survives. Sequence 0 is the event's own date (and the only one for a
		// single event or a series pass); a repeating event's other dates get
		// their own rows, copied from it on first use, so each date has its own
		// capacity. Sequences are the occurrences' (see Recurrence\Module).
		// occurrence_id is unused.
		dbDelta(
			'CREATE TABLE ' . self::ticket_types() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type_key varchar(40) NOT NULL,
  sequence int(10) unsigned NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL DEFAULT 'rsvp',
  name varchar(200) NOT NULL DEFAULT '',
  description text NOT NULL,
  price_minor int(10) unsigned NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  capacity int(10) unsigned DEFAULT NULL,
  sold int(10) unsigned NOT NULL DEFAULT 0,
  sale_start_utc datetime DEFAULT NULL,
  sale_end_utc datetime DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  settings longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_type_date (event_id,type_key,sequence),
  KEY event_status (event_id,status)
) {$charset};"
		);
		// Before schema 5 a type was unique per event, which would refuse per-date
		// rows; dbDelta adds keys but never drops them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', self::ticket_types(), 'event_type' ) ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX event_type', self::ticket_types() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		// One row per person. group_uuid ties a response (or order) together;
		// token_hash authorises the private "change or cancel" link.
		dbDelta(
			'CREATE TABLE ' . self::attendees() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  group_uuid char(36) NOT NULL,
  token_hash char(64) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ticket_type_id bigint(20) unsigned NOT NULL,
  event_id bigint(20) unsigned NOT NULL,
  occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(200) NOT NULL DEFAULT '',
  email varchar(200) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'going',
  security_code varchar(16) NOT NULL DEFAULT '',
  checked_in_utc datetime DEFAULT NULL,
  ip_hash char(64) NOT NULL DEFAULT '',
  created_utc datetime NOT NULL,
  updated_utc datetime NOT NULL,
  fields longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY group_uuid (group_uuid),
  KEY event_status (event_id,status),
  KEY ticket_type (ticket_type_id),
  KEY email (email(100)),
  KEY ip_created (ip_hash,created_utc),
  KEY security_code (security_code)
) {$charset};"
		);

		// Orders hold seats while payment happens elsewhere; expires_utc lets a
		// sweep release abandoned holds. Order state never lives in transients.
		dbDelta(
			'CREATE TABLE ' . self::orders() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  event_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  gateway varchar(30) NOT NULL DEFAULT '',
  gateway_ref varchar(191) NOT NULL DEFAULT '',
  gateway_txn varchar(191) NOT NULL DEFAULT '',
  name varchar(200) NOT NULL DEFAULT '',
  email varchar(200) NOT NULL DEFAULT '',
  total_minor int(10) unsigned NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  token_hash char(64) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  expires_utc datetime DEFAULT NULL,
  created_utc datetime NOT NULL,
  updated_utc datetime NOT NULL,
  meta longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY status_expires (status,expires_utc),
  KEY gateway_ref (gateway_ref),
  KEY event_status (event_id,status),
  KEY email (email(100))
) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::order_items() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  ticket_type_id bigint(20) unsigned NOT NULL,
  quantity int(10) unsigned NOT NULL,
  unit_price_minor int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) {$charset};"
		);

		// First come, first served: the queue is ordered by id. An "offered"
		// entry holds seats (counted in ticket_types.sold) until offer_expires_utc.
		// `people` keeps an RSVP entry's guest details and answers (JSON) so
		// promotion creates attendees exactly as a direct response would.
		dbDelta(
			'CREATE TABLE ' . self::waitlist() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  ticket_type_id bigint(20) unsigned NOT NULL,
  event_id bigint(20) unsigned NOT NULL,
  name varchar(200) NOT NULL DEFAULT '',
  email varchar(200) NOT NULL DEFAULT '',
  quantity int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'waiting',
  token_hash char(64) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  offer_expires_utc datetime DEFAULT NULL,
  created_utc datetime NOT NULL,
  updated_utc datetime NOT NULL,
  people longtext,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY type_status (ticket_type_id,status,id),
  KEY status_expires (status,offer_expires_utc),
  KEY token_hash (token_hash),
  KEY email (email(100)),
  KEY ip_created (ip_hash,created_utc)
) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function uninstall(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::waitlist() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::order_items() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::orders() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::attendees() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::ticket_types() );
		// phpcs:enable
		delete_option( self::VERSION_OPTION );
	}
}
