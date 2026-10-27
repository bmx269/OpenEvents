<?php
/**
 * Event model.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Content;

use DateTimeImmutable;
use DateTimeZone;
use WP_Post;

/**
 * Read-only view of an event post and its meta.
 */
final class Event {

	private function __construct( public readonly WP_Post $post ) {}

	/**
	 * Wraps a post whose type is event-enabled. Use is_scheduled() to tell whether
	 * it is actually an event: posts in existing types only become events once a
	 * start date is set.
	 */
	public static function get( int|WP_Post|null $post ): ?Event {
		$post = get_post( $post );
		return ( $post && self::is_event_type( $post->post_type ) ) ? new self( $post ) : null;
	}

	public static function is_event_type( string $post_type ): bool {
		return in_array( $post_type, open_events_calendar()->settings()->event_post_types(), true );
	}

	public function is_scheduled(): bool {
		return null !== $this->start();
	}

	public function id(): int {
		return $this->post->ID;
	}

	public function meta( string $key ): mixed {
		return get_post_meta( $this->post->ID, $key, true );
	}

	public function timezone(): DateTimeZone {
		return self::resolve_timezone( (string) $this->meta( '_oe_timezone' ) );
	}

	public static function resolve_timezone( string $name ): DateTimeZone {
		if ( '' !== $name ) {
			try {
				return new DateTimeZone( $name );
			} catch ( \Exception ) {
				// Fall through to the site timezone.
			}
		}
		return wp_timezone();
	}

	public function is_all_day(): bool {
		return (bool) $this->meta( '_oe_all_day' );
	}

	/**
	 * Start in the event timezone, or null when the event has no start date yet.
	 */
	public function start(): ?DateTimeImmutable {
		return $this->parse( (string) $this->meta( '_oe_start' ) );
	}

	/**
	 * End in the event timezone. Falls back to the start when missing or earlier.
	 */
	public function end(): ?DateTimeImmutable {
		$start = $this->start();
		$end   = $this->parse( (string) $this->meta( '_oe_end' ) );
		if ( ! $start ) {
			return null;
		}
		return ( $end && $end >= $start ) ? $end : $start;
	}

	private function parse( string $value ): ?DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, $value, $this->timezone() );
		return $date ?: null;
	}

	public function venue(): ?WP_Post {
		$id = (int) $this->meta( '_oe_venue_id' );
		$venue = $id ? get_post( $id ) : null;
		return ( $venue && PostTypes::VENUE === $venue->post_type && 'publish' === $venue->post_status ) ? $venue : null;
	}

	/**
	 * @return WP_Post[]
	 */
	public function organizers(): array {
		$ids = array_filter( array_map( 'absint', (array) $this->meta( '_oe_organizer_ids' ) ) );
		if ( ! $ids ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => PostTypes::ORGANIZER,
				'post__in'       => $ids,
				'orderby'        => 'post__in',
				'posts_per_page' => count( $ids ),
			)
		);
	}

	public function cost(): string {
		$cost = trim( (string) $this->meta( '_oe_cost' ) );
		if ( '' === $cost ) {
			return '';
		}
		if ( is_numeric( $cost ) ) {
			if ( 0.0 === (float) $cost ) {
				return __( 'Free', 'open-events-calendar' );
			}
			return (string) $this->meta( '_oe_currency_symbol' ) . $cost;
		}
		return $cost;
	}

	public function url(): string {
		return (string) $this->meta( '_oe_url' );
	}

	/**
	 * Single-line venue address, e.g. "123 Main St, Vancouver, BC V5K 0A1, Canada".
	 */
	public static function venue_address( WP_Post $venue ): string {
		$get    = static fn( string $key ): string => trim( (string) get_post_meta( $venue->ID, $key, true ) );
		$region = trim( $get( '_oe_region' ) . ' ' . $get( '_oe_postal_code' ) );
		return implode( ', ', array_filter( array( $get( '_oe_address' ), $get( '_oe_city' ), $region, $get( '_oe_country' ) ) ) );
	}
}
