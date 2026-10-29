<?php
/**
 * Series module.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Series;

use OpenEvents\Content\Event;
use OpenEvents\Content\PostTypes;
use OpenEvents\Frontend\CalendarView;
use OpenEvents\Installer;
use OpenEvents\Modules\AbstractModule;
use OpenEvents\Occurrences\Occurrence;
use OpenEvents\Occurrences\Repository;

/**
 * Series group related events (a film season, a weekly class) under one page
 * that lists their dates, and can sell series passes when Tickets is on.
 *
 * An event belongs to at most one series, stored as `_oe_series_id`. Dates
 * split off a repeating event keep it, so they stay in the series.
 */
final class Module extends AbstractModule {

	public const POST_TYPE = 'oe_series';
	public const META_KEY  = '_oe_series_id';

	/** @var array<int, true> Series whose page content was already added. */
	private static array $rendered = array();

	public function id(): string {
		return 'series';
	}

	public function label(): string {
		return __( 'Series', 'openevents' );
	}

	public function description(): string {
		return __( 'Group events into a series with its own page, and sell series passes with Tickets.', 'openevents' );
	}

	public function install(): void {
		// The post type is new: flush rewrite rules on the next request.
		update_option( Installer::FLUSH_OPTION, 1 );
	}

	public function boot(): void {
		add_action( 'init', array( self::class, 'register_type' ) );
		add_action( 'init', array( self::class, 'register_meta' ), 50 );
		add_filter( 'openevents/meta_fields', array( self::class, 'event_meta' ), 10, 2 );
		add_filter( 'openevents/event_details/rows', array( self::class, 'detail_row' ), 10, 2 );
		add_filter( 'the_content', array( self::class, 'page_content' ), 6 );
		add_filter( 'openevents/series/sections', array( self::class, 'dates_section' ), 10, 2 );
	}

	public static function register_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Series', 'openevents' ),
					'singular_name' => __( 'Series', 'openevents' ),
					'add_new_item'  => __( 'Add New Series', 'openevents' ),
					'edit_item'     => __( 'Edit Series', 'openevents' ),
					'all_items'     => __( 'Series', 'openevents' ),
					'not_found'     => __( 'No series found.', 'openevents' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'series',
				'show_in_menu' => PostTypes::menu_parent( openevents()->settings() ),
				'rewrite'      => array(
					'slug'       => 'series',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
			)
		);
	}

	/**
	 * Meta on the series itself, through the same filter as events, so other
	 * modules (Tickets: series passes) can add fields.
	 */
	public static function register_meta(): void {
		/** This filter is documented in inc/Content/Meta.php */
		$fields = apply_filters( 'openevents/meta_fields', array(), self::POST_TYPE );
		foreach ( $fields as $key => $args ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array_merge(
					array(
						'single'        => true,
						'show_in_rest'  => true,
						'auth_callback' => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
					),
					$args
				)
			);
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $fields    Meta fields.
	 * @param string                              $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	public static function event_meta( array $fields, string $post_type ): array {
		if ( in_array( $post_type, openevents_event_post_types(), true ) ) {
			$fields[ self::META_KEY ] = array(
				'type'              => 'integer',
				'description'       => __( 'Series this event belongs to.', 'openevents' ),
				'sanitize_callback' => 'absint',
			);
		}
		return $fields;
	}

	public static function series_for( int $event_id ): ?\WP_Post {
		$id     = (int) get_post_meta( $event_id, self::META_KEY, true );
		$series = $id ? get_post( $id ) : null;
		return ( $series && self::POST_TYPE === $series->post_type && 'publish' === $series->post_status ) ? $series : null;
	}

	/**
	 * @param array<string, array{label: string, html: string}> $rows  Detail rows.
	 * @param Event                                             $event Event.
	 * @return array<string, array{label: string, html: string}>
	 */
	public static function detail_row( array $rows, Event $event ): array {
		$series = self::series_for( $event->id() );
		if ( ! $series ) {
			return $rows;
		}
		$row  = array(
			'series' => array(
				'label' => __( 'Series', 'openevents' ),
				'html'  => sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $series ) ), esc_html( get_the_title( $series ) ) ),
			),
		);
		$keys = array_keys( $rows );
		$at   = max( array_search( 'date', $keys, true ), array_search( 'repeats', $keys, true ) );
		return false === $at ? $row + $rows : array_slice( $rows, 0, $at + 1, true ) + $row + array_slice( $rows, $at + 1, null, true );
	}

	/**
	 * Adds the series' dates (and, with Tickets, its passes) after the content
	 * of a series page. Works inside page builders that print the content.
	 */
	public static function page_content( string $content ): string {
		$id = get_the_ID();
		if ( ! $id || ! is_singular( self::POST_TYPE ) || get_queried_object_id() !== $id || isset( self::$rendered[ $id ] ) || ! is_main_query() ) {
			return $content;
		}
		self::$rendered[ $id ] = true;

		/**
		 * Filters the sections shown on a series page, after its content.
		 *
		 * @param string[] $sections Safe HTML keyed by id ("dates", "tickets").
		 * @param int      $id       Series id.
		 */
		$sections = apply_filters( 'openevents/series/sections', array(), $id );
		if ( ! $sections ) {
			return $content;
		}
		wp_enqueue_style( generate_block_asset_handle( 'openevents/calendar', 'style' ) );
		wp_enqueue_style( generate_block_asset_handle( 'openevents/event-details', 'style' ) );
		return $content . '<div class="oe-series oe-details">' . implode( '', $sections ) . '</div>';
	}

	/**
	 * Upcoming dates, then past ones behind a disclosure.
	 *
	 * @param string[] $sections Sections.
	 * @return string[]
	 */
	public static function dates_section( array $sections, int $id ): array {
		$repo     = openevents()->occurrences();
		$upcoming = $repo->query(
			array(
				'series'     => $id,
				'ends_after' => Repository::now(),
				'per_page'   => 100,
				'count'      => false,
			)
		)['items'];
		$past     = $repo->query(
			array(
				'series'      => $id,
				'ends_before' => Repository::now(),
				'order'       => 'DESC',
				'per_page'    => 50,
				'count'       => false,
			)
		)['items'];

		$list = static fn( array $items ) => '<ol class="oe-list__items">' . implode( '', array_map( static fn( Occurrence $o ) => '<li>' . CalendarView::list_item( $o, 'h3', array( 'excerpt' => false ) ) . '</li>', $items ) ) . '</ol>';

		$html = '<section class="oe-series__dates" aria-labelledby="oe-series-dates-' . $id . '"><h2 id="oe-series-dates-' . $id . '">' . esc_html__( 'Dates', 'openevents' ) . '</h2>';
		$html .= $upcoming ? $list( $upcoming ) : '<p>' . esc_html__( 'There are no upcoming dates in this series.', 'openevents' ) . '</p>';
		if ( $past ) {
			/* translators: %d: number of past dates */
			$html .= '<details class="oe-series__past"><summary>' . esc_html( sprintf( _n( '%d past date', '%d past dates', count( $past ), 'openevents' ), count( $past ) ) ) . '</summary>' . $list( $past ) . '</details>';
		}
		$sections['dates'] = $html . '</section>';
		return $sections;
	}
}
