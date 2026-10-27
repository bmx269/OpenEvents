<?php
/**
 * Schema and lifecycle.
 *
 * @package OpenEvents
 */

namespace OpenEvents;

use OpenEvents\Content\PostTypes;

/**
 * Creates and upgrades custom tables, and handles activation side effects.
 *
 * The schema check runs on every request (one autoloaded option read) so that
 * plugin updates, multisite sub-sites, and restored databases self-heal without
 * relying on the activation hook.
 */
final class Installer {

	public const DB_VERSION        = '2';
	public const DB_VERSION_OPTION = 'openevents_db_version';
	public const FLUSH_OPTION      = 'openevents_flush_rewrite';
	public const ERROR_OPTION      = 'openevents_schema_error';

	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 1 );
		add_action( 'init', array( self::class, 'maybe_flush_rewrite_rules' ), 99 );
		add_action( 'admin_notices', array( self::class, 'schema_notice' ) );
	}

	/**
	 * Schema problems are reported here instead of fataling the site. Queries
	 * against a missing table return no rows, so the front end degrades to
	 * "no events" rather than an error page.
	 */
	public static function schema_notice(): void {
		$error = get_option( self::ERROR_OPTION );
		if ( $error && current_user_can( 'manage_options' ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
				esc_html__( 'OpenEvents could not create its database table.', 'openevents' ),
				esc_html__( 'Events will not appear on calendars until this is fixed. It will retry automatically on the next page load.', 'openevents' ),
				esc_html( (string) $error )
			);
		}
	}

	public static function activate(): void {
		self::install_schema();
		update_option( self::FLUSH_OPTION, 1 );
		update_option( Admin\AdminApp::REDIRECT_OPTION, 1 );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( self::DB_VERSION_OPTION );
		if ( $installed !== self::DB_VERSION ) {
			self::install_schema();
			// v2 added denormalised status/post_type columns; existing rows need filling.
			if ( $installed && version_compare( (string) $installed, '2', '<' ) ) {
				add_action( 'wp_loaded', static fn() => Occurrences\Sync::instance()->rebuild_all() );
			}
		}
	}

	/**
	 * Flushes after post types are registered (priority 99), never during activation,
	 * because the CPTs do not exist yet when the activation hook runs.
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules();
		}
	}

	public static function occurrences_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oe_occurrences';
	}

	public static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::occurrences_table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one field per line.
		//
		// Indexes match the three read patterns, all filtered to published rows:
		// - month/week grids: start_local range (bounded by the longest duration), status_start_local
		// - "upcoming": end_utc >= now, status_end_utc
		// - "past" and feeds ordered by start: status_start_utc
		// post_type/status are copied from wp_posts so listings never join it.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  sequence int(10) unsigned NOT NULL DEFAULT 0,
  start_local datetime NOT NULL,
  end_local datetime NOT NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  timezone varchar(64) NOT NULL DEFAULT '',
  all_day tinyint(1) NOT NULL DEFAULT 0,
  hide_from_listings tinyint(1) NOT NULL DEFAULT 0,
  post_type varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY event_sequence (event_id,sequence),
  KEY status_start_local (status,start_local),
  KEY status_end_utc (status,end_utc),
  KEY status_start_utc (status,start_utc)
) {$charset};"
		);

		// Only record the version once the table really exists, so a failed install retries.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
			delete_option( self::ERROR_OPTION );
		} else {
			update_option( self::ERROR_OPTION, $wpdb->last_error ?: 'CREATE TABLE ' . $table . ' failed.', false );
		}
	}

	/**
	 * Removes everything the plugin created. Called from uninstall.php only when
	 * the site owner opted in.
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( array( PostTypes::EVENT, PostTypes::VENUE, PostTypes::ORGANIZER ) as $post_type ) {
			$ids = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term_id ) {
				wp_delete_term( $term_id, PostTypes::CATEGORY );
			}
		}

		// Event data on existing post types: the posts stay, our meta goes.
		foreach ( array_keys( Content\Meta::event_fields() ) as $key ) {
			delete_post_meta_by_key( $key );
		}

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::occurrences_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$options = array(
			Settings::OPTION,
			Settings::MODULES_OPTION,
			Settings::SETUP_OPTION,
			self::DB_VERSION_OPTION,
			self::FLUSH_OPTION,
			self::ERROR_OPTION,
			Occurrences\Sync::MAX_DURATION_OPTION,
			Admin\AdminApp::REDIRECT_OPTION,
			'openevents_module_versions',
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		/**
		 * Lets modules remove their own tables and options.
		 */
		do_action( 'openevents/uninstall' );
	}
}
