<?php
/**
 * Calendar block views.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Frontend;

use DateTimeImmutable;
use OpenEvents\Occurrences\Occurrence;
use OpenEvents\Occurrences\Repository;

/**
 * Server-renders the calendar block's month and list views.
 *
 * Navigation is plain links with query args, so it works with JavaScript off,
 * behind page caches, and without the REST API. The Interactivity API upgrades
 * those links to in-place updates. Navigation URLs are noindexed and limited
 * to a fixed range so crawlers cannot walk an endless chain of months.
 */
final class CalendarView {

	public const PARAMS = array( 'oe_view', 'oe_month', 'oe_date', 'oe_page', 'oe_past' );

	/** How far from today month navigation may go, in years. */
	private const RANGE_YEARS = 5;

	private static int $instance = 0;

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function __construct( private array $attributes ) {}

	/**
	 * @return array<string, callable> View id => renderer( CalendarView, array $state ): string.
	 */
	public static function views(): array {
		$views = array(
			'month' => array( self::class, 'render_month' ),
			'list'  => array( self::class, 'render_list' ),
		);

		/**
		 * Filters calendar views. Add-ons can register a renderer (callable that
		 * receives the CalendarView and state array and returns HTML).
		 *
		 * @param array<string, callable> $views View id => renderer.
		 */
		return apply_filters( 'openevents/calendar/views', $views );
	}

	public static function view_label( string $id ): string {
		$labels = array(
			'month' => __( 'Month', 'openevents' ),
			'list'  => __( 'List', 'openevents' ),
		);
		return $labels[ $id ] ?? ucfirst( $id );
	}

	public function render(): string {
		++self::$instance;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation params.
		$params = array_intersect_key( wp_unslash( $_GET ), array_flip( self::PARAMS ) );
		$key    = 'cal:' . md5(
			wp_json_encode(
				array(
					$this->attributes,
					$params,
					self::$instance,
					strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), '?' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					get_locale(),
					Repository::now(), // "Upcoming" and the today marker move with time.
				)
			)
		) . ':' . Repository::last_changed();

		/**
		 * Filters whether rendered calendar HTML is cached in the object cache.
		 * Disable if you add per-user output (for example "you're going" badges).
		 *
		 * @param bool  $cache      Whether to cache.
		 * @param array $attributes Block attributes.
		 */
		$use_cache = apply_filters( 'openevents/calendar/cache', true, $this->attributes );
		if ( $use_cache ) {
			$html = wp_cache_get( $key, Repository::CACHE_GROUP );
			if ( is_string( $html ) ) {
				return $html;
			}
		}

		$html = $this->render_uncached();
		if ( $use_cache ) {
			wp_cache_set( $key, $html, Repository::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		return $html;
	}

	private function render_uncached(): string {
		$views   = self::views();
		$enabled = array_values( array_intersect( (array) ( $this->attributes['views'] ?? array( 'month', 'list' ) ), array_keys( $views ) ) ) ?: array( 'month' );
		$wanted  = (string) ( $this->attributes['defaultView'] ?? '' ) ?: (string) openevents()->settings()->get( 'default_view' );
		$default = in_array( $wanted, $enabled, true ) ? $wanted : $enabled[0];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$requested = sanitize_key( wp_unslash( $_GET['oe_view'] ?? '' ) );
		$state     = array(
			'view'       => in_array( $requested, $enabled, true ) ? $requested : $default,
			'month'      => self::parse_month( (string) wp_unslash( $_GET['oe_month'] ?? '' ) ),
			'date'       => self::parse_date( (string) wp_unslash( $_GET['oe_date'] ?? '' ) ),
			'page'       => max( 1, absint( $_GET['oe_page'] ?? 1 ) ),
			'past'       => ! empty( $_GET['oe_past'] ),
			'categories' => array_map( 'absint', (array) ( $this->attributes['categories'] ?? array() ) ),
			'enabled'    => $enabled,
		);
		// phpcs:enable

		$region = 'oe-calendar-' . self::$instance;

		$switcher = '';
		if ( count( $enabled ) > 1 ) {
			$switcher = '<div class="oe-calendar__views" role="group" aria-label="' . esc_attr__( 'Calendar view', 'openevents' ) . '">';
			foreach ( $enabled as $view ) {
				$switcher .= sprintf(
					'<a class="oe-calendar__view" href="%s" rel="nofollow" data-wp-on--click="actions.navigate"%s>%s</a>',
					esc_url( self::url( array( 'oe_view' => $view ) ) ),
					$view === $state['view'] ? ' aria-current="page"' : '',
					esc_html( self::view_label( $view ) )
				);
			}
			$switcher .= '</div>';
		}
		// Views place this in their header row, next to their own navigation.
		$state['switcher'] = $switcher;

		$body = call_user_func( $views[ $state['view'] ], $this, $state );

		/**
		 * Filters links shown under the calendar (iCal subscribe, etc.).
		 *
		 * @param array<int, array{label: string, url: string}> $links Links.
		 */
		$links  = apply_filters( 'openevents/calendar/links', array() );
		$footer = '';
		if ( $links ) {
			$footer = '<ul class="oe-calendar__links">';
			foreach ( $links as $link ) {
				$footer .= sprintf( '<li><a href="%s" rel="nofollow">%s</a></li>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
			}
			$footer .= '</ul>';
		}

		return sprintf(
			'<div class="oe-calendar oe-calendar--%1$s" data-wp-interactive="openevents/calendar" data-wp-router-region="%2$s" id="%2$s">%3$s%4$s%5$s</div>',
			esc_attr( $state['view'] ),
			esc_attr( $region ),
			$body,
			! empty( $this->attributes['showLegend'] ) ? self::legend( $state['categories'] ) : '',
			$footer
		);
	}

	public function heading_tag(): string {
		$level = (int) ( $this->attributes['headingLevel'] ?? 2 );
		return 'h' . ( $level >= 1 && $level <= 6 ? $level : 2 );
	}

	/**
	 * @param array<string, mixed> $state View state.
	 */
	public static function render_month( CalendarView $view, array $state ): string {
		$month = $state['month'];
		$sow   = (int) get_option( 'start_of_week', 1 );

		$first      = $month;
		$last       = $month->modify( 'last day of this month' );
		$grid_start = $first->modify( '-' . ( ( (int) $first->format( 'w' ) - $sow + 7 ) % 7 ) . ' days' );
		$grid_end   = $last->modify( '+' . ( ( $sow + 6 - (int) $last->format( 'w' ) + 7 ) % 7 ) . ' days' );

		$result = openevents()->occurrences()->query(
			array(
				'local_from' => $grid_start->format( 'Y-m-d 00:00:00' ),
				'local_to'   => $grid_end->format( 'Y-m-d 23:59:59' ),
				'categories' => $state['categories'],
				'per_page'   => -1,
				'count'      => false,
			)
		);

		$days = array();
		foreach ( $result['items'] as $occurrence ) {
			$day  = max( $occurrence->start->format( 'Y-m-d' ), $grid_start->format( 'Y-m-d' ) );
			$stop = min( $occurrence->end->format( 'Y-m-d' ), $grid_end->format( 'Y-m-d' ) );
			while ( $day <= $stop ) {
				$days[ $day ][] = $occurrence;
				$day            = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
			}
		}

		$min   = self::range_start();
		$max   = self::range_end();
		$prev  = $month->modify( '-1 month' );
		$next  = $month->modify( '+1 month' );
		$title = wp_date( 'F Y', $month->getTimestamp(), wp_timezone() );
		$tag   = $view->heading_tag();

		$nav  = '<div class="oe-calendar__header">';
		$nav .= sprintf( '<%1$s class="oe-calendar__title" tabindex="-1">%2$s</%1$s>', $tag, esc_html( $title ) );
		$nav .= '<div class="oe-calendar__tools">' . $state['switcher'];
		$nav .= '<nav class="oe-calendar__nav" aria-label="' . esc_attr__( 'Months', 'openevents' ) . '">';
		$nav .= $prev >= $min ? self::nav_link( array( 'oe_month' => $prev->format( 'Y-m' ) ), __( 'Previous month', 'openevents' ), '‹' ) : '';
		$nav .= self::nav_link( array( 'oe_month' => null ), __( 'This month', 'openevents' ), __( 'Today', 'openevents' ), false );
		$nav .= $next <= $max ? self::nav_link( array( 'oe_month' => $next->format( 'Y-m' ) ), __( 'Next month', 'openevents' ), '›' ) : '';
		$nav .= '</nav></div></div>';

		$today = current_datetime()->format( 'Y-m-d' );
		$html  = '<table class="oe-month"><caption class="oe-visually-hidden">' . esc_html( $title ) . '</caption><thead><tr>';

		global $wp_locale;
		for ( $i = 0; $i < 7; $i++ ) {
			$weekday = $wp_locale->get_weekday( ( $sow + $i ) % 7 );
			$html   .= sprintf( '<th scope="col"><abbr title="%s">%s</abbr></th>', esc_attr( $weekday ), esc_html( $wp_locale->get_weekday_abbrev( $weekday ) ) );
		}
		$html .= '</tr></thead><tbody>';

		$max_per_day = 3;
		for ( $day = $grid_start; $day <= $grid_end; $day = $day->modify( '+1 day' ) ) {
			$key = $day->format( 'Y-m-d' );
			if ( (int) $day->format( 'w' ) === $sow ) {
				$html .= '<tr>';
			}
			$classes = array( 'oe-month__day' );
			if ( $day->format( 'm' ) !== $month->format( 'm' ) ) {
				$classes[] = 'is-other-month';
			}
			if ( $key === $today ) {
				$classes[] = 'is-today';
			}
			$events = $days[ $key ] ?? array();
			if ( ! $events ) {
				$classes[] = 'is-empty';
			}

			$html .= sprintf(
				'<td class="%s"><span class="oe-month__date"><time datetime="%s">%s</time></span>',
				esc_attr( implode( ' ', $classes ) ),
				esc_attr( $key ),
				esc_html( wp_date( 'j', $day->getTimestamp(), wp_timezone() ) )
			);
			if ( $events ) {
				$html .= '<ul class="oe-month__events">';
				foreach ( array_slice( $events, 0, $max_per_day ) as $occurrence ) {
					$html .= '<li>' . self::month_item( $occurrence, $key ) . '</li>';
				}
				$html .= '</ul>';
				if ( count( $events ) > $max_per_day ) {
					$html .= sprintf(
						'<a class="oe-month__more" href="%s" rel="nofollow" data-wp-on--click="actions.navigate">%s</a>',
						esc_url(
							self::url(
								array(
									'oe_view' => 'list',
									'oe_date' => $key,
								)
							)
						),
						/* translators: %d: number of additional events */
						esc_html( sprintf( _n( '+%d more', '+%d more', count( $events ) - $max_per_day, 'openevents' ), count( $events ) - $max_per_day ) )
					);
				}
			}
			$html .= '</td>';
			if ( (int) $day->modify( '+1 day' )->format( 'w' ) === $sow ) {
				$html .= '</tr>';
			}
		}
		$html .= '</tbody></table>';

		if ( ! $result['items'] ) {
			$html .= '<p class="oe-calendar__empty">' . esc_html__( 'No events scheduled this month.', 'openevents' ) . '</p>';
		}

		return $nav . $html;
	}

	private static function month_item( Occurrence $occurrence, string $day ): string {
		$event = $occurrence->event();
		if ( ! $event ) {
			return '';
		}
		$starts_today = $occurrence->start->format( 'Y-m-d' ) === $day;
		$time         = ( ! $occurrence->all_day && $starts_today ) ? Formatter::time( $occurrence->start ) . ' ' : '';
		return sprintf(
			'<a class="oe-month__event%s" href="%s" style="%s">%s<span class="oe-month__event-title">%s</span></a>',
			$occurrence->all_day || $occurrence->is_multi_day() ? ' is-all-day' : '',
			esc_url( get_permalink( $event->post ) ),
			esc_attr( CategoryColors::style_for( $event->id() ) ),
			$time ? '<span class="oe-month__event-time">' . esc_html( $time ) . '</span>' : '',
			esc_html( get_the_title( $event->post ) )
		);
	}

	/**
	 * @param array<string, mixed> $state View state.
	 */
	public static function render_list( CalendarView $view, array $state ): string {
		$per_page = (int) ( $view->attributes['perPage'] ?? 0 ) ?: (int) openevents()->settings()->get( 'per_page' );
		$args     = array(
			'categories'   => $state['categories'],
			'per_page'     => $per_page,
			'page'         => $state['page'],
			'prime_images' => true,
		);

		if ( $state['past'] ) {
			$args['ends_before'] = Repository::now();
			$args['order']       = 'DESC';
			$title               = __( 'Past events', 'openevents' );
		} elseif ( $state['date'] ) {
			$args['local_from'] = $state['date']->format( 'Y-m-d 00:00:00' );
			$args['local_to']   = '9999-12-31 23:59:59';
			/* translators: %s: date */
			$title = sprintf( __( 'Events from %s', 'openevents' ), Formatter::date( $state['date'] ) );
		} else {
			$args['ends_after'] = Repository::now();
			$title              = __( 'Upcoming events', 'openevents' );
		}

		$result = openevents()->occurrences()->query( $args );
		$tag    = $view->heading_tag();

		$html  = '<div class="oe-calendar__header">';
		$html .= sprintf( '<%1$s class="oe-calendar__title" tabindex="-1">%2$s</%1$s>', $tag, esc_html( $title ) );
		$html .= '<div class="oe-calendar__tools">' . $state['switcher'];
		$html .= '<nav class="oe-calendar__nav" aria-label="' . esc_attr__( 'Event list', 'openevents' ) . '">';
		$html .= $state['past']
			? self::nav_link( array( 'oe_past' => null, 'oe_page' => null ), __( 'Upcoming events', 'openevents' ), __( 'Upcoming', 'openevents' ), false )
			: self::nav_link( array( 'oe_past' => 1, 'oe_page' => null, 'oe_date' => null ), __( 'Past events', 'openevents' ), __( 'Past', 'openevents' ), false );
		$html .= '</nav></div></div>';

		if ( ! $result['items'] ) {
			return $html . '<p class="oe-calendar__empty">' . esc_html__( 'There are no events to show.', 'openevents' ) . '</p>';
		}

		$current_group = '';
		$sub_tag       = 'h' . min( 6, (int) substr( $tag, 1 ) + 1 );
		$html         .= '<div class="oe-list">';
		foreach ( $result['items'] as $occurrence ) {
			$group = wp_date( 'F Y', $occurrence->start->getTimestamp(), $occurrence->start->getTimezone() );
			if ( $group !== $current_group ) {
				$html         .= ( $current_group ? '</ol>' : '' ) . sprintf( '<%1$s class="oe-list__group">%2$s</%1$s><ol class="oe-list__items">', $sub_tag, esc_html( $group ) );
				$current_group = $group;
			}
			$html .= '<li>' . self::list_item( $occurrence, $sub_tag ) . '</li>';
		}
		$html .= '</ol></div>';

		if ( $result['pages'] > 1 ) {
			$html .= '<nav class="oe-pagination" aria-label="' . esc_attr__( 'Event pages', 'openevents' ) . '">';
			if ( $state['page'] > 1 ) {
				$html .= self::nav_link( array( 'oe_page' => $state['page'] - 1 ), __( 'Previous page', 'openevents' ), __( 'Previous', 'openevents' ), false );
			}
			/* translators: 1: current page, 2: total pages */
			$html .= '<span class="oe-pagination__status">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'openevents' ), $state['page'], $result['pages'] ) ) . '</span>';
			if ( $state['page'] < $result['pages'] ) {
				$html .= self::nav_link( array( 'oe_page' => $state['page'] + 1 ), __( 'Next page', 'openevents' ), __( 'Next', 'openevents' ), false );
			}
			$html .= '</nav>';
		}

		return $html;
	}

	/**
	 * One event in list layouts. Shared with the upcoming-events block.
	 */
	public static function list_item( Occurrence $occurrence, string $title_tag = 'h3', array $options = array() ): string {
		$event = $occurrence->event();
		if ( ! $event ) {
			return '';
		}
		$options = wp_parse_args(
			$options,
			array(
				'image'   => true,
				'excerpt' => true,
				'venue'   => true,
			)
		);

		$image = $options['image'] ? get_the_post_thumbnail( $event->post, 'medium', array( 'class' => 'oe-event-card__image', 'alt' => '' ) ) : '';
		$venue = $options['venue'] ? $event->venue() : null;

		$html  = sprintf( '<article class="oe-event-card%s" style="%s">', $image ? ' has-image' : '', esc_attr( CategoryColors::style_for( $event->id() ) ) );
		$html .= sprintf(
			'<div class="oe-event-card__date" aria-hidden="true"><span class="oe-event-card__month">%s</span><span class="oe-event-card__day">%s</span></div>',
			esc_html( wp_date( 'M', $occurrence->start->getTimestamp(), $occurrence->start->getTimezone() ) ),
			esc_html( wp_date( 'j', $occurrence->start->getTimestamp(), $occurrence->start->getTimezone() ) )
		);
		$html .= '<div class="oe-event-card__body">';
		$html .= sprintf( '<%1$s class="oe-event-card__title"><a href="%2$s">%3$s</a></%1$s>', tag_escape( $title_tag ), esc_url( get_permalink( $event->post ) ), esc_html( get_the_title( $event->post ) ) );
		$html .= sprintf( '<p class="oe-event-card__when"><time datetime="%s">%s</time></p>', esc_attr( Formatter::iso( $occurrence->start, $occurrence->all_day ) ), esc_html( Formatter::range( $occurrence ) ) );
		if ( $venue ) {
			$html .= '<p class="oe-event-card__venue">' . esc_html( get_the_title( $venue ) ) . '</p>';
		}
		if ( $options['excerpt'] && has_excerpt( $event->post ) ) {
			$html .= '<p class="oe-event-card__excerpt">' . esc_html( get_the_excerpt( $event->post ) ) . '</p>';
		}
		$html .= '</div>' . $image . '</article>';

		return $html;
	}

	/**
	 * Legend of coloured categories.
	 *
	 * @param int[] $categories Restrict to these categories.
	 */
	private static function legend( array $categories ): string {
		$terms = CategoryColors::legend_terms( $categories );
		if ( ! $terms ) {
			return '';
		}
		$html = '<ul class="oe-legend" aria-label="' . esc_attr__( 'Category colours', 'openevents' ) . '">';
		foreach ( $terms as $term ) {
			$html .= sprintf(
				'<li><span class="oe-legend__swatch" style="background:%s" aria-hidden="true"></span><a href="%s">%s</a></li>',
				esc_attr( CategoryColors::color( $term->term_id ) ),
				esc_url( get_term_link( $term ) ),
				esc_html( $term->name )
			);
		}
		return $html . '</ul>';
	}

	/**
	 * Current URL with calendar params replaced. Null values remove a param.
	 *
	 * @param array<string, mixed> $params Params to set.
	 */
	public static function url( array $params ): string {
		$keep = array();
		foreach ( self::PARAMS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $key ] ) && 'oe_page' !== $key ) {
				$keep[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		$params = array_merge( $keep, $params );
		$url    = remove_query_arg( self::PARAMS );
		return add_query_arg( array_filter( $params, static fn( $v ) => null !== $v ), $url );
	}

	private static function nav_link( array $params, string $label, string $text, bool $icon = true ): string {
		return sprintf(
			'<a class="oe-calendar__nav-link%s" href="%s" rel="nofollow" data-wp-on--click="actions.navigate"%s>%s</a>',
			$icon ? ' is-icon' : '',
			esc_url( self::url( $params ) ),
			$icon ? ' aria-label="' . esc_attr( $label ) . '"' : '',
			esc_html( $text )
		);
	}

	private static function parse_month( string $value ): DateTimeImmutable {
		$month = preg_match( '/^\d{4}-\d{2}$/', $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value . '-01', wp_timezone() ) : false;
		if ( ! $month ) {
			return current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 );
		}
		return max( self::range_start(), min( self::range_end(), $month ) );
	}

	private static function parse_date( string $value ): ?DateTimeImmutable {
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;
		return $date ?: null;
	}

	private static function range_start(): DateTimeImmutable {
		return current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 )->modify( '-' . self::RANGE_YEARS . ' years' );
	}

	private static function range_end(): DateTimeImmutable {
		return current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 )->modify( '+' . self::RANGE_YEARS . ' years' );
	}
}
