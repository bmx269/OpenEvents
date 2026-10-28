<?php
/**
 * Orders admin screen.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Content\PostTypes;

/**
 * Events → Orders, rendered by the attendance admin entry (DataViews).
 */
final class Admin {

	public const SLUG = 'openevents-orders';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'oversold_notice' ) );
	}

	public function menu(): void {
		add_submenu_page( PostTypes::menu_parent( openevents()->settings() ), __( 'Orders', 'openevents' ), __( 'Orders', 'openevents' ), 'edit_posts', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			\OpenEvents\Modules\Rsvp\Admin::enqueue_bundle();
		}
	}

	public function render(): void {
		printf(
			'<div id="openevents-attendance" class="openevents-admin" data-screen="orders" data-events="%s" data-event="%d"></div>',
			esc_attr( (string) wp_json_encode( \OpenEvents\Modules\Rsvp\Admin::events() ) ),
			absint( $_GET['event'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Late payments that landed after their seats were resold need a human.
	 */
	public function oversold_notice(): void {
		global $wpdb;
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$table = \OpenEvents\Attendance\Schema::orders();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed' AND meta LIKE '%\"oversold\":true%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				/* translators: %d: number of orders */
				esc_html( sprintf( _n( '%d paid order arrived after its tickets were resold, so the event may be over capacity.', '%d paid orders arrived after their tickets were resold, so events may be over capacity.', $count, 'openevents' ), $count ) ),
				esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ),
				esc_html__( 'Review orders', 'openevents' )
			);
		}
	}
}
