<?php
/**
 * Editing one date, or the rest of a series.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Recurrence;

use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;
use OpenEvents\Occurrences\Occurrence;
use WP_Error;

/**
 * The three editing scopes, as calendar apps offer them:
 *
 * - All dates: edit the event itself.
 * - This date only (detach): the date becomes its own event and is skipped in
 *   the series.
 * - This and following (split): the series ends the day before, and a new event
 *   carries the rule from this date on.
 *
 * New events record where they came from in `_oe_series_parent`, so a series
 * can be listed (or re-joined) later.
 */
final class Series {

	public const PARENT_KEY = '_oe_series_parent';

	/** Meta that belongs to one post and must not be copied. */
	private const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', Recurrence::META_KEY, self::PARENT_KEY );

	/**
	 * Skips one date of a series.
	 */
	public static function skip( Event $event, Occurrence $occurrence ): true|WP_Error {
		$recurrence = Recurrence::for_event( $event );
		if ( ! $recurrence ) {
			return new WP_Error( 'openevents_not_recurring', __( 'This event does not repeat.', 'openevents' ), array( 'status' => 400 ) );
		}
		$exdates   = $recurrence->exdates;
		$exdates[] = $occurrence->start->format( 'Y-m-d' );
		update_post_meta( $event->id(), Recurrence::META_KEY, $recurrence->with( array( 'exdates' => $exdates ) )->to_meta() );
		return true;
	}

	/**
	 * Turns one date into a separate event and skips it in the series.
	 *
	 * @return int|WP_Error New event id.
	 */
	public static function detach( Event $event, Occurrence $occurrence ): int|WP_Error {
		$recurrence = Recurrence::for_event( $event );
		if ( ! $recurrence ) {
			return new WP_Error( 'openevents_not_recurring', __( 'This event does not repeat.', 'openevents' ), array( 'status' => 400 ) );
		}

		$id = self::copy( $event, $occurrence, Recurrence::empty_meta() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::skip( $event, $occurrence );
		return $id;
	}

	/**
	 * Ends the series before this date and starts a new one from it.
	 *
	 * @return int|WP_Error New event id.
	 */
	public static function split( Event $event, Occurrence $occurrence ): int|WP_Error {
		$recurrence = Recurrence::for_event( $event );
		$start      = $event->start();
		if ( ! $recurrence || ! $start ) {
			return new WP_Error( 'openevents_not_recurring', __( 'This event does not repeat.', 'openevents' ), array( 'status' => 400 ) );
		}
		if ( 0 === $occurrence->sequence || $occurrence->start <= $start ) {
			return new WP_Error( 'openevents_split_first', __( 'This is the first date. Edit the event itself to change every date.', 'openevents' ), array( 'status' => 400 ) );
		}

		$date   = $occurrence->start->format( 'Y-m-d' );
		$before = static fn( string $value ): bool => substr( $value, 0, 10 ) < $date;
		$tail   = null;
		$head   = null;
		if ( $recurrence->rule ) {
			$rule = $recurrence->rule;
			// COUNT carries over as the dates still to come; UNTIL stays as it was.
			$used = count( $rule->starts( $start, $occurrence->start->modify( '-1 second' ) ) );
			$tail = null !== $rule->count ? $rule->with( array( 'count' => max( 1, $rule->count - $used ) ) ) : $rule;
			$head = $rule->with(
				array(
					'count' => null,
					'until' => $occurrence->start->modify( '-1 day' )->format( 'Ymd' ),
				)
			);
		}

		$new = new Recurrence(
			$tail,
			array_values( array_filter( $recurrence->rdates, static fn( $d ) => ! $before( $d ) ) ),
			array_values( array_filter( $recurrence->exdates, static fn( $d ) => ! $before( $d ) ) )
		);
		$id  = self::copy( $event, $occurrence, $new->to_meta() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$old = new Recurrence(
			$head,
			array_values( array_filter( $recurrence->rdates, $before ) ),
			array_values( array_filter( $recurrence->exdates, $before ) )
		);
		update_post_meta( $event->id(), Recurrence::META_KEY, $old->to_meta() );
		return $id;
	}

	/**
	 * Copies the event (fields, meta, terms) to a new post starting at the
	 * occurrence's date and time.
	 *
	 * @param array<string, mixed> $recurrence Recurrence meta for the copy.
	 * @return int|WP_Error
	 */
	private static function copy( Event $event, Occurrence $occurrence, array $recurrence ): int|WP_Error {
		$post = $event->post;
		$id   = wp_insert_post(
			array(
				'post_type'    => $post->post_type,
				'post_status'  => $post->post_status,
				'post_author'  => $post->post_author,
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_parent'  => $post->post_parent,
				'menu_order'   => $post->menu_order,
				'post_password' => $post->post_password,
				'comment_status' => $post->comment_status,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $id, $key, maybe_unserialize( $value ) );
			}
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $id, $terms, $taxonomy );
			}
		}

		$root = (int) get_post_meta( $post->ID, self::PARENT_KEY, true ) ?: $post->ID;
		update_post_meta( $id, self::PARENT_KEY, $root );
		update_post_meta( $id, '_oe_start', $occurrence->start->format( Meta::DATETIME_FORMAT ) );
		update_post_meta( $id, '_oe_end', $occurrence->end->format( Meta::DATETIME_FORMAT ) );
		update_post_meta( $id, Recurrence::META_KEY, $recurrence );

		/**
		 * Fires after a date or the rest of a series is copied to a new event.
		 * Modules with per-event data (RSVPs, tickets) can move it here.
		 *
		 * @param int        $id         New event id.
		 * @param Event      $event      Original event.
		 * @param Occurrence $occurrence Date the new event starts on.
		 */
		do_action( 'openevents/recurrence/copied', $id, $event, $occurrence );
		return $id;
	}
}
