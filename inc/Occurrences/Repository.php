<?php
/**
 * Occurrence queries.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Occurrences;

use DateTimeInterface;
use DateTimeZone;
use OpenEvents\Content\Meta;
use OpenEvents\Content\PostTypes;
use OpenEvents\Installer;

/**
 * The one place that reads oe_occurrences. Views, REST, feeds, abilities, and
 * add-ons all query through here so filters, caching, and priming apply everywhere.
 *
 * Performance contract (see docs/ARCHITECTURE.md, "Performance"):
 * - Listings are single-table reads on oe_occurrences using its indexes; wp_posts
 *   is joined only for keyword search.
 * - Date-range scans are bounded by the longest event duration, so a month query
 *   reads about a month of index entries however much history exists.
 * - Results prime post, meta, term, venue, and organizer caches in a fixed number
 *   of queries, so rendering N events costs O(1) queries, not O(N).
 * - Results are cached in the object cache, invalidated by a last-changed key.
 */
final class Repository {

	public const CACHE_GROUP = 'openevents';

	/** @var array<int, Occurrence|null> Per-request memo for for_event(). */
	private array $event_memo = array();

	/**
	 * Default query arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'ends_after'     => null,    // Still running at/after this UTC instant ("upcoming").
			'ends_before'    => null,    // Finished before this UTC instant ("past").
			'starts_before'  => null,    // Starting before this UTC instant.
			'local_from'     => null,    // Overlaps the wall-clock range [local_from, local_to] ("Y-m-d H:i:s").
			'local_to'       => null,
			'categories'     => array(), // Term ids; children included.
			'venue'          => 0,
			'series'         => 0,       // Series post id (series module).
			'event_ids'      => array(),
			'post_types'     => array(), // Defaults to every event-enabled post type.
			'search'         => '',
			'include_hidden' => false,   // Include events flagged "hide from listings".
			'order'          => 'ASC',
			'per_page'       => 10,      // -1 for no limit.
			'page'           => 1,
			'count'          => true,    // Compute total/pages. Turn off when not paginating.
			'prime'          => true,    // Prime post/meta/term/venue/organizer caches for rendering.
			'prime_images'   => false,   // Also prime featured image attachments.
		);
	}

	/**
	 * @param array<string, mixed> $args See defaults().
	 * @return array{items: Occurrence[], total: int, pages: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		// Writes from earlier in this request must be visible to this read.
		Sync::instance()->flush();

		$args = wp_parse_args( $args, self::defaults() );

		/**
		 * Filters occurrence query arguments. A filter bar or search add-on can
		 * narrow every view at once from here.
		 *
		 * @param array<string, mixed> $args Query arguments.
		 */
		$args = apply_filters( 'openevents/occurrences/query_args', $args );

		$post_types = array_values( array_filter( (array) $args['post_types'] ) ) ?: openevents()->settings()->event_post_types();
		if ( ! $post_types ) {
			return array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$cache_args = $args;
		unset( $cache_args['prime'], $cache_args['prime_images'] );
		$cache_key = 'occ:' . md5( wp_json_encode( array( $cache_args, $post_types ) ) ) . ':' . self::last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			$rows  = $cached['rows'];
			$total = $cached['total'];
		} else {
			list( $sql, $order, $limit, $per_page ) = $this->build_sql( $args, $post_types );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows  = (array) $wpdb->get_results( "SELECT o.* {$sql} ORDER BY o.start_utc {$order}, o.id {$order} {$limit}" );
			$total = ( $per_page > 0 && $args['count'] ) ? (int) $wpdb->get_var( "SELECT COUNT(*) {$sql}" ) : count( $rows );
			// phpcs:enable

			wp_cache_set(
				$cache_key,
				array(
					'rows'  => $rows,
					'total' => $total,
				),
				self::CACHE_GROUP,
				DAY_IN_SECONDS
			);
		}

		$items = array_map( array( Occurrence::class, 'from_row' ), $rows );
		if ( $args['prime'] && $items ) {
			self::prime( $items, (bool) $args['prime_images'] );
		}

		$per_page = (int) $args['per_page'];
		return array(
			'items' => $items,
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		);
	}

	/**
	 * @param array<string, mixed> $args       Parsed arguments.
	 * @param string[]             $post_types Post types.
	 * @return array{0: string, 1: string, 2: string, 3: int} FROM/WHERE clause, order, limit, per page.
	 */
	private function build_sql( array $args, array $post_types ): array {
		global $wpdb;

		$table = Installer::occurrences_table();
		$from  = "FROM {$table} o";
		$where = array(
			"o.status = 'publish'",
			"o.post_type IN ('" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "')",
		);

		foreach ( array(
			'ends_after'    => 'o.end_utc >= %s',
			'ends_before'   => 'o.end_utc < %s',
			'starts_before' => 'o.start_utc < %s',
		) as $key => $clause ) {
			if ( null !== $args[ $key ] ) {
				$where[] = $wpdb->prepare( $clause, self::to_utc_string( $args[ $key ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		if ( $args['local_from'] && $args['local_to'] ) {
			// "Overlaps" alone (start <= to AND end >= from) makes the index scan all history
			// before `to`. No occurrence is longer than the max duration, so anything
			// overlapping must also start after (from - max duration): a bounded range.
			$floor   = gmdate( Meta::DATETIME_FORMAT, strtotime( $args['local_from'] . ' UTC' ) - self::max_duration() - DAY_IN_SECONDS );
			$where[] = $wpdb->prepare( 'o.start_local BETWEEN %s AND %s AND o.end_local >= %s', $floor, $args['local_to'], $args['local_from'] );
		}

		if ( ! $args['include_hidden'] ) {
			$where[] = 'o.hide_from_listings = 0';
		}

		if ( $args['venue'] ) {
			$where[] = $wpdb->prepare(
				"o.event_id IN (SELECT vm.post_id FROM {$wpdb->postmeta} vm WHERE vm.meta_key = '_oe_venue_id' AND vm.meta_value = %s)",
				(string) absint( $args['venue'] )
			);
		}

		if ( $args['series'] ) {
			$where[] = $wpdb->prepare(
				"o.event_id IN (SELECT sm.post_id FROM {$wpdb->postmeta} sm WHERE sm.meta_key = '_oe_series_id' AND sm.meta_value = %s)",
				(string) absint( $args['series'] )
			);
		}

		$event_ids = array_filter( array_map( 'absint', (array) $args['event_ids'] ) );
		if ( $event_ids ) {
			$where[] = 'o.event_id IN (' . implode( ',', $event_ids ) . ')';
		}

		$term_taxonomy_ids = self::category_tt_ids( (array) $args['categories'] );
		if ( null !== $term_taxonomy_ids ) {
			$where[] = $term_taxonomy_ids
				? "o.event_id IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN (" . implode( ',', $term_taxonomy_ids ) . '))'
				: '1 = 0';
		}

		if ( '' !== (string) $args['search'] ) {
			$from   .= " INNER JOIN {$wpdb->posts} p ON p.ID = o.event_id";
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s)', $like, $like, $like );
		}

		$order    = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$per_page = (int) $args['per_page'];
		$limit    = '';
		if ( $per_page > 0 ) {
			$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, max( 0, ( (int) $args['page'] - 1 ) * $per_page ) );
		}

		return array( $from . ' WHERE ' . implode( ' AND ', $where ), $order, $limit, $per_page );
	}

	/**
	 * Loads everything a listing renders in a fixed number of queries: the event
	 * posts with their meta and terms, category term meta (colours), venues and
	 * organizers with their meta, and optionally featured images.
	 *
	 * @param Occurrence[] $items Occurrences.
	 */
	public static function prime( array $items, bool $images = false ): void {
		$event_ids = array_values( array_unique( array_map( static fn( Occurrence $o ) => $o->event_id, $items ) ) );
		_prime_post_caches( $event_ids, true, true );

		$linked   = array();
		$term_ids = array();
		foreach ( $event_ids as $id ) {
			$linked[] = (int) get_post_meta( $id, '_oe_venue_id', true );
			foreach ( (array) get_post_meta( $id, '_oe_organizer_ids', true ) as $organizer ) {
				$linked[] = (int) $organizer;
			}
			if ( $images ) {
				$linked[] = (int) get_post_meta( $id, '_thumbnail_id', true );
			}
			$terms = get_object_term_cache( $id, PostTypes::CATEGORY );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_ids[] = $term->term_id;
				}
			}
		}

		$linked = array_values( array_unique( array_filter( $linked ) ) );
		if ( $linked ) {
			_prime_post_caches( $linked, false, true );
		}
		if ( $term_ids ) {
			update_termmeta_cache( array_values( array_unique( $term_ids ) ) );
		}
	}

	public function get( int $occurrence_id ): ?Occurrence {
		global $wpdb;
		$table = Installer::occurrences_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $occurrence_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? Occurrence::from_row( $row ) : null;
	}

	/**
	 * The latest-starting occurrence of a series' published events (including
	 * ones hidden from listings), or null when the series has none.
	 */
	public function last_in_series( int $series_id ): ?Occurrence {
		$items = $this->query(
			array(
				'series'         => $series_id,
				'include_hidden' => true,
				'order'          => 'DESC',
				'per_page'       => 1,
				'count'          => false,
				'prime'          => false,
			)
		)['items'];
		return $items[0] ?? null;
	}

	/**
	 * The event's first occurrence starting on a local date ("Y-m-d"), if any.
	 */
	public function on_date( int $event_id, string $date ): ?Occurrence {
		global $wpdb;
		Sync::instance()->flush();
		$table = Installer::occurrences_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND start_local BETWEEN %s AND %s ORDER BY start_local LIMIT 1", $event_id, $date . ' 00:00:00', $date . ' 23:59:59' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? Occurrence::from_row( $row ) : null;
	}

	/**
	 * The occurrence a visitor asked for: the date in ?oe_occurrence= (links to one
	 * date of a repeating event carry it), else the next one (see for_event()).
	 */
	public function requested( int $event_id ): ?Occurrence {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
		$date = sanitize_text_field( wp_unslash( $_GET['oe_occurrence'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$occurrence = $this->on_date( $event_id, $date );
			if ( $occurrence ) {
				return $occurrence;
			}
		}
		return $this->for_event( $event_id );
	}

	/**
	 * Next occurrence that has not ended, else the most recent one.
	 */
	public function for_event( int $event_id ): ?Occurrence {
		if ( ! array_key_exists( $event_id, $this->event_memo ) ) {
			$this->for_events( array( $event_id ) );
		}
		return $this->event_memo[ $event_id ] ?? null;
	}

	/**
	 * Batch form of for_event() for list tables and archives: one query for all ids.
	 *
	 * @param int[] $event_ids Event ids.
	 * @return array<int, Occurrence|null> Keyed by event id.
	 */
	public function for_events( array $event_ids ): array {
		global $wpdb;
		Sync::instance()->flush();

		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		$missing   = array_values( array_diff( $event_ids, array_keys( $this->event_memo ) ) );
		if ( $missing ) {
			$table = Installer::occurrences_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE event_id IN (" . implode( ',', $missing ) . ') ORDER BY start_utc ASC' );
			$now  = gmdate( Meta::DATETIME_FORMAT );
			$pick = array_fill_keys( $missing, null );
			foreach ( $rows as $row ) {
				$id      = (int) $row->event_id;
				$current = $pick[ $id ];
				// Rows arrive oldest first: keep advancing while the kept row has ended,
				// so the result is the first unfinished row, or the latest past one.
				if ( null === $current || $current->end_utc < $now ) {
					$pick[ $id ] = $row;
				}
			}
			foreach ( $pick as $id => $row ) {
				$this->event_memo[ $id ] = $row ? Occurrence::from_row( $row ) : null;
			}
		}
		return array_intersect_key( $this->event_memo, array_flip( $event_ids ) );
	}

	/**
	 * Forgets memoised for_event() results, e.g. after a sync in the same request.
	 */
	public function forget( int $event_id ): void {
		unset( $this->event_memo[ $event_id ] );
	}

	/**
	 * Forgets all memoised results (long-running processes, tests).
	 */
	public function forget_all(): void {
		$this->event_memo = array();
	}

	/**
	 * Current UTC time floored to the minute, so "upcoming" queries share a cache key.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:00' );
	}

	public static function last_changed(): string {
		return wp_cache_get_last_changed( self::CACHE_GROUP );
	}

	public static function bump_cache(): void {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	public static function max_duration(): int {
		return max( DAY_IN_SECONDS, (int) get_option( Sync::MAX_DURATION_OPTION, 0 ) );
	}

	private static function to_utc_string( DateTimeInterface|string $value ): string {
		if ( $value instanceof DateTimeInterface ) {
			return \DateTimeImmutable::createFromInterface( $value )->setTimezone( new DateTimeZone( 'UTC' ) )->format( Meta::DATETIME_FORMAT );
		}
		return Meta::sanitize_datetime( $value );
	}

	/**
	 * Resolves category term ids (plus descendants) to term_taxonomy ids.
	 *
	 * @param int[] $term_ids Term ids.
	 * @return int[]|null Null when no category filter applies; empty when none matched.
	 */
	private static function category_tt_ids( array $term_ids ): ?array {
		$term_ids = array_filter( array_map( 'absint', $term_ids ) );
		if ( ! $term_ids ) {
			return null;
		}
		$all = $term_ids;
		foreach ( $term_ids as $term_id ) {
			$children = get_term_children( $term_id, PostTypes::CATEGORY );
			if ( is_array( $children ) ) {
				$all = array_merge( $all, $children );
			}
		}
		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'include'    => array_unique( $all ),
				'hide_empty' => false,
				'fields'     => 'tt_ids',
			)
		);
		return is_array( $terms ) ? array_map( 'intval', $terms ) : array();
	}
}
