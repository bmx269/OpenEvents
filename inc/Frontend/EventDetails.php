<?php
/**
 * Event details markup.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Frontend;

use OpenEvents\Content\Event;
use OpenEvents\Occurrences\Occurrence;

/**
 * Renders the details panel (date, venue, organizers, cost, links) shared by the
 * event-details block and the classic-theme content filter.
 */
final class EventDetails {

	/** @var array<int, true> Posts whose details were already output this request. */
	private static array $rendered = array();

	public static function was_rendered( int $post_id ): bool {
		return isset( self::$rendered[ $post_id ] );
	}

	/**
	 * @param array<string, mixed> $options show_* toggles from block attributes.
	 */
	public static function render( int $post_id, array $options = array() ): string {
		$event = openevents_get_event( $post_id );
		if ( ! $event ) {
			return '';
		}
		$occurrence = openevents()->occurrences()->for_event( $post_id );
		if ( ! $occurrence ) {
			return '';
		}

		$options = wp_parse_args(
			$options,
			array(
				'showVenue'      => true,
				'showOrganizers' => true,
				'showCost'       => true,
				'showLinks'      => true,
			)
		);

		self::$rendered[ $post_id ] = true;

		$rows = array();

		$rows['date'] = array(
			'label' => __( 'When', 'openevents' ),
			'html'  => sprintf(
				'<time datetime="%s">%s</time>',
				esc_attr( Formatter::iso( $occurrence->start, $occurrence->all_day ) ),
				esc_html( Formatter::range( $occurrence ) )
			) . ( $occurrence->has_ended() ? ' <span class="oe-details__badge">' . esc_html__( 'This event has passed.', 'openevents' ) . '</span>' : '' ),
		);

		$venue = $options['showVenue'] ? $event->venue() : null;
		if ( $venue ) {
			$address = Event::venue_address( $venue );
			$html    = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $venue ) ), esc_html( get_the_title( $venue ) ) );
			if ( $address ) {
				$html .= sprintf(
					'<br><span class="oe-details__address">%s</span> <a class="oe-details__map" href="%s" rel="noopener" target="_blank">%s<span class="oe-visually-hidden"> %s</span></a>',
					esc_html( $address ),
					esc_url( 'https://www.openstreetmap.org/search?query=' . rawurlencode( $address ) ),
					esc_html__( 'Map', 'openevents' ),
					esc_html__( '(opens in a new tab)', 'openevents' )
				);
			}
			$rows['venue'] = array(
				'label' => __( 'Where', 'openevents' ),
				'html'  => $html,
			);
		}

		$organizers = $options['showOrganizers'] ? $event->organizers() : array();
		if ( $organizers ) {
			$rows['organizers'] = array(
				'label' => _n( 'Organizer', 'Organizers', count( $organizers ), 'openevents' ),
				'html'  => implode(
					', ',
					array_map(
						static fn( \WP_Post $o ) => sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $o ) ), esc_html( get_the_title( $o ) ) ),
						$organizers
					)
				),
			);
		}

		$cost = $options['showCost'] ? $event->cost() : '';
		if ( '' !== $cost ) {
			$rows['cost'] = array(
				'label' => __( 'Cost', 'openevents' ),
				'html'  => esc_html( $cost ),
			);
		}

		if ( $event->url() ) {
			$rows['website'] = array(
				'label' => __( 'Website', 'openevents' ),
				'html'  => sprintf( '<a href="%1$s">%2$s</a>', esc_url( $event->url() ), esc_html( wp_parse_url( $event->url(), PHP_URL_HOST ) ?: $event->url() ) ),
			);
		}

		/**
		 * Filters the detail rows. Each row is ['label' => string, 'html' => escaped HTML].
		 *
		 * @param array      $rows       Rows keyed by id.
		 * @param Event      $event      Event.
		 * @param Occurrence $occurrence Occurrence shown.
		 */
		$rows = apply_filters( 'openevents/event_details/rows', $rows, $event, $occurrence );

		$out = '<dl class="oe-details__list">';
		foreach ( $rows as $id => $row ) {
			$out .= sprintf(
				'<div class="oe-details__row oe-details__row--%s"><dt>%s</dt><dd>%s</dd></div>',
				esc_attr( (string) $id ),
				esc_html( $row['label'] ),
				wp_kses_post( $row['html'] )
			);
		}
		$out .= '</dl>';

		if ( $options['showLinks'] ) {
			/**
			 * Filters "add to calendar"-style links. Each link is ['label' => string, 'url' => string].
			 *
			 * @param array      $links      Links.
			 * @param Occurrence $occurrence Occurrence.
			 */
			$links = apply_filters( 'openevents/event_details/links', array(), $occurrence );
			if ( $links ) {
				$out .= '<ul class="oe-details__links">';
				foreach ( $links as $link ) {
					$out .= sprintf( '<li><a href="%s" rel="nofollow">%s</a></li>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
				}
				$out .= '</ul>';
			}
		}

		/**
		 * Filters extra sections appended to the details panel (RSVP form, tickets).
		 * Each section is safe HTML.
		 *
		 * @param string[]   $sections   Sections keyed by id.
		 * @param Event      $event      Event.
		 * @param Occurrence $occurrence Occurrence.
		 */
		$sections = apply_filters( 'openevents/event_details/sections', array(), $event, $occurrence );

		return $out . implode( '', $sections );
	}
}
