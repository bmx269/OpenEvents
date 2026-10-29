<?php
/**
 * Source-to-target ids for imports.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Import;

/**
 * Remembers what an import created (`oe_import_map`: source kind and id →
 * target id), so running it again skips what's done, and a rollback removes
 * exactly what it added.
 */
final class ImportMap {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_import_map';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
  source varchar(40) NOT NULL,
  source_id bigint(20) unsigned NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (source,source_id),
  KEY target (source,target_id)
) {$wpdb->get_charset_collate()};"
		);
	}

	public static function get( string $source, int $source_id ): ?int {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT target_id FROM ' . self::table() . ' WHERE source = %s AND source_id = %d', $source, $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return null === $id ? null : (int) $id;
	}

	/**
	 * @return array<int, int> Source id => target id.
	 */
	public static function all( string $source ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_id, target_id FROM ' . self::table() . ' WHERE source = %s', $source ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->source_id ] = (int) $row->target_id;
		}
		return $out;
	}

	public static function set( string $source, int $source_id, int $target_id ): void {
		global $wpdb;
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'source'    => $source,
				'source_id' => $source_id,
				'target_id' => $target_id,
			)
		);
	}

	public static function forget( string $source ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'source' => $source ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
