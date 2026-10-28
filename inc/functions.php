<?php
/**
 * Public PHP API for themes and add-ons.
 *
 * These functions are a stable contract: their signatures will not change in
 * minor releases. Prefer them to instantiating internal classes.
 *
 * @package OpenEvents
 */

use OpenEvents\Content\Event;
use OpenEvents\Occurrences\Occurrence;
use OpenEvents\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin instance: settings(), occurrences() repository, modules() registry.
 */
function openevents(): Plugin {
	return Plugin::instance();
}

/**
 * Post types that can hold event data.
 *
 * @return string[]
 */
function openevents_event_post_types(): array {
	return openevents()->settings()->event_post_types();
}

/**
 * Event wrapper for a post, or null when the post's type is not event-enabled
 * or the post has no start date.
 */
function openevents_get_event( int|WP_Post|null $post = null ): ?Event {
	$event = Event::get( $post );
	return ( $event && $event->is_scheduled() ) ? $event : null;
}

function openevents_is_event( int|WP_Post|null $post = null ): bool {
	return null !== openevents_get_event( $post );
}

/**
 * Queries occurrences. See OpenEvents\Occurrences\Repository::defaults() for arguments.
 *
 * @param array<string, mixed> $args Query arguments.
 * @return array{items: Occurrence[], total: int, pages: int}
 */
function openevents_get_occurrences( array $args = array() ): array {
	return openevents()->occurrences()->query( $args );
}

/**
 * Upcoming occurrences, soonest first.
 *
 * @return Occurrence[]
 */
function openevents_get_upcoming( int $limit = 10, array $args = array() ): array {
	$args = array_merge(
		array(
			'ends_after' => OpenEvents\Occurrences\Repository::now(),
			'per_page'   => $limit,
			'count'      => false,
		),
		$args
	);
	return openevents_get_occurrences( $args )['items'];
}

/**
 * Human-readable date range for an occurrence, e.g. "November 3, 2026, 7:00 pm – 9:00 pm".
 */
function openevents_format_range( Occurrence $occurrence ): string {
	return OpenEvents\Frontend\Formatter::range( $occurrence );
}

/**
 * Link to one occurrence. Later dates of a repeating event add ?oe_date= so the
 * event page shows the date that was clicked; the first date uses the plain
 * permalink, which stays the canonical URL.
 */
function openevents_occurrence_url( Occurrence $occurrence ): string {
	$url = (string) get_permalink( $occurrence->event_id );
	return 0 === $occurrence->sequence ? $url : add_query_arg( 'oe_date', $occurrence->start->format( 'Y-m-d' ), $url );
}

function openevents_is_module_enabled( string $id ): bool {
	return openevents()->modules()->is_enabled( $id );
}

/**
 * URL of the main calendar: the Events archive when the post type is registered,
 * otherwise the calendar page chosen in setup.
 */
function openevents_calendar_url(): string {
	$settings = openevents()->settings();
	if ( $settings->registers_event_type() ) {
		$url = get_post_type_archive_link( $settings::EVENT_TYPE );
		if ( $url ) {
			return $url;
		}
	}
	$page = (int) $settings->get( 'calendar_page' );
	return $page ? (string) get_permalink( $page ) : home_url( '/' );
}
