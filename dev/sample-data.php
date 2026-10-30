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

$jazz = $make( 'Jazz Night', $d( 3, '19:00:00' ), $d( 3, '22:00:00' ) );
$make( 'Pottery Basics', $d( 8, '10:00:00' ), $d( 8, '12:30:00' ), array( '_oe_cost' => '0' ), 'Workshops' );
$make( 'Harvest Festival', $d( 12, '00:00:00' ), $d( 14, '23:59:59' ), array( '_oe_all_day' => true ), 'Community' );
$make( 'Online Talk (Toronto time)', $d( 16, '18:00:00' ), $d( 16, '19:00:00' ), array( '_oe_timezone' => 'America/Toronto' ), 'Workshops' );
foreach ( array( 'Open Mic', 'Choir Practice', 'Board Games', 'Late Show' ) as $i => $title ) {
	$make( $title, $d( 20, sprintf( '%02d:00:00', 12 + $i * 2 ) ), $d( 20, sprintf( '%02d:30:00', 13 + $i * 2 ) ), array(), 'Community' );
}
$gala = $make( 'Next Month Gala', $month->modify( '+1 month +4 days' )->format( 'Y-m-d' ) . ' 18:00:00', $month->modify( '+1 month +4 days' )->format( 'Y-m-d' ) . ' 23:00:00' );
$make( 'Hidden Rehearsal', $d( 22, '09:00:00' ), $d( 22, '10:00:00' ), array( '_oe_hide_from_listings' => true ) );

// A repeating event: eight weeks from the 2nd (needs the recurrence module, enabled below).
$storytime = $make( 'Weekly Storytime', $d( 2, '10:30:00' ), $d( 2, '11:15:00' ), array( '_oe_cost' => '0', '_oe_recurrence' => array( 'rrule' => 'FREQ=WEEKLY;COUNT=8', 'rdates' => array(), 'exdates' => array() ) ), 'Community' );

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
update_option( 'openevents_modules', array( 'ical', 'recurrence', 'series', 'rsvp', 'tickets' ) );
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

// Tickets: a concert with paid and free ticket types, payable at the door.
update_option( 'openevents_gateways', array( 'manual' => array( 'enabled' => true ) ), false );
$concert_day = current_datetime()->modify( '+12 days' )->format( 'Y-m-d' );
$concert     = $make( 'Spring Concert', "{$concert_day} 19:30:00", "{$concert_day} 21:30:00", array( '_oe_cost' => '' ), 'Music' );
update_post_meta(
	$concert,
	'_oe_tickets',
	array(
		array( 'key' => 'adult', 'name' => 'Adult', 'price' => '25', 'capacity' => 100, 'max_per_order' => 8 ),
		array( 'key' => 'student', 'name' => 'Student', 'price' => '10', 'capacity' => 40, 'max_per_order' => 8, 'description' => 'Bring student ID.' ),
		array( 'key' => 'kids', 'name' => 'Under 12', 'price' => '0', 'capacity' => 30, 'max_per_order' => 6 ),
	)
);
OpenEvents\Modules\Tickets\Module::sync( $concert );
OpenEvents\Modules\Tickets\Gateways::reset();
$adult = ( new OpenEvents\Attendance\TicketTypes() )->find( $concert, 'tkt-adult' );
( new OpenEvents\Modules\Tickets\Checkout() )->place( $concert, array( 'name' => 'Grace Hopper', 'email' => 'grace@example.com', 'tickets' => array( $adult->id => 2 ), 'gateway' => 'manual', 'ts' => OpenEvents\Attendance\Guard::sign_timestamp( time() - 60 ), 'ip' => '192.0.2.20' ) );

// Waitlists: a full talk (RSVP) and a sold-out front row (tickets).
$talk_day = current_datetime()->modify( '+8 days' )->format( 'Y-m-d' );
$talk     = $make( 'Author Talk (full)', "{$talk_day} 18:00:00", "{$talk_day} 19:00:00", array( '_oe_cost' => '0' ), 'Community' );
$talk_questions = array( array( 'type' => 'radio', 'label' => 'Have you read the book?', 'required' => true, 'options' => array( 'Yes', 'Not yet' ) ) );
update_post_meta( $talk, '_oe_rsvp', array( 'enabled' => true, 'capacity' => 2, 'max_per_response' => 2, 'waitlist' => true, 'fields' => $talk_questions ) );
$rsvp->sync( $talk );
foreach ( array( array( 'Mary Jackson', 'mary@example.com' ), array( 'Dorothy Vaughan', 'dorothy@example.com' ) ) as $i => $person ) {
	$rsvp->respond( $talk, array( 'name' => $person[0], 'email' => $person[1], 'status' => 'going', 'quantity' => 1, 'attendees' => array( array( 'fields' => array( 'have-you-read-the-book' => 'Yes' ) ) ), 'ts' => OpenEvents\Attendance\Guard::sign_timestamp( time() - 60 ), 'ip' => '192.0.2.3' . $i ) );
}
$tickets_meta   = get_post_meta( $concert, '_oe_tickets', true );
$tickets_meta[] = array( 'key' => 'front', 'name' => 'Front Row', 'price' => '60', 'capacity' => 2, 'max_per_order' => 2, 'waitlist' => true );
update_post_meta( $concert, '_oe_tickets', $tickets_meta );
OpenEvents\Modules\Tickets\Module::sync( $concert );
$front = ( new OpenEvents\Attendance\TicketTypes() )->find( $concert, 'tkt-front' );
( new OpenEvents\Modules\Tickets\Checkout() )->place( $concert, array( 'name' => 'Alan Turing', 'email' => 'alan@example.com', 'tickets' => array( $front->id => 2 ), 'gateway' => 'manual', 'ts' => OpenEvents\Attendance\Guard::sign_timestamp( time() - 60 ), 'ip' => '192.0.2.40' ) );
// Registration with attendee questions, as library programs use: each ticket
// holder's name and email, an age range, and a privacy consent.
$kids_questions = array(
	array( 'type' => 'radio', 'label' => 'Age range', 'required' => true, 'options' => array( '3–5', '6–8', '9–12' ) ),
	array( 'type' => 'tel', 'label' => 'Phone number', 'help' => 'For reminders on the day.' ),
	array( 'type' => 'checkbox', 'label' => 'I understand this information is collected to run the program and send reminders.', 'required' => true ),
);
$puppet_day = current_datetime()->modify( '+9 days' )->format( 'Y-m-d' );
$puppets    = $make( 'Puppet Show (registration)', "{$puppet_day} 10:30:00", "{$puppet_day} 11:15:00", array( '_oe_cost' => '0' ), 'Workshops' );
update_post_meta(
	$puppets,
	'_oe_tickets',
	array( array( 'key' => 'child', 'name' => 'Child', 'price' => '0', 'capacity' => 30, 'max_per_order' => 4, 'attendee_details' => 'required', 'fields' => $kids_questions ) )
);
OpenEvents\Modules\Tickets\Module::sync( $puppets );
OpenEvents\Attendance\FieldSets::save( 'Kids programs', $kids_questions );

// A series: three film nights with a free season pass sold on the series page.
$season = wp_insert_post(
	array(
		'post_type'    => 'oe_series',
		'post_status'  => 'publish',
		'post_title'   => 'Autumn Film Season',
		'post_content' => '<!-- wp:paragraph --><p>Three classic films, one evening each week.</p><!-- /wp:paragraph -->',
	)
);
foreach ( array( 'Film Night: Metropolis' => 4, 'Film Night: Casablanca' => 11, 'Film Night: Rear Window' => 18 ) as $film => $days ) {
	$film_day = current_datetime()->modify( "+{$days} days" )->format( 'Y-m-d' );
	$make( $film, "{$film_day} 19:00:00", "{$film_day} 21:00:00", array( '_oe_series_id' => $season, '_oe_cost' => '0' ), 'Music' );
}
update_post_meta(
	$season,
	'_oe_tickets',
	array( array( 'key' => 'pass', 'name' => 'Season pass', 'price' => '0', 'capacity' => 40, 'max_per_order' => 2 ) )
);
OpenEvents\Modules\Tickets\Module::sync( $season );

// An event attribute: who each event is for, filterable on the calendar.
update_option( 'openevents_settings', OpenEvents\Settings::sanitize( array_merge( openevents()->settings()->all(), array( 'attributes' => array( array( 'label' => 'Audiences' ) ) ) ) ) );
OpenEvents\Content\Attributes::register_taxonomies();
foreach ( array( 'All Ages', 'Kids', 'Teens', 'Adults' ) as $audience ) {
	wp_insert_term( $audience, 'oe_audiences' );
}
wp_set_object_terms( $storytime, array( 'Kids' ), 'oe_audiences' );
wp_set_object_terms( $puppets, array( 'Kids' ), 'oe_audiences' );
wp_set_object_terms( $jazz, array( 'Adults' ), 'oe_audiences' );
wp_set_object_terms( $concert, array( 'Adults' ), 'oe_audiences' );

remove_filter( 'pre_wp_mail', '__return_true' );

update_option( 'openevents_sample_data', 1 );
