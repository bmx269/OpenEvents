<?php
/**
 * Recurrence module.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Recurrence;

use DateTimeImmutable;
use OpenEvents\Content\Event;
use OpenEvents\Modules\AbstractModule;
use OpenEvents\Occurrences\Generator;
use OpenEvents\Occurrences\Occurrence;
use OpenEvents\Occurrences\Sync;

/**
 * Repeating events. A rule expands into rows in the occurrences table, so
 * calendars, listings, search, and the REST API see every date with no extra
 * queries.
 *
 * Open-ended rules are expanded up to a rolling horizon (two years by default),
 * and a daily job moves the horizon forward.
 */
final class Module extends AbstractModule {

	public const CRON_HOOK = 'openevents_recurrence_extend';

	/** Slots per day in a sequence number; see sequence(). */
	private const SLOTS = 8;

	public function id(): string {
		return 'recurrence';
	}

	public function label(): string {
		return __( 'Recurring events', 'openevents' );
	}

	public function description(): string {
		return __( 'Repeat events daily, weekly, monthly, or yearly, skip or add dates, and edit one date or the rest of a series.', 'openevents' );
	}

	public function boot(): void {
		add_filter( 'openevents/meta_fields', array( $this, 'meta_fields' ), 10, 2 );
		add_filter( 'openevents/occurrences/generate', array( self::class, 'expand' ), 10, 2 );
		add_filter( 'openevents/event_details/rows', array( $this, 'detail_rows' ), 10, 3 );
		add_action( self::CRON_HOOK, array( self::class, 'extend_all' ) );
		add_action( 'init', array( self::class, 'schedule_cron' ) );
		( new Rest() )->register();
		add_action( 'openevents/uninstall', array( self::class, 'unschedule_cron' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $fields    Meta fields.
	 * @param string                              $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	public function meta_fields( array $fields, string $post_type ): array {
		if ( ! in_array( $post_type, openevents_event_post_types(), true ) ) {
			return $fields;
		}
		$fields[ Series::PARENT_KEY ] = array(
			'type'              => 'integer',
			'description'       => __( 'The event this one was split or detached from.', 'openevents' ),
			'sanitize_callback' => 'absint',
		);
		$fields[ Recurrence::META_KEY ] = array(
			'type'              => 'object',
			'default'           => Recurrence::empty_meta(),
			'sanitize_callback' => array( Recurrence::class, 'sanitize' ),
			'show_in_rest'      => array(
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'rrule'   => array(
							'description' => __( 'RFC 5545 RRULE, e.g. FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20271231. Empty for no repeat.', 'openevents' ),
							'type'        => 'string',
						),
						'rdates'  => array(
							'description' => __( 'Extra start times, "Y-m-d H:i:s" in the event timezone.', 'openevents' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'exdates' => array(
							'description' => __( 'Skipped dates, "Y-m-d".', 'openevents' ),
							'type'        => 'array',
							'items'       => array(
								'type'   => 'string',
								'format' => 'date',
							),
						),
					),
					'additionalProperties' => false,
				),
			),
		);
		return $fields;
	}

	/**
	 * `openevents/occurrences/generate`: one row per date.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows from the event's own dates.
	 * @param Event                            $event Event.
	 * @return array<int, array<string, mixed>>
	 */
	public static function expand( array $rows, Event $event ): array {
		$recurrence = Recurrence::for_event( $event );
		$start      = $event->start();
		$end        = $event->end();
		if ( ! $recurrence || ! $start || ! $end || ! $rows ) {
			return $rows;
		}

		if ( $event->is_all_day() ) {
			$start = $start->setTime( 0, 0, 0 );
			$end   = $end->setTime( 23, 59, 59 );
		}
		$length = self::length( $start, $end );
		$out    = array();
		$slots  = array();
		foreach ( $recurrence->starts( $start, self::horizon( $start ) ) as $date ) {
			$sequence = self::sequence( $date, $start, $slots );
			if ( null !== $sequence ) {
				$out[ $sequence ] = Generator::row( $event, $sequence, $date, self::end_for( $date, $length ) );
			}
		}
		return $out;
	}

	/**
	 * Wall-clock length in seconds (an event from 7pm to 9pm lasts 2 hours on
	 * every date, including the night the clocks change).
	 */
	public static function length( DateTimeImmutable $start, DateTimeImmutable $end ): int {
		return self::wall( $end ) - self::wall( $start );
	}

	/**
	 * End of a date that starts at $start and lasts $length wall-clock seconds.
	 */
	public static function end_for( DateTimeImmutable $start, int $length ): DateTimeImmutable {
		return new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', self::wall( $start ) + $length ), $start->getTimezone() );
	}

	private static function wall( DateTimeImmutable $date ): int {
		return ( new DateTimeImmutable( $date->format( 'Y-m-d H:i:s' ), new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	/**
	 * Stable sequence numbers, so changing one date never renumbers the others
	 * (attendees and links refer to occurrences by id). The event's own start is
	 * 0, as for a single event; other dates are keyed by their local day, with a
	 * few slots for several dates on one day.
	 *
	 * @param array<int, int> $slots Slots used per day so far.
	 */
	private static function sequence( DateTimeImmutable $date, DateTimeImmutable $dtstart, array &$slots ): ?int {
		if ( $date == $dtstart ) { // phpcs:ignore Universal.Operators.StrictComparisons -- compares instants.
			return 0;
		}
		$day  = intdiv( (int) ( new DateTimeImmutable( $date->format( 'Y-m-d' ), new \DateTimeZone( 'UTC' ) ) )->getTimestamp(), DAY_IN_SECONDS );
		$slot = $slots[ $day ] ?? 0;
		if ( $slot >= self::SLOTS || $day < 0 ) {
			return null;
		}
		$slots[ $day ] = $slot + 1;
		return $day * self::SLOTS + $slot + 1;
	}

	/**
	 * Last date to expand: the horizon from today, or from the start for series
	 * that begin later.
	 */
	public static function horizon( DateTimeImmutable $start ): DateTimeImmutable {
		/**
		 * Filters how far ahead repeats are expanded.
		 *
		 * @param string $modifier A DateTime modifier, e.g. "+2 years", applied to
		 *                         today or the series start, whichever is later.
		 */
		$modifier = (string) apply_filters( 'openevents/recurrence/horizon', '+2 years' );
		$now      = new DateTimeImmutable( 'now', $start->getTimezone() );
		return ( $start > $now ? $start : $now )->modify( $modifier );
	}

	/**
	 * @param array<string, array{label: string, html: string}> $rows       Detail rows.
	 * @param Event                                             $event      Event.
	 * @param Occurrence                                        $occurrence Occurrence shown.
	 * @return array<string, array{label: string, html: string}>
	 */
	public function detail_rows( array $rows, Event $event, Occurrence $occurrence ): array {
		$recurrence = Recurrence::for_event( $event );
		$start      = $event->start();
		if ( ! $recurrence || ! $start ) {
			return $rows;
		}

		$html = $recurrence->rule ? esc_html( Describer::describe( $recurrence->rule, $start ) ) : esc_html__( 'On several dates', 'openevents' );

		$next = openevents()->occurrences()->query(
			array(
				'event_ids'      => array( $event->id() ),
				'ends_after'     => \OpenEvents\Occurrences\Repository::now(),
				'include_hidden' => true,
				'per_page'       => 6,
				'count'          => false,
				'prime'          => false,
			)
		)['items'];
		$next = array_values( array_filter( $next, static fn( Occurrence $o ) => $o->id !== $occurrence->id ) );
		if ( $next ) {
			$items = '';
			foreach ( array_slice( $next, 0, 5 ) as $other ) {
				$items .= sprintf(
					'<li><a href="%s"><time datetime="%s">%s</time></a></li>',
					esc_url( openevents_occurrence_url( $other ) ),
					esc_attr( \OpenEvents\Frontend\Formatter::iso( $other->start, $other->all_day ) ),
					esc_html( \OpenEvents\Frontend\Formatter::range( $other ) )
				);
			}
			$html .= '<ul class="oe-details__dates" aria-label="' . esc_attr__( 'Other dates', 'openevents' ) . '">' . $items . '</ul>';
		}

		// Right after the date.
		$repeat = array(
			'repeats' => array(
				'label' => __( 'Repeats', 'openevents' ),
				'html'  => $html,
			),
		);
		$at = array_search( 'date', array_keys( $rows ), true );
		return false === $at
			? $repeat + $rows
			: array_slice( $rows, 0, $at + 1, true ) + $repeat + array_slice( $rows, $at + 1, null, true );
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Daily: re-syncs repeating events so the horizon moves forward (rules that
	 * end after the horizon are cut off there too, not only open-ended ones).
	 * Syncs only write rows that changed, so this is cheap.
	 */
	public static function extend_all(): void {
		global $wpdb;
		$last_id = 0;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d AND meta_value LIKE %s ORDER BY post_id LIMIT 200", Recurrence::META_KEY, $last_id, '%FREQ=%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $ids as $id ) {
				$last_id = (int) $id;
				if ( Event::get( (int) $id ) ) {
					Sync::instance()->sync( (int) $id );
				}
			}
		} while ( count( $ids ) === 200 );
	}
}
