<?php
/**
 * Importer for The Events Calendar (with Pro) and Event Tickets (with Plus).
 *
 * @package OpenEvents
 */

namespace OpenEvents\Import;

use DateTimeImmutable;
use DateTimeZone;
use OpenEvents\Attendance\Fields;
use OpenEvents\Attendance\FieldSets;
use OpenEvents\Attendance\Money;
use OpenEvents\Attendance\Schema;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Content\EventStatus;
use OpenEvents\Content\Meta;
use OpenEvents\Content\PostTypes;
use OpenEvents\Modules\Recurrence\Recurrence;
use OpenEvents\Modules\Recurrence\Rule;
use OpenEvents\Modules\Series\Module as Series;
use OpenEvents\Modules\Tickets\Module as Tickets;
use OpenEvents\Occurrences\Sync;
use OpenEvents\Settings;

/**
 * Converts a TEC site in place: events, venues, organizers, and series keep
 * their post ids, slugs, content, images, and other plugins' meta; their
 * post type (and categories' taxonomy) changes, and OpenEvents meta is
 * written next to TEC's, which stays for a rollback.
 *
 * Tickets, orders, and attendees are copied into the OpenEvents tables, with
 * check-in codes, check-ins, and attendee answers. What's left behind is
 * reported: legacy per-date posts TEC no longer shows, and attendance data
 * whose event was deleted (see the cleanup advice in the report).
 *
 * Every step can run again: posts are matched by id and table rows through
 * ImportMap.
 */
final class Tec {

	/** TEC post type => OpenEvents post type. */
	public const TYPES = array(
		'tribe_venue'        => PostTypes::VENUE,
		'tribe_organizer'    => PostTypes::ORGANIZER,
		'tribe_event_series' => Series::POST_TYPE,
		'tribe_events'       => PostTypes::EVENT,
	);

	private const FIELD_TYPES = array(
		'text'      => 'text',
		'textarea'  => 'textarea',
		'email'     => 'email',
		'telephone' => 'tel',
		'url'       => 'url',
		'number'    => 'number',
		'date'      => 'date',
		'birth'     => 'date',
		'select'    => 'select',
		'radio'     => 'radio',
		'checkbox'  => 'checkbox',
	);

	private const ORDER_STATUSES = array(
		'tec-tc-completed'     => 'completed',
		'tec-tc-pending'       => 'unpaid',
		'tec-tc-action-req'    => 'pending',
		'tec-tc-approved'      => 'completed',
		'tec-tc-refunded'      => 'refunded',
		'tec-tc-reversed'      => 'refunded',
		'tec-tc-denied'        => 'failed',
		'tec-tc-not-completed' => 'expired',
		'tec-tc-voided'        => 'cancelled',
		'tec-tc-created'       => 'expired',
	);

	/** @var callable(string): void */
	private $log;

	/** @var array<string, mixed> */
	private array $report = array();

	/** @var array<int, array{fields: array, keys: array<string, string>, details: string}> Questions per TEC ticket. */
	private array $questions = array();

	public function __construct( ?callable $log = null ) {
		$this->log = $log ?? static function ( string $message ): void {};
	}

	/**
	 * What would be imported, without changing anything.
	 *
	 * @return array<string, mixed>
	 */
	public function preview(): array {
		global $wpdb;
		if ( ! $this->has_tec_tables() ) {
			return array( 'error' => 'TEC tables (wp_tec_events) not found: is this a TEC 6 site?' );
		}
		$out = array();
		foreach ( self::TYPES as $from => $to ) {
			$out[ $from ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $from ) ); // phpcs:ignore WordPress.DB
		}
		$out['legacy per-date posts (skipped)'] = count( $this->legacy_children() );
		$out['categories']                      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tribe_events_cat'" ); // phpcs:ignore WordPress.DB
		$out['repeating (rset)']                = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tec_events WHERE rset IS NOT NULL AND rset <> ''" ); // phpcs:ignore WordPress.DB
		$out['tickets (on existing events)']    = count( $this->source_tickets() );
		$out['attendees (on existing events)']  = (int) $wpdb->get_var( $this->attendee_sql( 'COUNT(*)' ) ); // phpcs:ignore WordPress.DB
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('tec_tc_attendee','tribe_rsvp_attendees')" ); // phpcs:ignore WordPress.DB
		$out['attendees (deleted events, left behind)'] = $all - $out['attendees (on existing events)'];
		return $out;
	}

	/**
	 * Runs every step and returns the report.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		if ( ! $this->has_tec_tables() ) {
			return array( 'error' => 'TEC tables (wp_tec_events) not found: is this a TEC 6 site?' );
		}
		ImportMap::install();
		Schema::maybe_install();
		$sync = Sync::instance();
		$sync->suspend();
		wp_defer_term_counting( true );

		$this->settings();
		$this->categories();
		$this->convert_posts();
		$this->venues_and_organizers();
		$this->events();
		$this->additional_fields();
		$this->field_sets();
		$this->tickets();
		$this->orders_and_attendees();
		$this->waitlists();
		$this->menus();

		wp_defer_term_counting( false );
		$sync->resume();
		$this->step( 'Rebuilding occurrences' );
		$this->report['occurrences'] = $sync->rebuild_all();
		update_option( \OpenEvents\Installer::FLUSH_OPTION, 1 );

		$this->report['verify'] = $this->verify();
		return $this->report;
	}

	/**
	 * Compares what TEC had with what OpenEvents now has.
	 *
	 * @return array<string, mixed>
	 */
	public function verify(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB
		$out = array();
		foreach ( self::TYPES as $from => $to ) {
			$left        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $from ) );
			$out[ $to ]  = array(
				'converted'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_oe_source_tec' AND m.meta_value = %s AND p.post_type = %s", $from, $to ) ),
				'still TEC type'   => $left,
			);
		}

		$tec_attendees = (int) $wpdb->get_var( $this->attendee_sql( 'COUNT(*)' ) );
		$map           = ImportMap::all( 'tec_attendee' );
		$out['attendees'] = array(
			'TEC (existing events)' => $tec_attendees,
			'imported'              => count( $map ),
		);
		if ( $map ) {
			$ids                              = implode( ',', array_map( 'intval', $map ) );
			$out['attendees']['with answers'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::attendees() . " WHERE id IN ({$ids}) AND fields NOT IN ('{}','[]','')" );
			$out['attendees']['checked in']   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::attendees() . " WHERE id IN ({$ids}) AND checked_in_utc IS NOT NULL" );
		}
		$out['attendees']['TEC checked in'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} c JOIN ( " . $this->attendee_sql( 'a.ID' ) . " ) x ON x.ID = c.post_id WHERE c.meta_key = '_tec_tickets_commerce_checked_in' AND c.meta_value = '1'" );
		$out['orders']                      = array( 'imported' => count( ImportMap::all( 'tec_order' ) ) );
		$out['ticket types']                = array(
			'TEC tickets (existing events)' => count( $this->source_tickets() ),
			'imported'                      => count( ImportMap::all( 'tec_ticket' ) ),
		);

		// Dates: every TEC occurrence of a published event within the expansion horizon must exist.
		$horizon  = gmdate( 'Y-m-d H:i:s', strtotime( '+2 years' ) );
		$missing  = array();
		$checked  = 0;
		$rows     = $wpdb->get_results( "SELECT o.post_id, o.start_date FROM {$wpdb->prefix}tec_occurrences o JOIN {$wpdb->posts} p ON p.ID = o.post_id WHERE p.post_status = 'publish' AND p.post_type = '" . PostTypes::EVENT . "' AND o.start_date_utc <= '{$horizon}'" );
		$have_raw = $wpdb->get_results( 'SELECT event_id, start_local FROM ' . \OpenEvents\Installer::occurrences_table() );
		$have     = array();
		foreach ( $have_raw as $row ) {
			$have[ $row->event_id . '|' . $row->start_local ] = true;
		}
		foreach ( $rows as $row ) {
			++$checked;
			if ( ! isset( $have[ $row->post_id . '|' . $row->start_date ] ) ) {
				$missing[] = $row->post_id . ' @ ' . $row->start_date;
			}
		}
		$out['dates'] = array(
			'TEC occurrences checked' => $checked,
			'missing in OpenEvents'   => count( $missing ),
			'examples'                => array_slice( $missing, 0, 10 ),
		);
		// phpcs:enable
		return $out;
	}

	/**
	 * Undoes the conversion: post types and the category taxonomy go back, and
	 * rows the import added to the OpenEvents tables are deleted. TEC's own
	 * meta was never removed, so TEC can be switched back on afterwards.
	 *
	 * @return array<string, int>
	 */
	public function rollback(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB
		$out = array();
		foreach ( self::TYPES as $from => $to ) {
			$out[ $from ] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_oe_source_tec' AND m.meta_value = %s SET p.post_type = %s WHERE p.post_type = %s", $from, $from, $to ) );
		}
		$out['categories'] = (int) $wpdb->query( "UPDATE {$wpdb->term_taxonomy} tt JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id AND tm.meta_key = '_oe_source_tec' SET tt.taxonomy = 'tribe_events_cat' WHERE tt.taxonomy = '" . PostTypes::CATEGORY . "'" );
		foreach ( array( 'tec_attendee' => Schema::attendees(), 'tec_order' => Schema::orders(), 'tec_ticket' => Schema::ticket_types(), 'tec_waitlist' => Schema::waitlist() ) as $source => $table ) {
			$ids = ImportMap::all( $source );
			foreach ( array_chunk( array_values( $ids ), 500 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				if ( 'tec_order' === $source ) {
					$wpdb->query( 'DELETE FROM ' . Schema::order_items() . " WHERE order_id IN ({$in})" );
				}
				$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );
			}
			$out[ $source ] = count( $ids );
			ImportMap::forget( $source );
		}
		foreach ( array( '_menu_item_object' ) as $key ) {
			foreach ( self::TYPES + array( 'tribe_events_cat' => PostTypes::CATEGORY ) as $from => $to ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} m JOIN {$wpdb->postmeta} s ON s.post_id = m.post_id AND s.meta_key = '_oe_source_tec' SET m.meta_value = %s WHERE m.meta_key = %s AND m.meta_value = %s", $from, $key, $to ) );
			}
		}
		$wpdb->query( 'DELETE FROM ' . \OpenEvents\Installer::occurrences_table() );
		// phpcs:enable
		update_option( \OpenEvents\Installer::FLUSH_OPTION, 1 );
		return $out;
	}

	private function settings(): void {
		$this->step( 'Settings' );
		$tec      = (array) get_option( 'tribe_events_calendar_options', array() );
		$settings = openevents()->settings();
		$current  = $settings->all();
		$view     = (string) ( $tec['viewOption'] ?? 'month' );
		update_option(
			Settings::OPTION,
			Settings::sanitize(
				array_merge(
					$current,
					array(
						'register_event_type' => true,
						'events_slug'         => (string) ( $tec['eventsSlug'] ?? 'events' ),
						'single_slug'         => (string) ( $tec['singleEventSlug'] ?? 'event' ),
						'per_page'            => (int) ( $tec['postsPerPage'] ?? $current['per_page'] ),
						// TEC's photo, map, and summary views have no equivalent yet; list is closest.
						'default_view'        => in_array( $view, array( 'month', 'week', 'day', 'list' ), true ) ? $view : 'list',
						'currency'            => (string) ( $tec['tickets-commerce-currency-code'] ?? $current['currency'] ),
					)
				)
			)
		);
		$modules = array_values( array_unique( array_merge( $settings->enabled_modules(), array( 'ical', 'recurrence', 'series', 'rsvp', 'tickets' ) ) ) );
		update_option( Settings::MODULES_OPTION, $modules );
		update_option( 'openevents_setup_complete', 1 );
		$this->report['settings'] = array(
			'events slug' => $tec['eventsSlug'] ?? 'events',
			'single slug' => $tec['singleEventSlug'] ?? 'event',
			'TEC view'    => $view,
		);
	}

	private function categories(): void {
		global $wpdb;
		$this->step( 'Categories' );
		// phpcs:disable WordPress.DB
		$terms = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tribe_events_cat'" );
		foreach ( $terms as $term_id ) {
			update_term_meta( (int) $term_id, '_oe_source_tec', 'tribe_events_cat' );
			$color = (string) get_term_meta( (int) $term_id, 'tec-events-cat-colors-primary', true );
			if ( $color && ! get_term_meta( (int) $term_id, 'oe_color', true ) ) {
				update_term_meta( (int) $term_id, 'oe_color', sanitize_hex_color( $color ) );
			}
		}
		$wpdb->query( "UPDATE {$wpdb->term_taxonomy} SET taxonomy = '" . PostTypes::CATEGORY . "' WHERE taxonomy = 'tribe_events_cat'" );
		// phpcs:enable
		clean_term_cache( array_map( 'intval', $terms ), '', false );
		$this->report['categories'] = count( $terms );
	}

	/**
	 * Post types change in place; legacy per-date posts (pre-TEC 6 recurrence
	 * children that TEC no longer shows) stay as they are.
	 */
	private function convert_posts(): void {
		global $wpdb;
		$this->step( 'Events, venues, organizers, series' );
		$skip = $this->legacy_children();
		// phpcs:disable WordPress.DB
		foreach ( self::TYPES as $from => $to ) {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $from ) ) );
			$ids = array_values( array_diff( $ids, $skip ) );
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$in = implode( ',', $chunk );
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE ID IN ({$in})", $to ) );
				foreach ( $chunk as $id ) {
					update_post_meta( $id, '_oe_source_tec', $from );
					clean_post_cache( $id );
				}
			}
			$this->report['converted'][ $to ] = count( $ids );
		}
		// phpcs:enable
		$this->report['left behind']['legacy per-date posts'] = count( $skip );
	}

	private function venues_and_organizers(): void {
		$this->step( 'Venue and organizer details' );
		$venue = array(
			'_oe_address'     => array( '_VenueAddress' ),
			'_oe_city'        => array( '_VenueCity' ),
			'_oe_region'      => array( '_VenueStateProvince', '_VenueState', '_VenueProvince' ),
			'_oe_postal_code' => array( '_VenueZip' ),
			'_oe_country'     => array( '_VenueCountry' ),
			'_oe_phone'       => array( '_VenuePhone' ),
			'_oe_website'     => array( '_VenueURL' ),
			'_oe_lat'         => array( '_VenueLat' ),
			'_oe_lng'         => array( '_VenueLng' ),
		);
		$organizer = array(
			'_oe_email'   => array( '_OrganizerEmail' ),
			'_oe_phone'   => array( '_OrganizerPhone' ),
			'_oe_website' => array( '_OrganizerWebsite' ),
		);
		foreach ( array( PostTypes::VENUE => $venue, PostTypes::ORGANIZER => $organizer ) as $type => $map ) {
			foreach ( $this->converted( $type ) as $id ) {
				foreach ( $map as $to => $from ) {
					foreach ( $from as $key ) {
						$value = trim( (string) get_post_meta( $id, $key, true ) );
						if ( '' !== $value ) {
							update_post_meta( $id, $to, $value );
							break;
						}
					}
				}
			}
		}
	}

	private function events(): void {
		global $wpdb;
		$this->step( 'Event dates, places, and repeats' );
		// phpcs:disable WordPress.DB
		$series = array();
		foreach ( (array) $wpdb->get_results( "SELECT event_post_id, series_post_id FROM {$wpdb->prefix}tec_series_relationships" ) as $row ) {
			$series[ (int) $row->event_post_id ] = (int) $row->series_post_id;
		}
		$rsets = array();
		foreach ( (array) $wpdb->get_results( "SELECT post_id, rset FROM {$wpdb->prefix}tec_events WHERE rset IS NOT NULL AND rset <> ''" ) as $row ) {
			$rsets[ (int) $row->post_id ] = (string) $row->rset;
		}
		// phpcs:enable

		$repeating = 0;
		$called    = 0;
		$offsets   = array();
		foreach ( $this->converted( PostTypes::EVENT ) as $id ) {
			$meta = static fn( string $key ) => get_post_meta( $id, $key, true );
			$tz   = (string) $meta( '_EventTimezone' );
			if ( preg_match( '/^UTC([+-]\d+(?:\.\d+)?)?$/', $tz, $m ) ) {
				// TEC's fixed offsets: UTC itself maps exactly; others keep their wall-clock times.
				if ( '' !== ( $m[1] ?? '' ) && 0.0 !== (float) $m[1] ) {
					$offsets[] = $id;
				}
				$tz = ( '' === ( $m[1] ?? '' ) || 0.0 === (float) $m[1] ) ? 'UTC' : '';
			}
			$values = array(
				'_oe_start'              => Meta::sanitize_datetime( $meta( '_EventStartDate' ) ),
				'_oe_end'                => Meta::sanitize_datetime( $meta( '_EventEndDate' ) ),
				'_oe_timezone'           => Meta::sanitize_timezone( $tz ),
				'_oe_all_day'            => in_array( strtolower( (string) $meta( '_EventAllDay' ) ), array( 'yes', '1', 'true' ), true ),
				'_oe_venue_id'           => (int) $meta( '_EventVenueID' ),
				'_oe_organizer_ids'      => array_values( array_filter( array_map( 'intval', get_post_meta( $id, '_EventOrganizerID' ) ) ) ),
				'_oe_cost'               => (string) ( get_post_meta( $id, '_EventCost' )[0] ?? '' ),
				'_oe_currency_symbol'    => (string) $meta( '_EventCurrencySymbol' ),
				'_oe_url'                => esc_url_raw( (string) $meta( '_EventURL' ) ),
				'_oe_featured'           => (bool) $meta( '_tribe_featured' ),
				'_oe_hide_from_listings' => '' !== (string) $meta( '_EventHideFromUpcoming' ) && 'no' !== strtolower( (string) $meta( '_EventHideFromUpcoming' ) ),
			);
			$status = self::status( (string) $meta( '_tribe_events_status' ), (string) get_post_field( 'post_title', $id ) );
			if ( EventStatus::SCHEDULED !== $status ) {
				$values['_oe_status']        = $status;
				$values['_oe_status_reason'] = EventStatus::sanitize_reason( $meta( '_tribe_events_status_reason' ) );
				++$called;
			}
			if ( isset( $series[ $id ] ) ) {
				$values[ Series::META_KEY ] = $series[ $id ];
			}
			if ( isset( $rsets[ $id ] ) ) {
				$recurrence = self::recurrence( $rsets[ $id ] );
				if ( $recurrence ) {
					$values[ Recurrence::META_KEY ] = $recurrence;
					++$repeating;
				}
			}
			foreach ( $values as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
		}
		$this->report['repeating events']               = $repeating;
		$this->report['cancelled or postponed events'] = $called;
		if ( $offsets ) {
			$this->report['notes'][] = sprintf( '%d events used a fixed UTC offset; their wall-clock times were kept with the site timezone (ids: %s).', count( $offsets ), implode( ', ', array_slice( $offsets, 0, 20 ) ) );
		}
	}

	/**
	 * TEC's event status ("canceled", "postponed"), else a title that says so
	 * ("[CANCELLED] Storytime", "Postponed: Concert"), as sites without the
	 * status field often marked it. Titles are left as they are.
	 */
	public static function status( string $tec_status, string $title ): string {
		$tec_status = strtolower( trim( $tec_status ) );
		if ( in_array( $tec_status, array( 'canceled', 'cancelled' ), true ) ) {
			return EventStatus::CANCELLED;
		}
		if ( 'postponed' === $tec_status ) {
			return EventStatus::POSTPONED;
		}
		if ( preg_match( '/^\W*(cancell?ed|postponed)\b/i', $title, $m ) ) {
			return str_starts_with( strtolower( $m[1] ), 'post' ) ? EventStatus::POSTPONED : EventStatus::CANCELLED;
		}
		return EventStatus::SCHEDULED;
	}

	/**
	 * TEC Pro "Additional fields": choice fields (checkbox, radio, dropdown)
	 * become event attributes (taxonomies) with their options as terms; text
	 * fields become labelled rows in the event details (`_oe_details`).
	 */
	private function additional_fields(): void {
		$this->step( 'Additional fields' );
		$defs = (array) ( get_option( 'tribe_events_calendar_options', array() )['custom-fields'] ?? array() );
		if ( ! $defs ) {
			return;
		}

		$settings   = openevents()->settings()->all();
		$attributes = (array) $settings['attributes'];
		$choices    = array();
		$texts      = array();
		foreach ( $defs as $def ) {
			if ( ! is_array( $def ) || empty( $def['name'] ) || '' === trim( (string) ( $def['label'] ?? '' ) ) ) {
				continue;
			}
			$label = trim( (string) $def['label'] );
			if ( in_array( $def['type'] ?? '', array( 'checkbox', 'radio', 'dropdown' ), true ) ) {
				$existing = array_values( array_filter( $attributes, static fn( $a ) => $a['label'] === $label ) );
				if ( ! $existing ) {
					$attributes[] = array( 'label' => $label );
				}
				$choices[ (string) $def['name'] ] = array(
					'label'   => $label,
					'options' => array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\n|\r/', (string) ( $def['values'] ?? '' ) ) ) ) ),
				);
			} else {
				$texts[ (string) $def['name'] ] = $label;
			}
		}

		if ( $choices ) {
			update_option( Settings::OPTION, Settings::sanitize( array_merge( $settings, array( 'attributes' => $attributes ) ) ) );
			\OpenEvents\Content\Attributes::register_taxonomies();
			$by_label = array_flip( \OpenEvents\Content\Attributes::all() );
			foreach ( $choices as $name => $choice ) {
				$taxonomy = $by_label[ $choice['label'] ] ?? '';
				foreach ( $choice['options'] as $option ) {
					// Created in TEC's order; the calendar lists terms by creation order.
					if ( $taxonomy && ! term_exists( $option, $taxonomy ) ) {
						wp_insert_term( $option, $taxonomy );
					}
				}
				$choices[ $name ]['taxonomy'] = $taxonomy;
			}
		}

		$assigned = 0;
		foreach ( $this->converted( PostTypes::EVENT ) as $id ) {
			foreach ( $choices as $name => $choice ) {
				$value = trim( (string) get_post_meta( $id, $name, true ) );
				if ( '' !== $value && $choice['taxonomy'] ) {
					wp_set_object_terms( $id, array_values( array_filter( array_map( 'trim', explode( '|', $value ) ) ) ), $choice['taxonomy'] );
					++$assigned;
				}
			}
			$details = array();
			foreach ( $texts as $name => $label ) {
				$value = trim( (string) get_post_meta( $id, $name, true ) );
				if ( '' !== $value ) {
					$details[] = array(
						'label' => $label,
						'value' => $value,
					);
				}
			}
			if ( $details ) {
				update_post_meta( $id, '_oe_details', Meta::sanitize_details( $details ) );
			}
		}
		$this->report['additional fields'] = array(
			'attributes'          => array_values( array_column( $choices, 'label' ) ),
			'events with choices' => $assigned,
			'text fields'         => array_values( $texts ),
		);
	}

	/**
	 * `_oe_recurrence` from a TEC rset (DTSTART, DTEND, RRULE, RDATE, EXDATE
	 * lines), or null when it holds no repeat.
	 *
	 * @return array{rrule: string, rdates: string[], exdates: string[]}|null
	 */
	public static function recurrence( string $rset ): ?array {
		$rrule   = '';
		$rdates  = array();
		$exdates = array();
		$tz      = null;
		foreach ( preg_split( '/\\\\n|\r\n|\n/', $rset ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			list( $head, $value ) = explode( ':', $line, 2 );
			$parts                = explode( ';', $head );
			$name                 = strtoupper( array_shift( $parts ) );
			$params               = array();
			foreach ( $parts as $part ) {
				if ( str_contains( $part, '=' ) ) {
					list( $k, $v )                = explode( '=', $part, 2 );
					$params[ strtoupper( $k ) ] = $v;
				}
			}
			if ( isset( $params['TZID'] ) ) {
				try {
					$tz = new DateTimeZone( $params['TZID'] );
				} catch ( \Exception ) {
					$tz = null;
				}
			}
			if ( 'RRULE' === $name ) {
				try {
					$rrule = (string) Rule::parse( $value );
				} catch ( \InvalidArgumentException ) {
					$rrule = '';
				}
			}
			if ( in_array( $name, array( 'RDATE', 'EXDATE' ), true ) ) {
				foreach ( explode( ',', $value ) as $item ) {
					$start = explode( '/', $item )[0]; // VALUE=PERIOD: start/end.
					$local = self::ical_local( $start, $tz );
					if ( ! $local ) {
						continue;
					}
					if ( 'RDATE' === $name ) {
						$rdates[] = $local;
					} else {
						$exdates[] = substr( $local, 0, 10 );
					}
				}
			}
		}
		if ( '' === $rrule && ! $rdates ) {
			return null;
		}
		return array(
			'rrule'   => $rrule,
			'rdates'  => $rdates,
			'exdates' => $exdates,
		);
	}

	/**
	 * "20261103T103000", "…Z", or "20261103" as local "Y-m-d H:i:s".
	 */
	private static function ical_local( string $value, ?DateTimeZone $tz ): ?string {
		$value = trim( $value );
		if ( preg_match( '/^\d{8}$/', $value ) ) {
			return substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 ) . ' 00:00:00';
		}
		if ( ! preg_match( '/^\d{8}T\d{6}Z?$/', $value ) ) {
			return null;
		}
		$utc  = str_ends_with( $value, 'Z' );
		$date = DateTimeImmutable::createFromFormat( 'Ymd\THis', rtrim( $value, 'Z' ), $utc ? new DateTimeZone( 'UTC' ) : ( $tz ?? wp_timezone() ) );
		if ( ! $date ) {
			return null;
		}
		return ( $utc && $tz ? $date->setTimezone( $tz ) : $date )->format( Meta::DATETIME_FORMAT );
	}

	private function field_sets(): void {
		global $wpdb;
		$this->step( 'Saved question sets' );
		$count = 0;
		foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'ticket-meta-fieldset' AND post_status = 'publish'" ) as $set ) { // phpcs:ignore WordPress.DB
			$fields = self::questions( (array) get_post_meta( (int) $set->ID, '_tribe_tickets_meta_template', true ) );
			if ( $fields['fields'] ) {
				FieldSets::save( (string) $set->post_title, $fields['fields'] );
				++$count;
			}
		}
		$this->report['question sets'] = $count;
	}

	/**
	 * OpenEvents questions from Event Tickets Plus field definitions, and how
	 * ET+ answer keys map to them (checkbox answers are stored per option as
	 * "{slug}_{md5(option)}").
	 *
	 * @param array<int, mixed> $meta ET+ definitions.
	 * @return array{fields: array<int, array<string, mixed>>, keys: array<string, string>}
	 */
	public static function questions( array $meta ): array {
		$fields = array();
		$slugs  = array();
		$used   = array();
		foreach ( $meta as $def ) {
			if ( ! is_array( $def ) || ! isset( self::FIELD_TYPES[ $def['type'] ?? '' ] ) ) {
				continue;
			}
			$slug = (string) ( $def['slug'] ?? sanitize_title( (string) ( $def['label'] ?? '' ) ) );
			$key  = substr( sanitize_key( $slug ), 0, 40 ) ?: 'field';
			$base = $key;
			for ( $n = 2; in_array( $key, $used, true ); $n++ ) {
				$key = substr( $base, 0, 36 ) . '-' . $n;
			}
			$used[]         = $key;
			$slugs[ $slug ] = $key;
			$fields[]       = array(
				'key'      => $key,
				'type'     => self::FIELD_TYPES[ $def['type'] ],
				'label'    => (string) ( $def['label'] ?? $slug ),
				'required' => ! empty( $def['required'] ) && 'off' !== $def['required'],
				'help'     => (string) ( $def['description'] ?? '' ),
				'options'  => array_values( (array) ( $def['extra']['options'] ?? array() ) ),
			);
		}
		return array(
			'fields' => Fields::sanitize( $fields ),
			'keys'   => $slugs,
		);
	}

	/**
	 * Answers from ET+ attendee meta, keyed by OpenEvents question keys.
	 *
	 * @param array<string, mixed> $answers ET+ attendee meta.
	 * @param array<string, string> $keys   ET+ slug => OpenEvents key.
	 * @return array<string, mixed>
	 */
	public static function answers( array $answers, array $keys ): array {
		$out = array();
		foreach ( $answers as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || in_array( $name, array( 'tribe-tickets-plus-iac-name', 'tribe-tickets-plus-iac-email' ), true ) ) {
				continue;
			}
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				continue;
			}
			if ( isset( $keys[ $name ] ) ) {
				$out[ $keys[ $name ] ] = $value;
				continue;
			}
			// Checkbox option: "{slug}_{32 hex}".
			if ( preg_match( '/^(.+)_[a-f0-9]{32}$/', $name, $m ) && isset( $keys[ $m[1] ] ) ) {
				$out[ $keys[ $m[1] ] ]   = (array) ( $out[ $keys[ $m[1] ] ] ?? array() );
				$out[ $keys[ $m[1] ] ][] = $value;
				continue;
			}
			// A question since removed from the ticket: keep the answer under its own key.
			$out[ substr( sanitize_key( $name ), 0, 40 ) ] = $value;
		}
		return $out;
	}

	/**
	 * Tickets Commerce tickets become ticket types (passes on series), with
	 * their questions; RSVP-type tickets become free ticket types.
	 */
	private function tickets(): void {
		$this->step( 'Tickets and questions' );
		$by_owner = array();
		foreach ( $this->source_tickets() as $ticket ) {
			$by_owner[ (int) $ticket->owner ][] = $ticket;
		}

		$archived = 0;
		foreach ( $by_owner as $owner => $tickets ) {
			$entries = (array) get_post_meta( $owner, Tickets::META_KEY, true );
			$entries = array_values( array_filter( $entries, static fn( $e ) => is_array( $e ) && ! str_starts_with( (string) ( $e['key'] ?? '' ), 'tec' ) ) );
			$retired = array();
			foreach ( $tickets as $ticket ) {
				$id       = (int) $ticket->ID;
				$meta     = static fn( string $key ) => get_post_meta( $id, $key, true );
				$asked    = self::questions( (array) $meta( '_tribe_tickets_meta' ) );
				$iac      = (string) $meta( '_tribe_tickets_ar_iac' );
				$capacity = (int) $meta( '_tribe_ticket_capacity' );
				$entry    = array(
					'key'              => 'tec' . $id,
					'name'             => (string) $ticket->post_title,
					'description'      => (string) $ticket->post_excerpt,
					'price'            => (string) ( $meta( '_price' ) ?: '0' ),
					'capacity'         => max( 0, $capacity ),
					'max_per_order'    => 10,
					'sale_start'       => self::sale_date( (string) $meta( '_ticket_start_date' ), (string) $meta( '_ticket_start_time' ) ),
					'sale_end'         => self::sale_date( (string) $meta( '_ticket_end_date' ), (string) $meta( '_ticket_end_time' ) ),
					'attendee_details' => array(
						'allowed'  => 'optional',
						'required' => 'required',
					)[ $iac ] ?? 'none',
					'fields'           => $asked['fields'],
				);
				$this->questions[ $id ] = $asked;
				if ( 'publish' === $ticket->post_status ) {
					$entries[] = $entry;
				} else {
					$retired[] = $entry;
				}
			}
			update_post_meta( $owner, Tickets::META_KEY, Tickets::sanitize_types( $entries ) );
			Tickets::sync( $owner );

			$types = new TicketTypes();
			foreach ( $tickets as $ticket ) {
				$type = $types->find( $owner, Tickets::KEY_PREFIX . 'tec' . (int) $ticket->ID );
				if ( $type ) {
					ImportMap::set( 'tec_ticket', (int) $ticket->ID, $type->id );
				}
			}
			// Unpublished TEC tickets still have attendees: keep them as archived types.
			foreach ( Tickets::sanitize_types( $retired ) as $entry ) {
				$source = (int) substr( $entry['key'], 3 );
				$id     = $types->upsert(
					$owner,
					Tickets::KEY_PREFIX . $entry['key'],
					array(
						'kind'     => 'paid',
						'name'     => $entry['name'],
						'currency' => \OpenEvents\Modules\Tickets\Checkout::currency(),
						'status'   => 'archived',
						'settings' => array(
							'fields'           => $entry['fields'],
							'attendee_details' => $entry['attendee_details'],
						),
					)
				);
				ImportMap::set( 'tec_ticket', $source, $id );
				++$archived;
			}
		}
		$archived += $this->deleted_tickets();
		$this->report['ticket types'] = count( ImportMap::all( 'tec_ticket' ) );
		if ( $archived ) {
			$this->report['notes'][] = "{$archived} unpublished or deleted TEC tickets were kept as archived ticket types, because they have attendees.";
		}
	}

	/**
	 * Tickets deleted in TEC whose attendees remain: Event Tickets keeps the
	 * ticket's name on each attendee, so the attendees come over under an
	 * archived ticket type of that name.
	 *
	 * @return int Ticket types created.
	 */
	private function deleted_tickets(): int {
		global $wpdb;
		$done  = ImportMap::all( 'tec_ticket' );
		$types = new TicketTypes();
		$count = 0;
		// phpcs:ignore WordPress.DB
		$rows = $wpdb->get_results(
			"SELECT t.meta_value AS ticket, e.ID AS owner, MAX(n.meta_value) AS name
			FROM {$wpdb->posts} a
			JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key IN ('_tec_tickets_commerce_event', '_tribe_rsvp_event')
			JOIN {$wpdb->posts} e ON e.ID = m.meta_value
			JOIN {$wpdb->postmeta} t ON t.post_id = a.ID AND t.meta_key IN ('_tec_tickets_commerce_ticket', '_tribe_rsvp_product')
			LEFT JOIN {$wpdb->posts} tp ON tp.ID = t.meta_value
			LEFT JOIN {$wpdb->postmeta} n ON n.post_id = a.ID AND n.meta_key = '_tribe_deleted_product_name'
			WHERE a.post_type IN ('tec_tc_attendee', 'tribe_rsvp_attendees') AND tp.ID IS NULL
			GROUP BY t.meta_value, e.ID"
		);
		foreach ( $rows as $row ) {
			if ( isset( $done[ (int) $row->ticket ] ) ) {
				continue;
			}
			$id = $types->upsert(
				(int) $row->owner,
				Tickets::KEY_PREFIX . 'tec' . (int) $row->ticket,
				array(
					'kind'     => 'paid',
					/* translators: %d: ticket id */
					'name'     => (string) ( $row->name ?: sprintf( __( 'Deleted ticket %d', 'openevents' ), (int) $row->ticket ) ),
					'currency' => \OpenEvents\Modules\Tickets\Checkout::currency(),
					'status'   => 'archived',
				)
			);
			ImportMap::set( 'tec_ticket', (int) $row->ticket, $id );
			++$count;
		}
		return $count;
	}

	private static function sale_date( string $date, string $time ): string {
		return '' === trim( $date ) ? '' : Meta::sanitize_datetime( trim( $date ) . ' ' . ( trim( $time ) ?: '00:00:00' ) );
	}

	private function orders_and_attendees(): void {
		global $wpdb;
		$this->step( 'Orders and attendees' );
		$tickets  = ImportMap::all( 'tec_ticket' );
		$orders   = ImportMap::all( 'tec_order' );
		$done     = ImportMap::all( 'tec_attendee' );
		$types    = new TicketTypes();
		$currency = \OpenEvents\Modules\Tickets\Checkout::currency();
		$site_tz  = wp_timezone();
		$utc      = new DateTimeZone( 'UTC' );
		$imported = 0;
		$skipped  = array();

		// phpcs:disable WordPress.DB
		$rows = $wpdb->get_results( $this->attendee_sql( 'a.ID, a.post_parent, a.post_date_gmt, a.post_modified_gmt, a.post_type' ) );
		foreach ( $rows as $row ) {
			$id = (int) $row->ID;
			if ( isset( $done[ $id ] ) ) {
				continue;
			}
			$meta    = static fn( string $key ) => get_post_meta( $id, $key, true );
			$is_tc   = 'tec_tc_attendee' === $row->post_type;
			$ticket  = (int) $meta( $is_tc ? '_tec_tickets_commerce_ticket' : '_tribe_rsvp_product' );
			$event   = (int) $meta( $is_tc ? '_tec_tickets_commerce_event' : '_tribe_rsvp_event' );
			$type_id = $tickets[ $ticket ] ?? null;
			if ( ! $type_id ) {
				$skipped['ticket not imported'] = ( $skipped['ticket not imported'] ?? 0 ) + 1;
				continue;
			}

			// The order (or, for RSVPs, the response) groups attendees.
			$source_order = $is_tc ? (int) $row->post_parent : 0;
			$order_id     = 0;
			$group        = wp_generate_uuid4();
			if ( $source_order ) {
				if ( ! isset( $orders[ $source_order ] ) ) {
					$orders[ $source_order ] = $this->import_order( $source_order, $event, $currency );
				}
				$order_id = $orders[ $source_order ];
				$group    = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT uuid FROM ' . Schema::orders() . ' WHERE id = %d', $order_id ) ) ?: $group;
			} elseif ( ! $is_tc ) {
				$group = $this->rsvp_group( (string) $meta( '_tribe_rsvp_order' ) );
			}

			$answers = (array) maybe_unserialize( $meta( $is_tc ? '_tec_tickets_commerce_attendee_fields' : '_tribe_tickets_meta' ) );
			$name    = trim( (string) ( $answers['tribe-tickets-plus-iac-name'] ?? '' ) ) ?: (string) $meta( $is_tc ? '_tec_tickets_commerce_full_name' : '_tribe_rsvp_full_name' );
			$email   = trim( (string) ( $answers['tribe-tickets-plus-iac-email'] ?? '' ) ) ?: (string) $meta( $is_tc ? '_tec_tickets_commerce_email' : '_tribe_rsvp_email' );
			$fields  = self::answers( $answers, $this->questions[ $ticket ]['keys'] ?? array() );

			$checked = null;
			if ( '1' === (string) $meta( $is_tc ? '_tec_tickets_commerce_checked_in' : '_tribe_rsvp_checkedin' ) ) {
				$details = (array) maybe_unserialize( $meta( $is_tc ? '_tec_tickets_commerce_checked_in_details' : '_tribe_rsvp_checkedin_details' ) );
				$when    = ! empty( $details['date'] ) ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $details['date'], $site_tz ) : false;
				$checked = $when ? $when->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : (string) $row->post_modified_gmt;
			}

			$status = 'going';
			if ( $order_id ) {
				$order_status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::orders() . ' WHERE id = %d', $order_id ) );
				$status       = array(
					'completed' => 'going',
					'unpaid'    => 'going',
					'refunded'  => 'refunded',
					'pending'   => 'pending',
				)[ $order_status ] ?? 'cancelled';
			} elseif ( ! $is_tc ) {
				$status = 'no' === (string) $meta( '_tribe_rsvp_status' ) ? 'not_going' : 'going';
			}

			$wpdb->insert(
				Schema::attendees(),
				array(
					'uuid'           => wp_generate_uuid4(),
					'group_uuid'     => $group,
					'order_id'       => $order_id,
					'ticket_type_id' => $type_id,
					'event_id'       => $types->get( $type_id )?->event_id ?? $event,
					'name'           => mb_substr( $name, 0, 200 ),
					'email'          => mb_substr( $email, 0, 200 ),
					'status'         => $status,
					'security_code'  => mb_substr( (string) $meta( $is_tc ? '_tec_tickets_commerce_security_code' : '_tribe_rsvp_security_code' ), 0, 16 ),
					'checked_in_utc' => $checked,
					'created_utc'    => (string) $row->post_date_gmt,
					'updated_utc'    => (string) $row->post_modified_gmt,
					'fields'         => (string) wp_json_encode( (object) $fields ),
				)
			);
			ImportMap::set( 'tec_attendee', $id, (int) $wpdb->insert_id );
			++$imported;
		}
		// phpcs:enable

		foreach ( $tickets as $type_id ) {
			$types->recount( $type_id );
		}
		$this->report['attendees imported'] = $imported;
		$this->report['orders imported']    = count( ImportMap::all( 'tec_order' ) );
		if ( $skipped ) {
			$this->report['attendees skipped'] = $skipped;
		}
	}

	private function import_order( int $source, int $event, string $currency ): int {
		global $wpdb;
		$existing = ImportMap::get( 'tec_order', $source );
		if ( $existing ) {
			return $existing;
		}
		$post   = get_post( $source );
		$meta   = static fn( string $key ) => get_post_meta( $source, $key, true );
		$tickets = ImportMap::all( 'tec_ticket' );
		$owner  = $event;
		$items  = array();
		foreach ( (array) $meta( '_tec_tc_order_items' ) as $item ) {
			$type = $tickets[ (int) ( $item['ticket_id'] ?? 0 ) ] ?? null;
			if ( $type ) {
				$items[] = array(
					'ticket_type_id'   => $type,
					'quantity'         => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
					'unit_price_minor' => Money::to_minor( (string) ( $item['price'] ?? '0' ), $currency ),
				);
				$owner = (int) ( ( new TicketTypes() )->get( $type )?->event_id ?? $owner );
			}
		}
		$gateway = (string) $meta( '_tec_tc_order_gateway' );
		// phpcs:disable WordPress.DB
		$wpdb->insert(
			Schema::orders(),
			array(
				'uuid'        => wp_generate_uuid4(),
				'event_id'    => $owner,
				'status'      => self::ORDER_STATUSES[ $post ? $post->post_status : '' ] ?? 'completed',
				'gateway'     => in_array( $gateway, array( 'stripe', 'paypal', 'manual' ), true ) ? $gateway : 'free',
				'gateway_ref' => (string) $meta( '_tec_tc_order_gateway_order_id' ),
				'name'        => mb_substr( (string) $meta( '_tec_tc_order_purchaser_full_name' ), 0, 200 ),
				'email'       => mb_substr( (string) $meta( '_tec_tc_order_purchaser_email' ), 0, 200 ),
				'total_minor' => Money::to_minor( (string) ( $meta( '_tec_tc_order_total_value' ) ?: '0' ), $currency ),
				'currency'    => $currency,
				'created_utc' => $post ? $post->post_date_gmt : gmdate( 'Y-m-d H:i:s' ),
				'updated_utc' => $post ? $post->post_modified_gmt : gmdate( 'Y-m-d H:i:s' ),
				'meta'        => (string) wp_json_encode( array( 'imported_from' => 'tec', 'tec_order_id' => $source ) ),
			)
		);
		$order_id = (int) $wpdb->insert_id;
		foreach ( $items as $item ) {
			$wpdb->insert( Schema::order_items(), $item + array( 'order_id' => $order_id ) );
		}
		// phpcs:enable
		ImportMap::set( 'tec_order', $source, $order_id );
		return $order_id;
	}

	/** @var array<string, string> RSVP v1 order hash => group uuid. */
	private array $rsvp_groups = array();

	private function rsvp_group( string $hash ): string {
		if ( '' === $hash ) {
			return wp_generate_uuid4();
		}
		return $this->rsvp_groups[ $hash ] ??= wp_generate_uuid4();
	}

	/**
	 * Event Tickets waitlist subscribers who are still waiting join the first
	 * ticket type of their event.
	 */
	private function waitlists(): void {
		global $wpdb;
		$this->step( 'Waitlists' );
		$table = $wpdb->prefix . 'tec_waitlist_subscribers';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
			return;
		}
		$done  = ImportMap::all( 'tec_waitlist' );
		$types = ImportMap::all( 'tec_ticket' );
		$count = 0;
		// phpcs:disable WordPress.DB
		foreach ( $wpdb->get_results( "SELECT s.* FROM {$table} s JOIN {$wpdb->posts} p ON p.ID = s.post_id WHERE s.status = 0" ) as $row ) {
			if ( isset( $done[ (int) $row->waitlist_user_id ] ) ) {
				continue;
			}
			$type_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::ticket_types() . " WHERE event_id = %d AND status = 'active' ORDER BY id LIMIT 1", (int) $row->post_id ) );
			if ( ! $type_id || ! in_array( $type_id, $types, true ) ) {
				continue;
			}
			$wpdb->insert(
				Schema::waitlist(),
				array(
					'uuid'           => wp_generate_uuid4(),
					'ticket_type_id' => $type_id,
					'event_id'       => (int) $row->post_id,
					'name'           => mb_substr( (string) $row->fullname, 0, 200 ),
					'email'          => mb_substr( (string) $row->email, 0, 200 ),
					'quantity'       => 1,
					'status'         => 'waiting',
					'created_utc'    => get_gmt_from_date( (string) $row->created ),
					'updated_utc'    => get_gmt_from_date( (string) $row->created ),
				)
			);
			ImportMap::set( 'tec_waitlist', (int) $row->waitlist_user_id, (int) $wpdb->insert_id );
			++$count;
		}
		// phpcs:enable
		$this->report['waitlist entries'] = $count;
	}

	/**
	 * Menu items pointing at events, venues, organizers, series, and event
	 * categories follow the new types.
	 */
	private function menus(): void {
		global $wpdb;
		$this->step( 'Menus' );
		$changed = 0;
		foreach ( self::TYPES + array( 'tribe_events_cat' => PostTypes::CATEGORY ) as $from => $to ) {
			$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_menu_item_object' AND meta_value = %s", $to, $from ) ); // phpcs:ignore WordPress.DB
		}
		$this->report['menu items'] = $changed;
	}

	/**
	 * TC tickets (and RSVP v1 tickets) whose event or series was converted.
	 *
	 * @return object[] ID, post_title, post_excerpt, post_status, owner.
	 */
	private function source_tickets(): array {
		global $wpdb;
		$types = "'" . implode( "','", array( PostTypes::EVENT, Series::POST_TYPE, 'tribe_events', 'tribe_event_series' ) ) . "'";
		// phpcs:ignore WordPress.DB
		return (array) $wpdb->get_results(
			"SELECT t.ID, t.post_title, t.post_excerpt, t.post_status, m.meta_value AS owner
			FROM {$wpdb->posts} t
			JOIN {$wpdb->postmeta} m ON m.post_id = t.ID AND m.meta_key IN ('_tec_tickets_commerce_event', '_tribe_rsvp_for_event')
			JOIN {$wpdb->posts} e ON e.ID = m.meta_value AND e.post_type IN ({$types})
			WHERE t.post_type IN ('tec_tc_ticket', 'tribe_rsvp_tickets') AND t.post_status NOT IN ('auto-draft')
			ORDER BY t.ID"
		);
	}

	/**
	 * SQL for TC and RSVP v1 attendees that still belong to an event: their
	 * own event exists, or their ticket's does (TEC counts an attendee in its
	 * ticket's sales even when the attendee's event field points elsewhere).
	 */
	private function attendee_sql( string $select ): string {
		global $wpdb;
		return "SELECT {$select} FROM {$wpdb->posts} a
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key IN ('_tec_tickets_commerce_event', '_tribe_rsvp_event')
			LEFT JOIN {$wpdb->posts} e ON e.ID = m.meta_value
			LEFT JOIN {$wpdb->postmeta} tk ON tk.post_id = a.ID AND tk.meta_key IN ('_tec_tickets_commerce_ticket', '_tribe_rsvp_product')
			LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = tk.meta_value AND tm.meta_key IN ('_tec_tickets_commerce_event', '_tribe_rsvp_for_event')
			LEFT JOIN {$wpdb->posts} te ON te.ID = tm.meta_value
			WHERE a.post_type IN ('tec_tc_attendee', 'tribe_rsvp_attendees') AND ( e.ID IS NOT NULL OR te.ID IS NOT NULL )";
	}

	/**
	 * Pre-TEC 6 per-date posts: children of a recurring event that TEC 6 no
	 * longer lists (they have no row in its events table).
	 *
	 * @return int[]
	 */
	private function legacy_children(): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}tec_events e ON e.post_id = p.ID WHERE p.post_type IN ('tribe_events', '" . PostTypes::EVENT . "') AND p.post_parent > 0 AND e.post_id IS NULL" ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * @return int[] Converted posts of an OpenEvents type.
	 */
	private function converted( string $type ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_oe_source_tec' WHERE p.post_type = %s", $type ) ) ); // phpcs:ignore WordPress.DB
	}

	private function has_tec_tables(): bool {
		global $wpdb;
		return $wpdb->prefix . 'tec_events' === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'tec_events' ) ); // phpcs:ignore WordPress.DB
	}

	private function step( string $name ): void {
		( $this->log )( $name . '…' );
	}
}
