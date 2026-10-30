<?php
/**
 * Event attributes: site-defined taxonomies for events.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Content;

/**
 * Lists a site adds to describe events beyond categories, such as Audiences
 * (Kids, Teens, Adults) or Format (In person, Online). Each is a taxonomy on
 * every event post type: picked from checkboxes in the editor, shown in the
 * event details, and filterable on the calendar.
 *
 * Defined in the `attributes` setting as [ { slug, label } ]; the taxonomy is
 * "oe_{slug}". They have no public archive pages of their own: the calendar's
 * filters are how visitors browse by them.
 */
final class Attributes {

	public const PREFIX = 'oe_';

	/** Taxonomy names are limited to 32 characters. */
	private const MAX_SLUG = 20;

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_taxonomies' ), 12 );
	}

	/**
	 * @return array<string, string> Taxonomy => label.
	 */
	public static function all(): array {
		$out = array();
		foreach ( (array) openevents()->settings()->get( 'attributes' ) as $attribute ) {
			if ( ! empty( $attribute['slug'] ) ) {
				$out[ self::PREFIX . $attribute['slug'] ] = (string) $attribute['label'];
			}
		}
		return $out;
	}

	/**
	 * Cleans the `attributes` setting: unique slugs (from the label when
	 * missing) that don't collide with OpenEvents' own taxonomy.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	public static function sanitize( mixed $value ): array {
		$out  = array();
		$used = array( 'event_category' );
		foreach ( is_array( $value ) ? $value : array() as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $attribute['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue;
			}
			$slug = substr( str_replace( '-', '_', sanitize_key( (string) ( $attribute['slug'] ?? '' ) ?: sanitize_title( $label ) ) ), 0, self::MAX_SLUG );
			$slug = trim( $slug, '_' ) ?: 'attribute';
			$base = $slug;
			for ( $n = 2; in_array( $slug, $used, true ); $n++ ) {
				$slug = substr( $base, 0, self::MAX_SLUG - 3 ) . '_' . $n;
			}
			$used[] = $slug;
			$out[]  = array(
				'slug'  => $slug,
				'label' => $label,
			);
		}
		return $out;
	}

	public static function register_taxonomies(): void {
		$types = openevents()->settings()->event_post_types();
		foreach ( self::all() as $taxonomy => $label ) {
			register_taxonomy(
				$taxonomy,
				$types,
				array(
					'labels'             => array(
						'name'          => $label,
						'singular_name' => $label,
						'menu_name'     => $label,
						/* translators: %s: attribute name, e.g. "Audiences" */
						'add_new_item'  => sprintf( __( 'Add to %s', 'openevents' ), $label ),
					),
					// Hierarchical for the checkbox list in the editor; terms are usually flat.
					'hierarchical'       => true,
					'public'             => false,
					'publicly_queryable' => false,
					'show_ui'            => true,
					'show_in_menu'       => true,
					'show_in_rest'       => true,
					'show_admin_column'  => true,
					'rewrite'            => false,
					'query_var'          => false,
				)
			);
		}
	}

	/**
	 * Detail rows: one per attribute the event has terms in.
	 *
	 * @return array<string, array{label: string, html: string}>
	 */
	public static function detail_rows( int $post_id ): array {
		$rows = array();
		foreach ( self::all() as $taxonomy => $label ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) && $terms ) {
				$rows[ $taxonomy ] = array(
					'label' => $label,
					'html'  => esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ),
				);
			}
		}
		return $rows;
	}
}
