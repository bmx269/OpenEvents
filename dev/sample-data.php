<?php
/**
 * Development fixture: categories, venues, organizers, and events around the
 * current month, plus one existing Post turned into an event. Idempotent.
 *
 * Usage: wp eval-file dev/sample-data.php (or the Playground blueprint).
 *
 * @package OpenEvents
 */

if ( get_option( 'openevents_sample_data' ) ) {
	return;
}

// Make Posts event-capable alongside the Events post type, and finish setup.
update_option(
	'openevents_settings',
	OpenEvents\Settings::sanitize( array_merge( OpenEvents\Settings::defaults(), array( 'event_post_types' => array( 'post' ), 'append_details' => true ) ) )
);
update_option( 'openevents_setup_complete', 1 );

$cats = array();
foreach ( array( 'Music' => '#7c3aed', 'Workshops' => '#f59e0b', 'Community' => '#059669' ) as $name => $color ) {
	$term = wp_insert_term( $name, 'oe_event_category' );
	if ( ! is_wp_error( $term ) ) {
		$cats[ $name ] = $term['term_id'];
		update_term_meta( $term['term_id'], 'oe_color', $color );
	}
}

$venue = wp_insert_post(
	array(
		'post_type'   => 'oe_venue',
		'post_status' => 'publish',
		'post_title'  => 'The Commodore Ballroom',
		'meta_input'  => array(
			'_oe_address'     => '868 Granville St',
			'_oe_city'        => 'Vancouver',
			'_oe_region'      => 'BC',
			'_oe_postal_code' => 'V6Z 1K3',
			'_oe_country'     => 'Canada',
			'_oe_lat'         => '49.2805',
			'_oe_lng'         => '-123.1207',
		),
	)
);
$organizer = wp_insert_post(
	array(
		'post_type'   => 'oe_organizer',
		'post_status' => 'publish',
		'post_title'  => 'Granville Arts Society',
		'meta_input'  => array( '_oe_email' => 'hello@example.com' ),
	)
);

$month = current_datetime()->modify( 'first day of this month' );
$make  = static function ( string $title, string $start, string $end, array $extra = array(), string $cat = 'Music', string $type = 'oe_event' ) use ( $cats, $venue, $organizer ) {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_excerpt' => 'A sample event for testing the calendar.',
			'post_content' => '<!-- wp:paragraph --><p>Event description goes here.</p><!-- /wp:paragraph -->',
			'meta_input'   => array_merge(
				array(
					'_oe_start'         => $start,
					'_oe_end'           => $end,
					'_oe_venue_id'      => $venue,
					'_oe_organizer_ids' => array( $organizer ),
					'_oe_cost'          => '25',
					'_oe_currency_symbol' => '$',
				),
				$extra
			),
		)
	);
	if ( isset( $cats[ $cat ] ) && 'oe_event' === $type ) {
		wp_set_object_terms( $id, array( $cats[ $cat ] ), 'oe_event_category' );
	}
	return $id;
};

$d = static fn( int $day, string $time ) => $month->modify( '+' . ( $day - 1 ) . ' days' )->format( 'Y-m-d' ) . ' ' . $time;

$make( 'Jazz Night', $d( 3, '19:00:00' ), $d( 3, '22:00:00' ) );
$make( 'Pottery Basics', $d( 8, '10:00:00' ), $d( 8, '12:30:00' ), array( '_oe_cost' => '0' ), 'Workshops' );
$make( 'Harvest Festival', $d( 12, '00:00:00' ), $d( 14, '23:59:59' ), array( '_oe_all_day' => true ), 'Community' );
$make( 'Online Talk (Toronto time)', $d( 16, '18:00:00' ), $d( 16, '19:00:00' ), array( '_oe_timezone' => 'America/Toronto' ), 'Workshops' );
foreach ( array( 'Open Mic', 'Choir Practice', 'Board Games', 'Late Show' ) as $i => $title ) {
	$make( $title, $d( 20, sprintf( '%02d:00:00', 12 + $i * 2 ) ), $d( 20, sprintf( '%02d:30:00', 13 + $i * 2 ) ), array(), 'Community' );
}
$gala = $make( 'Next Month Gala', $month->modify( '+1 month +4 days' )->format( 'Y-m-d' ) . ' 18:00:00', $month->modify( '+1 month +4 days' )->format( 'Y-m-d' ) . ' 23:00:00' );
$make( 'Hidden Rehearsal', $d( 22, '09:00:00' ), $d( 22, '10:00:00' ), array( '_oe_hide_from_listings' => true ) );

// An ordinary Post that becomes an event, and one that stays a plain post.
$make( 'Blog post: Book Launch', $d( 25, '17:00:00' ), $d( 25, '19:00:00' ), array(), 'Music', 'post' );
wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Plain blog post (not an event)',
		'post_content' => '<!-- wp:paragraph --><p>Nothing about this post should change.</p><!-- /wp:paragraph -->',
	)
);

// RSVP on upcoming events (when the module is on), with a few responses.
$soon = current_datetime()->modify( '+6 days' )->format( 'Y-m-d' );
$workshop = $make( 'Intro to Bookbinding (3 places)', "{$soon} 18:30:00", "{$soon} 20:30:00", array( '_oe_cost' => '0' ), 'Workshops' );
$rsvp_events = array(
	$gala     => array( 'enabled' => true, 'capacity' => 120, 'max_per_response' => 4 ),
	$workshop => array( 'enabled' => true, 'capacity' => 3, 'max_per_response' => 2 ),
);
update_option( 'openevents_modules', array( 'ical', 'rsvp' ) );
OpenEvents\Attendance\Schema::install();
$rsvp = new OpenEvents\Modules\Rsvp\Service();
foreach ( $rsvp_events as $event_id => $config ) {
	update_post_meta( $event_id, '_oe_rsvp', $config );
	$rsvp->sync( $event_id );
}
openevents()->occurrences()->query( array( 'per_page' => 1 ) ); // Flush occurrence syncs first.
add_filter( 'pre_wp_mail', '__return_true' ); // No emails from sample data.
foreach ( array( array( 'Grace Hopper', 'grace@example.com', 2 ), array( 'Alan Turing', 'alan@example.com', 1 ), array( 'Katherine Johnson', 'katherine@example.com', 3 ) ) as $i => $person ) {
	$rsvp->respond( $gala, array( 'name' => $person[0], 'email' => $person[1], 'status' => 'going', 'quantity' => $person[2], 'ts' => OpenEvents\Modules\Rsvp\Service::sign_timestamp( time() - 60 ), 'ip' => '192.0.2.' . ( $i + 1 ) ) );
}
$rsvp->respond( $workshop, array( 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'status' => 'going', 'quantity' => 2, 'ts' => OpenEvents\Modules\Rsvp\Service::sign_timestamp( time() - 60 ), 'ip' => '192.0.2.9' ) );
remove_filter( 'pre_wp_mail', '__return_true' );

update_option( 'openevents_sample_data', 1 );
