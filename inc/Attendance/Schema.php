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

	public const VERSION        = '1';
	public const VERSION_OPTION = 'openevents_attendance_db_version';

	public static function ticket_types(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_ticket_types';
	}

	public static function attendees(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_attendees';
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
		// (event_id, type_key) so edits update rows in place and `sold` survives.
		dbDelta(
			'CREATE TABLE ' . self::ticket_types() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type_key varchar(40) NOT NULL,
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
  UNIQUE KEY event_type (event_id,type_key),
  KEY event_status (event_id,status)
) {$charset};"
		);

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
  KEY ip_created (ip_hash,created_utc)
) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function uninstall(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::attendees() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::ticket_types() );
		// phpcs:enable
		delete_option( self::VERSION_OPTION );
	}
}
