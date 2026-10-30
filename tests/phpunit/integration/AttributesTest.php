<?php
/**
 * Event attributes, calendar filters by attribute and weekday, and the TEC
 * Pro additional-fields import.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\Attributes;
use OpenEvents\Frontend\EventDetails;
use OpenEvents\Settings;
use OpenEvents\Tests\TestCase;

final class AttributesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		$this->settings( array( 'attributes' => array( array( 'label' => 'Audiences' ) ) ) );
		update_option( Settings::OPTION, Settings::sanitize( get_option( Settings::OPTION ) ) );
		Attributes::register_taxonomies();
		add_filter( 'openevents/calendar/cache', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'openevents/calendar/cache', '__return_false' );
		unset( $_GET['oe_dow'], $_GET['oe_audiences'], $_GET['oe_view'], $_GET['oe_month'] );
		parent::tear_down();
	}

	private function dated( string $title, string $start, array $audiences = array() ): int {
		$id = $this->event(
			array(
				'_oe_start' => $start,
				'_oe_end'   => substr( $start, 0, 11 ) . '23:00:00',
			),
			array( 'post_title' => $title )
		);
		if ( $audiences ) {
			wp_set_object_terms( $id, $audiences, 'oe_audiences' );
		}
		$this->rows( $id );
		return $id;
	}

	public function test_sanitize(): void {
		$this->assertSame(
			array(
				array(
					'slug'  => 'audiences',
					'label' => 'Audiences',
				),
				array(
					'slug'  => 'audiences_2',
					'label' => 'Audiences',
				),
				array(
					'slug'  => 'event_category_2',
					'label' => 'Event Category',
				),
			),
			Attributes::sanitize(
				array(
					array( 'label' => 'Audiences' ),
					array( 'label' => 'Audiences' ),
					array( 'label' => 'Event Category' ),
					array( 'label' => '' ),
				)
			),
			'Unique slugs, made from labels; the plugin’s own taxonomy name is reserved.'
		);
	}

	public function test_attributes_are_taxonomies_shown_in_details(): void {
		$this->assertTrue( taxonomy_exists( 'oe_audiences' ) );
		$this->assertTrue( get_taxonomy( 'oe_audiences' )->show_in_rest );

		$id = $this->dated( 'Story time', '2030-05-11 10:00:00', array( 'Kids', 'Early Years' ) );
		update_post_meta(
			$id,
			'_oe_details',
			array(
				array(
					'label' => 'Registration',
					'value' => 'Drop in, no registration needed.',
				),
			)
		);
		$html = EventDetails::render( $id );

		$this->assertStringContainsString( '<dt>Audiences</dt>', $html );
		$this->assertMatchesRegularExpression( '#<dd>(Early Years, Kids|Kids, Early Years)</dd>#', $html );
		$this->assertStringContainsString( '<dt>Registration</dt><dd>Drop in, no registration needed.</dd>', $html );
	}

	public function test_repository_filters_by_weekday_and_terms(): void {
		$sat_kids  = $this->dated( 'Sat kids', '2030-05-11 10:00:00', array( 'Kids' ) );
		$sat_teens = $this->dated( 'Sat teens', '2030-05-11 14:00:00', array( 'Teens' ) );
		$wed_kids  = $this->dated( 'Wed kids', '2030-05-08 10:00:00', array( 'Kids' ) );
		$kids      = get_term_by( 'name', 'Kids', 'oe_audiences' )->term_id;
		$teens     = get_term_by( 'name', 'Teens', 'oe_audiences' )->term_id;
		$ids       = static fn( array $args ) => array_map(
			static fn( $o ) => $o->event_id,
			openevents_get_occurrences( $args + array( 'local_from' => '2030-05-01 00:00:00', 'local_to' => '2030-05-31 23:59:59' ) )['items']
		);

		$this->assertEqualsCanonicalizing( array( $sat_kids, $sat_teens ), $ids( array( 'weekdays' => array( 6 ) ) ) );
		$this->assertEqualsCanonicalizing( array( $sat_kids, $wed_kids ), $ids( array( 'terms' => array( 'oe_audiences' => array( $kids ) ) ) ) );
		$this->assertEqualsCanonicalizing( array( $sat_kids, $sat_teens, $wed_kids ), $ids( array( 'terms' => array( 'oe_audiences' => array( $kids, $teens ) ) ) ), 'Several terms: any of them.' );
		$this->assertSame( array( $sat_kids ), $ids( array( 'weekdays' => array( 6 ), 'terms' => array( 'oe_audiences' => array( $kids ) ) ) ), 'Different filters: all of them.' );
	}

	public function test_calendar_more_filters(): void {
		$this->dated( 'Sat kids', '2030-05-11 10:00:00', array( 'Kids' ) );
		$this->dated( 'Wed kids', '2030-05-08 10:00:00', array( 'Kids' ) );
		$this->dated( 'Sat adults', '2030-05-11 14:00:00', array( 'Adults' ) );

		$_GET['oe_view']      = 'list';
		$_GET['oe_dow']       = array( '6' );
		$_GET['oe_audiences'] = array( 'kids' );
		$html                 = do_blocks( '<!-- wp:openevents/calendar {"views":["month","list"],"showFilters":true} /-->' );
		$html                 = str_replace( "checked='checked'", 'checked', $html );

		$this->assertStringContainsString( '<details class="oe-calendar__more"', $html );
		$this->assertStringContainsString( ' open><summary>More filters</summary>', $html, 'Open while in use.' );
		$this->assertStringContainsString( '<legend>Day of the week</legend>', $html );
		$this->assertStringContainsString( '<legend>Audiences</legend>', $html );
		$this->assertStringContainsString( 'name="oe_audiences[]" value="kids" checked', $html );
		$this->assertStringContainsString( 'Showing events: Kids, Saturday', $html );
		$this->assertStringNotContainsString( 'Wed kids', $html );
		$this->assertStringNotContainsString( 'Sat adults', $html );
	}

	public function test_tec_pro_additional_fields_import(): void {
		update_option(
			'tribe_events_calendar_options',
			array(
				'custom-fields' => array(
					4 => array(
						'name'   => '_ecp_custom_4',
						'label'  => 'Format',
						'type'   => 'checkbox',
						'values' => "In person\r\nOnline",
					),
					5 => array(
						'name'  => '_ecp_custom_5',
						'label' => 'Bring',
						'type'  => 'text',
					),
				),
			)
		);
		$id = $this->dated( 'Imported', '2030-05-11 10:00:00' );
		update_post_meta( $id, '_oe_source_tec', 'tribe_events' );
		update_post_meta( $id, '_ecp_custom_4', 'In person|Online' );
		update_post_meta( $id, '_ecp_custom_5', 'A library card' );

		$importer = new \OpenEvents\Import\Tec();
		( new \ReflectionMethod( $importer, 'additional_fields' ) )->invoke( $importer );

		$this->assertTrue( taxonomy_exists( 'oe_format' ) );
		$this->assertSame( array( 'In person', 'Online' ), wp_get_object_terms( $id, 'oe_format', array( 'fields' => 'names', 'orderby' => 'term_id' ) ) );
		$this->assertSame(
			array(
				array(
					'label' => 'Bring',
					'value' => 'A library card',
				),
			),
			get_post_meta( $id, '_oe_details', true )
		);
		$this->assertContains( 'Format', array_column( openevents()->settings()->get( 'attributes' ), 'label' ) );
	}
}
