<?php
/**
 * Events archive for classic themes. Copy to
 * your-theme/open-events-calendar/archive.php to customise.
 *
 * @package OpenEvents
 */

get_header();

$open_events_calendar_attrs = array( 'showLegend' => true );
if ( is_tax( OpenEvents\Content\PostTypes::CATEGORY ) ) {
	$open_events_calendar_attrs['categories'] = array( get_queried_object_id() );
}
?>
<main id="primary" class="site-main oe-classic-archive">
	<header class="page-header">
		<h1 class="page-title"><?php echo is_tax() ? esc_html( single_term_title( '', false ) ) : esc_html__( 'Events', 'open-events-calendar' ); ?></h1>
		<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
	</header>
	<?php
	echo do_blocks( '<!-- wp:open-events-calendar/calendar ' . wp_json_encode( $open_events_calendar_attrs ) . ' /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</main>
<?php
get_footer();
