<?php
/**
 * Block editor sidebar.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Frontend;

use OpenEvents\Content\PostTypes;

use const OpenEvents\PLUGIN_DIR;
use const OpenEvents\PLUGIN_FILE;

/**
 * Loads the sidebar panels for events, venues, and organizers.
 */
final class Editor {

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		$this->enqueue_bindings();

		$screen = get_current_screen();
		if ( ! $screen || ! $screen->post_type ) {
			return;
		}
		$settings    = openevents()->settings();
		$event_types = $settings->event_post_types();
		if ( ! in_array( $screen->post_type, array_merge( $event_types, array( PostTypes::VENUE, PostTypes::ORGANIZER ) ), true ) ) {
			return;
		}

		$asset_file = PLUGIN_DIR . '/build/editor/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'openevents-editor', plugins_url( 'build/editor/index.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'openevents-editor', 'openevents' );
		if ( is_readable( PLUGIN_DIR . '/build/editor/index.css' ) ) {
			wp_enqueue_style( 'openevents-editor', plugins_url( 'build/editor/index.css', PLUGIN_FILE ), array( 'wp-components' ), $asset['version'] );
		}

		wp_add_inline_script(
			'openevents-editor',
			'window.openEvents = ' . wp_json_encode(
				array(
					'eventPostTypes'  => $event_types,
					// Posts in the dedicated type are always events; existing types opt in per post.
					'dedicatedType'   => $settings->registers_event_type() ? PostTypes::EVENT : null,
					'siteTimezone'    => wp_timezone_string(),
					'timezones'       => timezone_identifiers_list(),
					'timeFormat12h'   => (bool) preg_match( '/[aAgh]/', (string) get_option( 'time_format' ) ),
					'modules'         => openevents()->modules()->enabled_ids(),
					'attendeesUrl'    => admin_url( 'admin.php?page=openevents-attendees' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The bindings source is needed in every block editor (posts, pages, and the
	 * Site Editor), not only on event screens.
	 */
	private function enqueue_bindings(): void {
		$asset_file = PLUGIN_DIR . '/build/editor/bindings.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = require $asset_file;
			wp_enqueue_script( 'openevents-bindings', plugins_url( 'build/editor/bindings.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'openevents-bindings', 'openevents' );
		}
	}
}
