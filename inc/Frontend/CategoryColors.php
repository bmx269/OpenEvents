<?php
/**
 * Category colours.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Frontend;

use OpenEvents\Content\PostTypes;

/**
 * Per-category background colour (term meta), with a text colour picked for
 * contrast so labels stay readable whatever colour an editor chooses.
 */
final class CategoryColors {

	public const META_KEY = 'oe_color';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ) );
	}

	public static function register_meta(): void {
		register_term_meta(
			PostTypes::CATEGORY,
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ) => (string) sanitize_hex_color( (string) $value ),
			)
		);
	}

	public static function color( int $term_id ): string {
		return (string) sanitize_hex_color( (string) get_term_meta( $term_id, self::META_KEY, true ) );
	}

	/**
	 * Inline CSS custom properties for one category, or '' when it has no colour.
	 */
	public static function style_for_term( int $term_id ): string {
		$color = self::color( $term_id );
		return $color ? self::style( $color ) : '';
	}

	/**
	 * Inline CSS custom properties for an event, from its first coloured category.
	 */
	public static function style_for( int $post_id ): string {
		$terms = get_the_terms( $post_id, PostTypes::CATEGORY );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			$color = self::color( $term->term_id );
			if ( $color ) {
				return self::style( $color );
			}
		}
		return '';
	}

	/**
	 * Categories that have a colour, for the legend.
	 *
	 * @param int[] $limit_to Term ids to restrict to; empty for all.
	 * @return \WP_Term[]
	 */
	public static function legend_terms( array $limit_to = array() ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => true,
				'include'    => $limit_to,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- small term set.
					array(
						'key'     => self::META_KEY,
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Fill colour for labels in a category's colour. Light colours keep black
	 * text. Others get white text and are darkened, keeping the hue, until
	 * white reaches 4.5:1. WCAG 2 rates black on mid-tones (e.g. #059669, 5.6:1)
	 * as passing, but at small sizes it reads as muddy, so mid-tones go dark.
	 */
	public static function fill( string $hex ): string {
		$rgb = self::rgb( $hex );
		for ( $step = 0; $step <= 20; $step++ ) {
			$mixed = array_map( static fn( int $c ): int => (int) round( $c * ( 1 - $step / 20 ) ), $rgb );
			if ( 0 === $step && self::ratio( self::luminance( $mixed ), 0.0 ) >= self::LIGHT ) {
				break;
			}
			if ( self::ratio( 1.0, self::luminance( $mixed ) ) >= 4.5 ) {
				$rgb = $mixed;
				break;
			}
		}
		return vsprintf( '#%02x%02x%02x', $rgb );
	}

	/**
	 * Text colour for a fill() colour: black on light colours, otherwise white.
	 */
	public static function contrast( string $hex ): string {
		return self::ratio( self::luminance( self::rgb( $hex ) ), 0.0 ) >= self::LIGHT ? '#000000' : '#ffffff';
	}

	/**
	 * Black-text contrast at or above which a colour counts as light.
	 */
	private const LIGHT = 8.0;

	private static function style( string $color ): string {
		$fill = self::fill( $color );
		return sprintf( '--oe-event-bg:%s;--oe-event-fg:%s;', $fill, self::contrast( $fill ) );
	}

	/**
	 * @return int[] Red, green, blue (0-255).
	 */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array_map( 'hexdec', str_split( substr( $hex, 0, 6 ), 2 ) );
	}

	/**
	 * @param int[] $rgb Red, green, blue (0-255).
	 */
	private static function luminance( array $rgb ): float {
		$channel = static function ( int $value ): float {
			$c = $value / 255;
			return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * $channel( $rgb[0] ) + 0.7152 * $channel( $rgb[1] ) + 0.0722 * $channel( $rgb[2] );
	}

	private static function ratio( float $lighter, float $darker ): float {
		return ( max( $lighter, $darker ) + 0.05 ) / ( min( $lighter, $darker ) + 0.05 );
	}
}
