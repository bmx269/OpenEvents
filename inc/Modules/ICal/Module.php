<?php
/**
 * iCal module.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\ICal;

use OpenEvents\Content\Event;
use OpenEvents\Content\PostTypes;
use OpenEvents\Modules\AbstractModule;
use OpenEvents\Occurrences\Occurrence;
use OpenEvents\Occurrences\Repository;

/**
 * .ics downloads for single events (?ical=1) and an upcoming-events feed
 * (/events/?ical=1), plus "Add to calendar" links.
 */
final class Module extends AbstractModule {

	private const FEED_LIMIT = 100;

	public function id(): string {
		return 'ical';
	}

	public function label(): string {
		return __( 'iCal export', 'openevents' );
	}

	public function description(): string {
		return __( 'Adds .ics downloads for each event, a subscribable feed of upcoming events, and Google Calendar links.', 'openevents' );
	}

	public function boot(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
		add_filter( 'openevents/event_details/links', array( $this, 'event_links' ), 10, 2 );
		add_filter( 'openevents/calendar/links', array( $this, 'calendar_links' ) );
	}

	/**
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'ical';
		return $vars;
	}

	public function maybe_serve(): void {
		if ( ! get_query_var( 'ical' ) ) {
			return;
		}

		$repository = openevents()->occurrences();

		if ( is_singular( openevents()->settings()->event_post_types() ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			$date     = sanitize_text_field( wp_unslash( $_GET['oe_date'] ?? '' ) );
			$one      = $date ? $repository->on_date( get_queried_object_id(), $date ) : null;
			$result   = $one ? array( 'items' => array( $one ) ) : $repository->query(
				array(
					'event_ids'      => array( get_queried_object_id() ),
					'include_hidden' => true,
					'per_page'       => -1,
				)
			);
			$filename = get_post_field( 'post_name', get_queried_object_id() );
		} elseif ( is_post_type_archive( PostTypes::EVENT ) || is_tax( PostTypes::CATEGORY ) || $this->is_calendar_page() ) {
			$result   = $repository->query(
				array(
					'ends_after' => Repository::now(),
					'categories' => is_tax( PostTypes::CATEGORY ) ? array( get_queried_object_id() ) : array(),
					'per_page'   => self::FEED_LIMIT,
				)
			);
			$filename = sanitize_title( get_bloginfo( 'name' ) ) . '-events';
		} else {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ( $filename ?: 'events' ) . '.ics"' );
		echo Writer::calendar( $result['items'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar body, escaped per RFC 5545 in Writer.
		exit;
	}

	/**
	 * @param array<int, array{label: string, url: string}> $links Links.
	 * @return array<int, array{label: string, url: string}>
	 */
	public function event_links( array $links, Occurrence $occurrence ): array {
		$event = $occurrence->event();
		if ( ! $event ) {
			return $links;
		}
		$links[] = array(
			'label' => __( 'Google Calendar', 'openevents' ),
			'url'   => self::google_url( $occurrence, $event ),
		);
		$links[] = array(
			'label' => __( 'Download .ics', 'openevents' ),
			'url'   => add_query_arg( 'ical', '1', openevents_occurrence_url( $occurrence ) ),
		);
		return $links;
	}

	/**
	 * @param array<int, array{label: string, url: string}> $links Links.
	 * @return array<int, array{label: string, url: string}>
	 */
	public function calendar_links( array $links ): array {
		$links[] = array(
			'label' => __( 'Subscribe (iCal)', 'openevents' ),
			'url'   => add_query_arg( 'ical', '1', openevents_calendar_url() ),
		);
		return $links;
	}

	private function is_calendar_page(): bool {
		$page = (int) openevents()->settings()->get( 'calendar_page' );
		return $page && is_page( $page );
	}

	public static function google_url( Occurrence $occurrence, Event $event ): string {
		if ( $occurrence->all_day ) {
			$dates = $occurrence->start->format( 'Ymd' ) . '/' . $occurrence->end->modify( '+1 day' )->format( 'Ymd' );
		} else {
			$dates = $occurrence->start_utc()->format( 'Ymd\THis\Z' ) . '/' . $occurrence->end_utc()->format( 'Ymd\THis\Z' );
		}
		$venue = $event->venue();
		return add_query_arg(
			rawurlencode_deep(
				array(
					'action'   => 'TEMPLATE',
					'text'     => wp_strip_all_tags( get_the_title( $event->post ) ),
					'dates'    => $dates,
					'details'  => wp_trim_words( wp_strip_all_tags( get_the_excerpt( $event->post ) ), 50 ),
					'location' => $venue ? get_the_title( $venue ) . ', ' . Event::venue_address( $venue ) : '',
				)
			),
			'https://calendar.google.com/calendar/render'
		);
	}
}
