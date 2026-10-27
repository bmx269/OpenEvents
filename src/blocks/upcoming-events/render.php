<?php
/**
 * Upcoming events block.
 *
 * @package OpenEvents
 *
 * @var array $attributes Block attributes.
 */

use OpenEvents\Frontend\CalendarView;

$openevents_items = openevents_get_upcoming(
	max( 1, min( 50, (int) $attributes['count'] ) ),
	array(
		'categories'   => array_map( 'absint', (array) $attributes['categories'] ),
		'count'        => false, // Not paginated.
		'prime_images' => (bool) $attributes['showImage'],
	)
);
$openevents_level = max( 2, min( 6, (int) $attributes['headingLevel'] ) );
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'oe-upcoming' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $openevents_items ) : ?>
		<ul class="oe-upcoming__items">
			<?php foreach ( $openevents_items as $openevents_occurrence ) : ?>
				<li>
					<?php
					echo CalendarView::list_item( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in list_item().
						$openevents_occurrence,
						'h' . $openevents_level,
						array(
							'image'   => (bool) $attributes['showImage'],
							'excerpt' => (bool) $attributes['showExcerpt'],
							'venue'   => (bool) $attributes['showVenue'],
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="oe-upcoming__empty"><?php esc_html_e( 'There are no upcoming events.', 'openevents' ); ?></p>
	<?php endif; ?>
	<?php if ( $attributes['showViewAll'] ) : ?>
		<p class="oe-upcoming__more"><a href="<?php echo esc_url( openevents_calendar_url() ); ?>"><?php esc_html_e( 'View all events', 'openevents' ); ?></a></p>
	<?php endif; ?>
</div>
