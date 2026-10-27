<?php
/**
 * Keeps oe_occurrences in step with event posts and meta.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Occurrences;

use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;
use OpenEvents\Installer;

/**
 * Rewrites an event's occurrences whenever its schedule or status can have changed.
 *
 * Every write path is covered: save_post, the REST/editor flow (meta is written
 * after save_post), status transitions (scheduled publishing, trash), and direct
 * meta updates from WP-CLI or importers.
 *
 * Syncs are deferred and de-duplicated: a REST save touches several schedule
 * keys, and each would otherwise rewrite the rows. Pending syncs run once, before
 * the next occurrence read in the same request (so reads are never stale) or at
 * shutdown, whichever comes first.
 */
final class Sync {

	/** Longest occurrence seen, in seconds. Bounds range scans (see Repository). */
	public const MAX_DURATION_OPTION = 'open_events_calendar_max_duration';

	private static ?Sync $instance = null;

	/** @var array<int, true> Event ids awaiting sync. */
	private array $pending = array();

	private bool $suspended = false;

	private Generator $generator;

	public function __construct( ?Generator $generator = null ) {
		$this->generator = $generator ?? new Generator();
		self::$instance  = $this;
	}

	public static function instance(): Sync {
		return self::$instance ?? new self();
	}

	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		// Event-enabled types can come from other plugins, so hook their REST inserts once all are registered.
		add_action( 'init', array( $this, 'register_rest_hooks' ), 60 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'delete' ) );
		add_action( 'shutdown', array( $this, 'flush' ), 0 );
		// Anything shown in cached listings invalidates them: category assignment and
		// colours, venue/organizer details, display settings, and enabled modules.
		add_action( 'set_object_terms', array( Repository::class, 'bump_cache' ) );
		add_action( 'saved_' . \OpenEvents\Content\PostTypes::CATEGORY, array( Repository::class, 'bump_cache' ) );
		add_action( 'delete_' . \OpenEvents\Content\PostTypes::CATEGORY, array( Repository::class, 'bump_cache' ) );
		add_action( 'save_post_' . \OpenEvents\Content\PostTypes::VENUE, array( Repository::class, 'bump_cache' ) );
		add_action( 'save_post_' . \OpenEvents\Content\PostTypes::ORGANIZER, array( Repository::class, 'bump_cache' ) );
		add_action( 'update_option_' . \OpenEvents\Settings::OPTION, array( Repository::class, 'bump_cache' ) );
		add_action( 'update_option_' . \OpenEvents\Settings::MODULES_OPTION, array( Repository::class, 'bump_cache' ) );
		add_action( 'update_option_date_format', array( Repository::class, 'bump_cache' ) );
		add_action( 'update_option_time_format', array( Repository::class, 'bump_cache' ) );
		add_action( 'update_option_start_of_week', array( Repository::class, 'bump_cache' ) );
	}

	public function register_rest_hooks(): void {
		foreach ( open_events_calendar()->settings()->event_post_types() as $post_type ) {
			add_action( 'rest_after_insert_' . $post_type, array( $this, 'on_rest_insert' ) );
		}
	}

	public function on_save( int $post_id, \WP_Post $post ): void {
		if ( Event::is_event_type( $post->post_type ) && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
			$this->schedule( $post_id );
			// Title, excerpt, or image may have changed even if the schedule did not.
			Repository::bump_cache();
		}
	}

	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status !== $old_status && Event::is_event_type( $post->post_type ) ) {
			$this->schedule( $post->ID );
		}
	}

	public function on_rest_insert( \WP_Post $post ): void {
		$this->schedule( $post->ID );
	}

	/**
	 * @param int|int[] $meta_ids Meta id(s); unused.
	 */
	public function on_meta_change( int|array $meta_ids, int $post_id, string $meta_key ): void {
		if ( in_array( $meta_key, Meta::SCHEDULE_KEYS, true ) && Event::is_event_type( (string) get_post_type( $post_id ) ) ) {
			$this->schedule( $post_id );
		}
	}

	public function schedule( int $event_id ): void {
		$this->pending[ $event_id ] = true;
	}

	/**
	 * Runs pending syncs. Called before reads and at shutdown.
	 */
	public function flush(): void {
		if ( $this->suspended || ! $this->pending ) {
			return;
		}
		$ids           = array_keys( $this->pending );
		$this->pending = array();
		foreach ( $ids as $id ) {
			$this->sync( $id );
		}
	}

	/**
	 * Pauses syncing for bulk imports. Call resume() afterwards, then
	 * `wp open-events rebuild` (or sync each id) to write the rows.
	 */
	public function suspend(): void {
		$this->suspended = true;
	}

	public function resume(): void {
		$this->suspended = false;
	}

	/**
	 * Diffs generated rows against stored rows by sequence: updates changed rows in
	 * place (keeping ids stable for attendees), inserts new sequences, and deletes
	 * sequences that disappeared. Unchanged rows are not written.
	 */
	public function sync( int $event_id ): void {
		global $wpdb;

		unset( $this->pending[ $event_id ] );
		open_events_calendar()->occurrences()->forget( $event_id );
		$event = Event::get( $event_id );
		if ( ! $event ) {
			return;
		}

		$table  = Installer::occurrences_table();
		$wanted = $this->generator->generate( $event );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d", $event_id ), ARRAY_A );

		$by_sequence = array();
		foreach ( $existing as $row ) {
			$by_sequence[ (int) $row['sequence'] ] = $row;
		}

		$changed      = false;
		$max_duration = 0;
		foreach ( $wanted as $sequence => $row ) {
			$row['sequence']  = (int) $sequence;
			$row['post_type'] = $event->post->post_type;
			$row['status']    = $event->post->post_status;
			$max_duration     = max( $max_duration, strtotime( $row['end_utc'] . ' UTC' ) - strtotime( $row['start_utc'] . ' UTC' ) );

			if ( isset( $by_sequence[ $sequence ] ) ) {
				$current = $by_sequence[ $sequence ];
				unset( $by_sequence[ $sequence ] );
				if ( array_diff_assoc( array_map( 'strval', $row ), array_intersect_key( $current, $row ) ) ) {
					$wpdb->update( $table, $row, array( 'id' => $current['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$changed = true;
				}
			} else {
				$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$changed = true;
			}
		}

		if ( $by_sequence ) {
			$ids = implode( ',', array_map( static fn( $r ) => (int) $r['id'], $by_sequence ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$changed = true;
		}

		if ( $max_duration > (int) get_option( self::MAX_DURATION_OPTION, 0 ) ) {
			update_option( self::MAX_DURATION_OPTION, $max_duration );
		}

		if ( $changed ) {
			Repository::bump_cache();

			/**
			 * Fires after an event's occurrences change.
			 *
			 * @param int $event_id Event id.
			 */
			do_action( 'open_events_calendar/occurrences/synced', $event_id );
		}
	}

	public function delete( int $post_id ): void {
		global $wpdb;
		unset( $this->pending[ $post_id ] );
		$deleted = $wpdb->delete( Installer::occurrences_table(), array( 'event_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $deleted ) {
			Repository::bump_cache();
		}
	}

	/**
	 * Rebuilds every event's rows in batches and recomputes the max duration.
	 * Used by WP-CLI and after bulk imports.
	 *
	 * @param callable|null $progress Called with ( done, total ) after each batch.
	 */
	public function rebuild_all( int $batch = 500, ?callable $progress = null ): int {
		global $wpdb;
		$types = open_events_calendar()->settings()->event_post_types();
		if ( ! $types ) {
			return 0;
		}
		$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status NOT IN ('auto-draft','inherit')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( self::MAX_DURATION_OPTION, 0 );
		$done    = 0;
		$last_id = 0;
		do {
			// Keyset pagination: stable and fast at any offset.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status NOT IN ('auto-draft','inherit') AND ID > %d ORDER BY ID LIMIT %d", $last_id, $batch ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $ids ) {
				break;
			}
			_prime_post_caches( $ids, false, true );
			foreach ( $ids as $id ) {
				$this->sync( (int) $id );
			}
			$last_id = (int) end( $ids );
			$done   += count( $ids );
			if ( $progress ) {
				$progress( $done, $total );
			}
			// Keep memory flat on very large sites.
			wp_cache_flush_runtime();
		} while ( count( $ids ) === $batch );

		Repository::bump_cache();
		return $done;
	}
}
