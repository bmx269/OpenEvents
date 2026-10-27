<?php
/**
 * Calendar block.
 *
 * @package OpenEvents
 *
 * @var array $attributes Block attributes.
 */

$openevents_view = new OpenEvents\Frontend\CalendarView( $attributes );
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $openevents_view->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in CalendarView. ?>
</div>
