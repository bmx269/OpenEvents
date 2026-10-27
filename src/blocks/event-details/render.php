<?php
/**
 * Event details block.
 *
 * @package OpenEvents
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

$openevents_post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$openevents_details = $openevents_post_id ? OpenEvents\Frontend\EventDetails::render( $openevents_post_id, $attributes ) : '';

if ( '' === $openevents_details ) {
	// In the editor (template editing or an unsaved event) show where the panel goes.
	if ( wp_is_serving_rest_request() ) {
		printf( '<div %s><p>%s</p></div>', get_block_wrapper_attributes( array( 'class' => 'oe-details' ) ), esc_html__( 'Event details appear here once the event has a date.', 'openevents' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'oe-details' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $openevents_details; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in EventDetails. ?>
</div>
