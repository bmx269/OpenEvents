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
		add_action(
			'init',
			static function (): void {
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
		);
	}

	public static function color( int $term_id ): string {
		return (string) sanitize_hex_color( (string) get_term_meta( $term_id, self::META_KEY, true ) );
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
				return sprintf( '--oe-event-bg:%s;--oe-event-fg:%s;', $color, self::contrast( $color ) );
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
	 * Black or white, whichever has higher WCAG contrast against the colour.
	 */
	public static function contrast( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$channel = static function ( string $pair ): float {
			$c = hexdec( $pair ) / 255;
			return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		$luminance = 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );
		// Contrast with black is (L + 0.05) / 0.05; with white 1.05 / (L + 0.05). Equal at L ≈ 0.179.
		return $luminance > 0.179 ? '#000000' : '#ffffff';
	}
}
