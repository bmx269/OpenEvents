<?php
/**
 * Attendees admin screen and CSV export.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Rsvp;

use OpenEvents\Attendance\Attendees;
use OpenEvents\Attendance\Fields;
use OpenEvents\Content\PostTypes;

/**
 * Events → Attendees: a DataViews list (rendered by the admin app) backed by
 * the attendees REST route, plus a CSV export.
 */
final class Admin {

	public const SLUG = 'openevents-attendees';

	public function __construct( private Service $service ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_openevents_attendees_csv', array( $this, 'export_csv' ) );
	}

	public function menu(): void {
		add_submenu_page(
			PostTypes::menu_parent( openevents()->settings() ),
			__( 'Attendees', 'openevents' ),
			__( 'Attendees', 'openevents' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			self::enqueue_bundle();
		}
	}

	/**
	 * Loads the attendance admin bundle (attendee and order screens).
	 */
	public static function enqueue_bundle(): void {
		$asset_file = \OpenEvents\PLUGIN_DIR . '/build/admin/attendance.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( 'openevents-attendance', plugins_url( 'build/admin/attendance.js', \OpenEvents\PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'openevents-attendance', 'openevents' );
		wp_enqueue_style( 'openevents-attendance', plugins_url( 'build/admin/attendance.css', \OpenEvents\PLUGIN_FILE ), array( 'wp-components' ), $asset['version'] );
	}

	public function render(): void {
		printf(
			'<div id="openevents-attendance" class="openevents-admin" data-screen="attendees" data-csv="%s" data-events="%s" data-event="%d"><noscript>%s</noscript></div>',
			esc_attr( wp_nonce_url( admin_url( 'admin-post.php?action=openevents_attendees_csv' ), 'openevents_attendees_csv' ) ),
			esc_attr( (string) wp_json_encode( self::events() ) ),
			absint( $_GET['event'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			esc_html__( 'The attendee list needs JavaScript. You can still download it as CSV.', 'openevents' )
		);
	}

	/**
	 * Events that take (or took) RSVPs and the current user can edit, newest first.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public static function events(): array {
		global $wpdb;
		$table = \OpenEvents\Attendance\Schema::ticket_types();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT event_id FROM {$table} ORDER BY event_id DESC LIMIT 500" ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		$events = array();
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				$events[] = array(
					'value' => $id,
					'label' => html_entity_decode( get_the_title( $id ), ENT_QUOTES ),
				);
			}
		}
		return $events;
	}

	/**
	 * Streams attendees as CSV, in batches so memory stays flat for large events.
	 */
	public function export_csv(): void {
		check_admin_referer( 'openevents_attendees_csv' );
		$event_id = absint( $_GET['event'] ?? 0 );
		if ( $event_id ? ! current_user_can( 'edit_post', $event_id ) : ! current_user_can( Rest::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to export these attendees.', 'openevents' ), 403 );
		}
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );

		$filename = 'attendees' . ( $event_id ? '-' . get_post_field( 'post_name', $event_id ) : '' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		$repo  = new Attendees();
		$args  = array(
			'event_id' => $event_id,
			'status'   => $status,
			'per_page' => 500,
			'orderby'  => 'created_utc',
			'order'    => 'ASC',
		);
		$types = array();

		// First pass: one column per attendee question that has answers.
		$columns = array();
		$page    = 1;
		do {
			$batch = $repo->query( $args + array( 'page' => $page ) );
			foreach ( $batch['items'] as $a ) {
				foreach ( Fields::answers( $a, $types ) as $answer ) {
					$columns[ $answer['key'] ] ??= $answer['label'];
				}
			}
			++$page;
		} while ( count( $batch['items'] ) === 500 );

		fputcsv( $out, array_merge( array( 'Name', 'Email', 'Event', 'Status', 'Checked in', 'Check-in code', 'Response group', 'Created (UTC)' ), array_map( array( self::class, 'safe_cell' ), array_values( $columns ) ) ) );

		$page = 1;
		do {
			$batch = $repo->query( $args + array( 'page' => $page ) );
			foreach ( $batch['items'] as $a ) {
				$answers = array_fill_keys( array_keys( $columns ), '' );
				foreach ( $a->fields as $key => $value ) {
					if ( isset( $answers[ $key ] ) ) {
						$answers[ $key ] = self::safe_cell( Fields::display( $value ) );
					}
				}
				fputcsv(
					$out,
					array_merge(
						array(
							self::safe_cell( $a->name ),
							self::safe_cell( $a->email ),
							self::safe_cell( html_entity_decode( get_the_title( $a->event_id ), ENT_QUOTES ) ),
							$a->status,
							$a->checked_in_utc ?? '',
							$a->security_code,
							$a->group_uuid,
							$a->created_utc,
						),
						array_values( $answers )
					)
				);
			}
			++$page;
		} while ( count( $batch['items'] ) === 500 );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralises spreadsheet formula injection (cells starting with = + - @).
	 */
	public static function safe_cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}
}
