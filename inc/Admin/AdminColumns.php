<?php
/**
 * Admin list tables.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Admin;

use OpenEvents\Content\PostTypes;
use OpenEvents\Frontend\CategoryColors;
use OpenEvents\Frontend\Formatter;

/**
 * Adds an "Event date" column to every event-enabled post type, and a colour
 * field to event categories.
 */
final class AdminColumns {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'hook_columns' ) );
		add_action( PostTypes::CATEGORY . '_add_form_fields', array( $this, 'color_field_add' ) );
		add_action( PostTypes::CATEGORY . '_edit_form_fields', array( $this, 'color_field_edit' ) );
		add_action( 'created_' . PostTypes::CATEGORY, array( $this, 'save_color' ) );
		add_action( 'edited_' . PostTypes::CATEGORY, array( $this, 'save_color' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_color_picker' ) );
	}

	public function hook_columns(): void {
		foreach ( oec_event_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable' ) );
		}
		add_filter( 'the_posts', array( $this, 'prime_dates' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'sort_by_date' ) );
	}

	/**
	 * One query for every row's event date, instead of one per row.
	 *
	 * @param \WP_Post[] $posts Posts.
	 * @return \WP_Post[]
	 */
	public function prime_dates( array $posts, \WP_Query $query ): array {
		if ( $query->is_main_query() && $posts && in_array( $query->get( 'post_type' ), oec_event_post_types(), true ) ) {
			open_events_calendar()->occurrences()->for_events( wp_list_pluck( $posts, 'ID' ) );
		}
		return $posts;
	}

	/**
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function sortable( array $columns ): array {
		$columns['oe_event_date'] = 'oe_event_date';
		return $columns;
	}

	/**
	 * Sorts the list table by first occurrence using the occurrences index.
	 * Posts without a date sort last.
	 */
	public function sort_by_date( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || 'oe_event_date' !== $query->get( 'orderby' ) ) {
			return;
		}
		$order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
		add_filter(
			'posts_clauses',
			static function ( array $clauses, \WP_Query $current ) use ( $order, $query ): array {
				if ( $current !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$table              = \OpenEvents\Installer::occurrences_table();
				$clauses['join']   .= " LEFT JOIN {$table} oe_sort ON oe_sort.event_id = {$wpdb->posts}.ID AND oe_sort.sequence = 0";
				$clauses['orderby'] = "oe_sort.start_utc IS NULL, oe_sort.start_utc {$order}";
				return $clauses;
			},
			10,
			2
		);
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['oe_event_date'] = __( 'Event date', 'open-events-calendar' );
			}
		}
		return $out;
	}

	public function column( string $column, int $post_id ): void {
		if ( 'oe_event_date' !== $column ) {
			return;
		}
		$occurrence = oec_is_event( $post_id ) ? open_events_calendar()->occurrences()->for_event( $post_id ) : null;
		echo $occurrence ? esc_html( Formatter::range( $occurrence ) ) : '<span aria-hidden="true">—</span>';
	}

	public function enqueue_color_picker( string $hook ): void {
		$screen = get_current_screen();
		if ( $screen && PostTypes::CATEGORY === $screen->taxonomy && in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".oe-color-field").wpColorPicker();});' );
		}
	}

	public function color_field_add(): void {
		?>
		<div class="form-field">
			<label for="oe-color"><?php esc_html_e( 'Colour', 'open-events-calendar' ); ?></label>
			<?php wp_nonce_field( 'oe_category_color', 'oe_category_color_nonce' ); ?>
			<input type="text" id="oe-color" name="oe_color" class="oe-color-field" value="">
			<p><?php esc_html_e( 'Background colour for events in this category on the calendar. Text colour is chosen automatically for contrast.', 'open-events-calendar' ); ?></p>
		</div>
		<?php
	}

	public function color_field_edit( \WP_Term $term ): void {
		?>
		<tr class="form-field">
			<th scope="row"><label for="oe-color"><?php esc_html_e( 'Colour', 'open-events-calendar' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'oe_category_color', 'oe_category_color_nonce' ); ?>
				<input type="text" id="oe-color" name="oe_color" class="oe-color-field" value="<?php echo esc_attr( CategoryColors::color( $term->term_id ) ); ?>">
				<p class="description"><?php esc_html_e( 'Background colour for events in this category on the calendar.', 'open-events-calendar' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function save_color( int $term_id ): void {
		if (
			! isset( $_POST['oe_category_color_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oe_category_color_nonce'] ) ), 'oe_category_color' )
			|| ! current_user_can( 'edit_term', $term_id )
		) {
			return;
		}
		$color = sanitize_hex_color( wp_unslash( $_POST['oe_color'] ?? '' ) );
		if ( $color ) {
			update_term_meta( $term_id, CategoryColors::META_KEY, $color );
		} else {
			delete_term_meta( $term_id, CategoryColors::META_KEY );
		}
	}
}
