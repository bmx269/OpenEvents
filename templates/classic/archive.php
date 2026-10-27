<?php
/**
 * Events archive for classic themes. Copy to
 * your-theme/openevents/archive.php to customise.
 *
 * @package OpenEvents
 */

get_header();

$openevents_attrs = array( 'showLegend' => true );
if ( is_tax( OpenEvents\Content\PostTypes::CATEGORY ) ) {
	$openevents_attrs['categories'] = array( get_queried_object_id() );
}
?>
<main id="primary" class="site-main oe-classic-archive">
	<header class="page-header">
		<h1 class="page-title"><?php echo is_tax() ? esc_html( single_term_title( '', false ) ) : esc_html__( 'Events', 'openevents' ); ?></h1>
		<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
	</header>
	<?php
	echo do_blocks( '<!-- wp:openevents/calendar ' . wp_json_encode( $openevents_attrs ) . ' /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</main>
<?php
get_footer();
