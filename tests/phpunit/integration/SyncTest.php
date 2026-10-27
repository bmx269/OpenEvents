<?php
/**
 * Occurrence generation and sync.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\Event;
use OpenEvents\Occurrences\Generator;
use OpenEvents\Occurrences\Sync;
use OpenEvents\Tests\TestCase;

final class SyncTest extends TestCase {

	public function test_event_gets_one_occurrence_with_local_and_utc_times(): void {
		$id   = $this->event(
			array(
				'_oe_start' => '2030-07-01 19:00:00',
				'_oe_end'   => '2030-07-01 21:00:00',
			)
		);
		$rows = $this->rows( $id );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2030-07-01 19:00:00', $rows[0]->start_local );
		// Vancouver is UTC-7 in July.
		$this->assertSame( '2030-07-02 02:00:00', $rows[0]->start_utc );
		$this->assertSame( 'publish', $rows[0]->status );
		$this->assertSame( 'oe_event', $rows[0]->post_type );
	}

	public function test_event_timezone_overrides_site_timezone(): void {
		$id   = $this->event(
			array(
				'_oe_start'    => '2030-01-15 18:00:00',
				'_oe_end'      => '2030-01-15 19:00:00',
				'_oe_timezone' => 'America/Toronto',
			)
		);
		$rows = $this->rows( $id );

		$this->assertSame( '2030-01-15 18:00:00', $rows[0]->start_local );
		$this->assertSame( '2030-01-15 23:00:00', $rows[0]->start_utc ); // Toronto is UTC-5 in January.
		$this->assertSame( 'America/Toronto', $rows[0]->timezone );
	}

	public function test_all_day_spans_whole_days(): void {
		$id   = $this->event(
			array(
				'_oe_start'   => '2030-03-10 14:00:00',
				'_oe_end'     => '2030-03-12 09:00:00',
				'_oe_all_day' => true,
			)
		);
		$rows = $this->rows( $id );

		$this->assertSame( '2030-03-10 00:00:00', $rows[0]->start_local );
		$this->assertSame( '2030-03-12 23:59:59', $rows[0]->end_local );
		$this->assertSame( '1', (string) $rows[0]->all_day );
	}

	public function test_end_before_start_is_clamped_to_start(): void {
		$id   = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 09:00:00',
			)
		);
		$rows = $this->rows( $id );

		$this->assertSame( $rows[0]->start_local, $rows[0]->end_local );
	}

	public function test_moving_an_event_keeps_its_occurrence_id(): void {
		$id     = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			)
		);
		$before = $this->rows( $id )[0]->id;

		update_post_meta( $id, '_oe_start', '2030-06-06 10:00:00' );
		update_post_meta( $id, '_oe_end', '2030-06-06 11:00:00' );
		$after = $this->rows( $id );

		$this->assertCount( 1, $after );
		$this->assertSame( $before, $after[0]->id );
		$this->assertSame( '2030-06-06 10:00:00', $after[0]->start_local );
	}

	public function test_status_changes_are_copied_to_rows(): void {
		$id = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			)
		);

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'draft', $this->rows( $id )[0]->status );

		wp_trash_post( $id );
		$this->assertSame( 'trash', $this->rows( $id )[0]->status );
	}

	public function test_deleting_an_event_removes_its_rows(): void {
		$id = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			)
		);
		wp_delete_post( $id, true );

		$this->assertSame( array(), $this->rows( $id ) );
	}

	public function test_removing_the_start_date_removes_the_occurrence(): void {
		$id = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			)
		);
		delete_post_meta( $id, '_oe_start' );

		$this->assertSame( array(), $this->rows( $id ) );
	}

	public function test_posts_in_types_that_are_not_event_enabled_are_ignored(): void {
		$id = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			),
			array( 'post_type' => 'post' )
		);

		$this->assertSame( array(), $this->rows( $id ) );
	}

	public function test_existing_post_type_gets_rows_only_for_posts_with_dates(): void {
		$this->settings( array( 'event_post_types' => array( 'post' ) ) );

		$plain = $this->event( array(), array( 'post_type' => 'post' ) );
		$event = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			),
			array( 'post_type' => 'post' )
		);

		$this->assertSame( array(), $this->rows( $plain ) );
		$this->assertCount( 1, $this->rows( $event ) );
		$this->assertSame( 'post', $this->rows( $event )[0]->post_type );
		$this->assertNull( openevents_get_event( $plain ), 'A post without a date is not an event.' );
		$this->assertNotNull( openevents_get_event( $event ) );
	}

	public function test_generate_filter_can_add_occurrences_with_stable_ids(): void {
		// Simulates the recurrence module: three weekly dates.
		$weekly = static function ( array $rows, Event $event ): array {
			if ( ! $rows ) {
				return $rows;
			}
			$start = $event->start();
			$end   = $event->end();
			for ( $i = 1; $i < 3; $i++ ) {
				$rows[ $i ] = Generator::row( $event, $i, $start->modify( "+{$i} weeks" ), $end->modify( "+{$i} weeks" ) );
			}
			return $rows;
		};
		add_filter( 'openevents/occurrences/generate', $weekly, 10, 2 );

		$id    = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			)
		);
		$first = wp_list_pluck( $this->rows( $id ), 'id' );
		$this->assertCount( 3, $first );
		$this->assertSame( '2030-05-19 10:00:00', $this->rows( $id )[2]->start_local );

		update_post_meta( $id, '_oe_start', '2030-05-05 12:00:00' );
		$this->assertSame( $first, wp_list_pluck( $this->rows( $id ), 'id' ), 'Re-syncing keeps ids per sequence.' );

		remove_filter( 'openevents/occurrences/generate', $weekly, 10 );
	}

	public function test_syncs_are_deferred_until_read(): void {
		global $wpdb;
		$id = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			)
		);
		$this->rows( $id );

		$writes = 0;
		$count  = static function ( string $sql ) use ( &$writes ): string {
			if ( preg_match( '/^\s*(UPDATE|INSERT)\b.*oe_occurrences/i', $sql ) ) {
				++$writes;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		// Four schedule keys change in one save, as in a REST request.
		update_post_meta( $id, '_oe_start', '2030-05-06 10:00:00' );
		update_post_meta( $id, '_oe_end', '2030-05-06 11:00:00' );
		update_post_meta( $id, '_oe_timezone', 'America/Vancouver' );
		update_post_meta( $id, '_oe_all_day', false );
		Sync::instance()->flush();
		remove_filter( 'query', $count );

		$this->assertSame( 1, $writes, 'One row write for several meta changes.' );
	}

	public function test_unchanged_save_writes_nothing(): void {
		$id = $this->event(
			array(
				'_oe_start' => '2030-05-05 10:00:00',
				'_oe_end'   => '2030-05-05 11:00:00',
			)
		);
		$this->rows( $id );

		$writes = 0;
		$count  = static function ( string $sql ) use ( &$writes ): string {
			if ( preg_match( '/^\s*(UPDATE|INSERT|DELETE)\b.*oe_occurrences/i', $sql ) ) {
				++$writes;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		Sync::instance()->sync( $id );
		remove_filter( 'query', $count );

		$this->assertSame( 0, $writes );
	}

	public function test_max_duration_tracks_the_longest_occurrence(): void {
		$this->event(
			array(
				'_oe_start' => '2030-05-01 00:00:00',
				'_oe_end'   => '2030-05-11 00:00:00',
			)
		);
		$this->rows( 0 );

		$this->assertSame( 10 * DAY_IN_SECONDS, (int) get_option( Sync::MAX_DURATION_OPTION ) );
	}

	public function test_rebuild_all_restores_a_truncated_table(): void {
		global $wpdb;
		$ids = array(
			$this->event( array( '_oe_start' => '2030-05-01 10:00:00' ) ),
			$this->event( array( '_oe_start' => '2030-05-02 10:00:00' ) ),
		);
		$this->rows( $ids[0] );
		$wpdb->query( 'DELETE FROM ' . \OpenEvents\Installer::occurrences_table() ); // phpcs:ignore WordPress.DB

		$this->assertSame( 2, Sync::instance()->rebuild_all( 1 ) );
		$this->assertCount( 1, $this->rows( $ids[0] ) );
		$this->assertCount( 1, $this->rows( $ids[1] ) );
	}
}
