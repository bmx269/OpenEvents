<?php
/**
 * Event details block.
 *
 * @package OpenEvents
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

$open_events_calendar_post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$open_events_calendar_details = $open_events_calendar_post_id ? OpenEvents\Frontend\EventDetails::render( $open_events_calendar_post_id, $attributes ) : '';

if ( '' === $open_events_calendar_details ) {
	// In the editor (template editing or an unsaved event) show where the panel goes.
	if ( wp_is_serving_rest_request() ) {
		printf( '<div %s><p>%s</p></div>', get_block_wrapper_attributes( array( 'class' => 'oe-details' ) ), esc_html__( 'Event details appear here once the event has a date.', 'open-events-calendar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'oe-details' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $open_events_calendar_details; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in EventDetails. ?>
</div>
