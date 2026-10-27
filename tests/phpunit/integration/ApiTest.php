<?php
/**
 * Public APIs: settings, REST, abilities, block bindings, modules.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Content\Meta;
use OpenEvents\Modules\AbstractModule;
use OpenEvents\Settings;
use OpenEvents\Tests\TestCase;

final class ApiTest extends TestCase {

	public function test_settings_sanitize_rejects_unknown_post_types_and_bad_values(): void {
		$clean = Settings::sanitize(
			array(
				'event_post_types' => array( 'post', 'not_a_type', 'attachment' ),
				'per_page'         => 5000,
				'default_view'     => 'week',
				'events_slug'      => 'Our Events!',
				'extra'            => 'dropped',
			)
		);

		$this->assertSame( array( 'post' ), $clean['event_post_types'] );
		$this->assertSame( 100, $clean['per_page'] );
		$this->assertSame( 'month', $clean['default_view'] );
		$this->assertSame( 'our-events', $clean['events_slug'] );
		$this->assertArrayNotHasKey( 'extra', $clean );
	}

	public function test_event_post_types_combine_dedicated_and_existing(): void {
		$this->assertSame( array( 'oe_event' ), openevents_event_post_types() );

		$this->settings(
			array(
				'register_event_type' => false,
				'event_post_types'    => array( 'post', 'page' ),
			)
		);
		$this->assertSame( array( 'post', 'page' ), openevents_event_post_types() );
	}

	/**
	 * @dataProvider datetimes
	 */
	public function test_datetime_sanitizer( string $input, string $expected ): void {
		$this->assertSame( $expected, Meta::sanitize_datetime( $input ) );
	}

	public static function datetimes(): array {
		return array(
			'full'     => array( '2030-05-05 10:00:00', '2030-05-05 10:00:00' ),
			'iso'      => array( '2030-05-05T10:00:00', '2030-05-05 10:00:00' ),
			'no secs'  => array( '2030-05-05 10:00', '2030-05-05 10:00:00' ),
			'date'     => array( '2030-05-05', '2030-05-05 00:00:00' ),
			'invalid'  => array( '2030-02-31 10:00:00', '' ),
			'garbage'  => array( 'next tuesday', '' ),
		);
	}

	public function test_rest_route_returns_published_occurrences_with_totals(): void {
		$day = current_datetime()->modify( '+3 days' )->format( 'Y-m-d' );
		$this->event(
			array(
				'_oe_start' => "{$day} 10:00:00",
				'_oe_end'   => "{$day} 11:00:00",
			),
			array( 'post_title' => 'Visible' )
		);
		$this->event( array( '_oe_start' => "{$day} 12:00:00" ), array( 'post_status' => 'draft' ) );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/openevents/v1/occurrences' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'Visible' ), wp_list_pluck( $data, 'title' ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data[0]['start_utc'] );
	}

	public function test_event_meta_is_editable_over_core_rest(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/events' );
		$request->set_body_params(
			array(
				'title'  => 'From REST',
				'status' => 'publish',
				'meta'   => array(
					'_oe_start' => '2030-05-05T10:00:00',
					'_oe_end'   => '2030-05-05 11:00',
				),
			)
		);
		$response = rest_do_request( $request );
		$id       = $response->get_data()['id'];

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '2030-05-05 10:00:00', get_post_meta( $id, '_oe_start', true ) );
		$this->assertCount( 1, $this->rows( $id ) );
	}

	public function test_anonymous_users_cannot_write_event_meta(): void {
		$id      = $this->event( array( '_oe_start' => '2030-05-05 10:00:00' ) );
		$request = new \WP_REST_Request( 'POST', "/wp/v2/events/{$id}" );
		$request->set_body_params( array( 'meta' => array( '_oe_start' => '2031-01-01 10:00:00' ) ) );

		$this->assertGreaterThanOrEqual( 400, rest_do_request( $request )->get_status() );
		$this->assertSame( '2030-05-05 10:00:00', get_post_meta( $id, '_oe_start', true ) );
	}

	public function test_abilities_are_registered_and_run(): void {
		$day = current_datetime()->modify( '+2 days' )->format( 'Y-m-d' );
		$this->event( array( '_oe_start' => "{$day} 10:00:00" ), array( 'post_title' => 'Listed' ) );

		$list = wp_get_ability( 'openevents/list-events' );
		$this->assertNotNull( $list );
		$this->assertSame( array( 'Listed' ), wp_list_pluck( $list->execute( array( 'limit' => 5 ) ), 'title' ) );

		$create = wp_get_ability( 'openevents/create-event' );
		$this->assertInstanceOf( \WP_Error::class, $create->execute( array( 'title' => 'Nope', 'start' => '2030-05-05 10:00' ) ), 'Anonymous users cannot create.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$created = $create->execute(
			array(
				'title'  => 'Made by AI',
				'start'  => '2030-05-05 10:00',
				'status' => 'publish',
			)
		);
		$this->assertSame( 'Made by AI', get_the_title( $created['id'] ) );
		$this->assertSame( 'publish', get_post_status( $created['id'] ) );
		$this->assertCount( 1, $this->rows( $created['id'] ) );
	}

	public function test_block_binding_source_returns_event_fields(): void {
		$venue = self::factory()->post->create(
			array(
				'post_type'  => 'oe_venue',
				'post_title' => 'Gym & Hall',
			)
		);
		$id    = $this->event(
			array(
				'_oe_start'    => '2030-05-05 10:00:00',
				'_oe_end'      => '2030-05-05 11:00:00',
				'_oe_venue_id' => $venue,
				'_oe_url'      => 'https://example.com/?a=1&b=2',
			)
		);
		$block          = new \WP_Block( array( 'blockName' => 'core/paragraph' ) );
		$block->context = array( 'postId' => $id );
		$value          = static fn( string $key ) => get_block_bindings_source( 'openevents/event' )->get_value( array( 'key' => $key ), $block, 'content' );

		$this->assertSame( 'May 5, 2030, 10:00 am – 11:00 am', $value( 'date' ) );
		$this->assertSame( 'Gym &amp; Hall', $value( 'venue' ), 'Text is HTML-escaped.' );
		$this->assertSame( 'https://example.com/?a=1&b=2', $value( 'website' ), 'URLs are not double-escaped.' );
		$this->assertNull( $value( 'unknown' ) );

		// End to end: a bound core paragraph renders the value on the event page.
		$this->go_to( get_permalink( $id ) );
		$html = do_blocks( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"openevents/event","args":{"key":"date"}}}}} --><p>placeholder</p><!-- /wp:paragraph -->' );
		$this->assertStringContainsString( '>May 5, 2030, 10:00 am – 11:00 am</p>', $html );
	}

	public function test_third_party_modules_register_and_boot_with_dependencies(): void {
		$module = new class() extends AbstractModule {
			public bool $booted = false;
			public function id(): string {
				return 'acme-zoom';
			}
			public function label(): string {
				return 'Zoom';
			}
			public function description(): string {
				return 'Adds meeting links.';
			}
			public function requires(): array {
				return array( 'ical' );
			}
			public function boot(): void {
				$this->booted = true;
			}
		};
		$add    = static function ( array $modules ) use ( $module ): array {
			$modules[] = $module;
			return $modules;
		};
		add_filter( 'openevents/modules', $add );

		$registry = new \OpenEvents\Modules\Registry( new Settings() );
		$this->assertSame( array( 'acme-zoom', 'ical' ), $registry->resolve( array( 'acme-zoom', 'missing' ) ) );

		// The bootstrap pins enabled modules with a pre_option filter; override it here.
		$enabled = static fn() => array( 'acme-zoom' );
		add_filter( 'pre_option_' . Settings::MODULES_OPTION, $enabled, 20 );
		$registry->boot_enabled();
		remove_filter( 'pre_option_' . Settings::MODULES_OPTION, $enabled, 20 );
		$this->assertTrue( $module->booted );
		$this->assertTrue( $registry->is_enabled( 'ical' ), 'Dependencies boot too.' );

		remove_filter( 'openevents/modules', $add );
	}
}
