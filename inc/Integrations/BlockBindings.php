<?php
/**
 * Block Bindings source.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Integrations;

use OpenEvents\Content\Event;
use OpenEvents\Frontend\Formatter;

/**
 * "open-events-calendar/event" binding source: lets core blocks (Paragraph,
 * Heading, Button, Image) show event fields, so themes can design event
 * templates from core blocks instead of fixed plugin markup.
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{
 *        "source":"open-events-calendar/event","args":{"key":"date"}}}}} -->
 */
final class BlockBindings {

	public const SOURCE = 'open-events-calendar/event';

	/**
	 * Bindable keys and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array(
			'date'          => __( 'Event date and time', 'open-events-calendar' ),
			'start_date'    => __( 'Start date', 'open-events-calendar' ),
			'start_time'    => __( 'Start time', 'open-events-calendar' ),
			'end_date'      => __( 'End date', 'open-events-calendar' ),
			'end_time'      => __( 'End time', 'open-events-calendar' ),
			'venue'         => __( 'Venue name', 'open-events-calendar' ),
			'venue_address' => __( 'Venue address', 'open-events-calendar' ),
			'venue_url'     => __( 'Venue link', 'open-events-calendar' ),
			'cost'          => __( 'Cost', 'open-events-calendar' ),
			'website'       => __( 'Event website', 'open-events-calendar' ),
		);
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_source' ) );
	}

	public function register_source(): void {
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Event', 'open-events-calendar' ),
				'get_value_callback' => array( $this, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $args  Binding args; "key" selects the field.
	 * @param \WP_Block            $block Block instance.
	 */
	public function get_value( array $args, \WP_Block $block ): ?string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$event   = $post_id ? oec_get_event( $post_id ) : null;
		if ( ! $event ) {
			return null;
		}
		$occurrence = open_events_calendar()->occurrences()->for_event( $post_id );
		$venue      = $event->venue();

		$value = match ( (string) ( $args['key'] ?? '' ) ) {
			'date'          => $occurrence ? Formatter::range( $occurrence ) : '',
			'start_date'    => $occurrence ? Formatter::date( $occurrence->start ) : '',
			'start_time'    => $occurrence && ! $occurrence->all_day ? Formatter::time( $occurrence->start ) : '',
			'end_date'      => $occurrence ? Formatter::date( $occurrence->end ) : '',
			'end_time'      => $occurrence && ! $occurrence->all_day ? Formatter::time( $occurrence->end ) : '',
			'venue'         => $venue ? get_the_title( $venue ) : '',
			'venue_address' => $venue ? Event::venue_address( $venue ) : '',
			'venue_url'     => $venue ? (string) get_permalink( $venue ) : '',
			'cost'          => $event->cost(),
			'website'       => $event->url(),
			default         => null,
		};

		/**
		 * Filters a bound event value. Add-ons can supply values for their own keys.
		 *
		 * @param string|null          $value Value, or null for unknown keys.
		 * @param array<string, mixed> $args  Binding args.
		 * @param Event                $event Event.
		 */
		$value = apply_filters( 'open_events_calendar/block_bindings/value', $value, $args, $event );

		if ( null === $value ) {
			return null;
		}
		// URLs land in attributes, which the HTML API escapes; text lands in rich-text content.
		return in_array( $args['key'] ?? '', array( 'venue_url', 'website' ), true ) ? esc_url_raw( $value ) : esc_html( $value );
	}
}
