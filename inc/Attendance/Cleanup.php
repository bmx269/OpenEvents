<?php
/**
 * Deletes attendance data with the event it belongs to.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * When an event (or a series selling passes) is deleted permanently, its
 * attendees, orders, ticket types, and waitlist entries go with it. They hold
 * names, emails, and answers, and with no event they'd be invisible but
 * still on file (Event Tickets leaves them behind; WVML had 19,000 such
 * records). Trashing keeps everything, so a restored event is whole.
 *
 * Registered by core, so it runs even when RSVP and Tickets are switched
 * off but their tables still hold data.
 */
final class Cleanup {

	public static function register(): void {
		add_action( 'before_delete_post', array( self::class, 'delete_for' ) );
	}

	/**
	 * @param int $post_id Post being deleted (any type; nothing happens unless rows reference it).
	 */
	public static function delete_for( int $post_id ): void {
		global $wpdb;
		if ( ! get_option( Schema::VERSION_OPTION ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$post_id = (int) $post_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orders = Schema::orders();
		$counts = array(
			'attendees'    => (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::attendees() . ' WHERE event_id = %d', $post_id ) ),
			'waitlist'     => (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::waitlist() . ' WHERE event_id = %d', $post_id ) ),
			'order_items'  => (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::order_items() . " WHERE order_id IN (SELECT id FROM {$orders} WHERE event_id = %d)", $post_id ) ),
			'orders'       => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$orders} WHERE event_id = %d", $post_id ) ),
			'ticket_types' => (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::ticket_types() . ' WHERE event_id = %d', $post_id ) ),
		);
		// phpcs:enable

		if ( array_sum( $counts ) ) {
			/**
			 * Fires after an event's attendance data was deleted with it.
			 *
			 * @param int               $post_id Deleted post id.
			 * @param array<string,int> $counts  Rows deleted per table.
			 */
			do_action( 'openevents/attendance/deleted', $post_id, $counts );
		}
	}
}
