<?php
/**
 * Admin screens.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Admin;

use OpenEvents\Content\PostTypes;
use OpenEvents\Modules\Registry;
use OpenEvents\Settings;

use const OpenEvents\PLUGIN_DIR;
use const OpenEvents\PLUGIN_FILE;

/**
 * Settings and the first-run setup wizard, rendered by one React app built on
 * the WordPress Design System (@wordpress/ui, @wordpress/admin-ui). Both save
 * through the core /wp/v2/settings endpoint, so options are registered here
 * with REST schemas and the same sanitisers as before.
 */
final class AdminApp {

	public const SETTINGS_SLUG   = 'open-events-calendar-settings';
	public const SETUP_SLUG      = 'open-events-calendar-setup';
	public const REDIRECT_OPTION = 'open_events_calendar_setup_redirect';

	private string $hook_settings = '';
	private string $hook_setup    = '';

	public function __construct( private Settings $settings, private Registry $modules ) {}

	public function register(): void {
		// Settings must be registered for REST requests too, not only in wp-admin.
		add_action( 'init', array( $this, 'register_settings' ) );

		if ( ! is_admin() ) {
			return;
		}
		// Priority 9: the top-level page must exist before post types attach their submenus.
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	public static function settings_url(): string {
		$parent = PostTypes::menu_parent( open_events_calendar()->settings() );
		$base   = str_starts_with( $parent, 'edit.php' ) ? admin_url( $parent ) : admin_url( 'admin.php' );
		return add_query_arg( 'page', self::SETTINGS_SLUG, $base );
	}

	public static function setup_url(): string {
		return add_query_arg( 'page', self::SETUP_SLUG, admin_url( 'admin.php' ) );
	}

	public function register_settings(): void {
		register_setting(
			'open_events_calendar',
			Settings::OPTION,
			array(
				'type'              => 'object',
				'default'           => Settings::defaults(),
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'register_event_type'      => array( 'type' => 'boolean' ),
							'event_post_types'         => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'events_slug'              => array( 'type' => 'string' ),
							'append_details'           => array( 'type' => 'boolean' ),
							'calendar_page'            => array( 'type' => 'integer' ),
							'per_page'                 => array( 'type' => 'integer' ),
							'default_view'             => array(
								'type' => 'string',
								'enum' => array( 'month', 'list' ),
							),
							'date_format'              => array( 'type' => 'string' ),
							'time_format'              => array( 'type' => 'string' ),
							'remove_data_on_uninstall' => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);

		register_setting(
			'open_events_calendar',
			Settings::MODULES_OPTION,
			array(
				'type'              => 'array',
				'default'           => array( 'ical' ),
				'sanitize_callback' => fn( $value ) => $this->modules->resolve( array_map( 'sanitize_key', (array) $value ) ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_setting(
			'open_events_calendar',
			Settings::SETUP_OPTION,
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
			)
		);
	}

	public function menu(): void {
		$parent = PostTypes::menu_parent( $this->settings );

		// Without the Events post type there is no Events menu to hang off, so add one.
		if ( ! $this->settings->registers_event_type() ) {
			add_menu_page( __( 'Events', 'open-events-calendar' ), __( 'Events', 'open-events-calendar' ), 'edit_posts', $parent, array( $this, 'render_overview' ), 'dashicons-calendar-alt', 6 );
			add_submenu_page( $parent, __( 'Events', 'open-events-calendar' ), __( 'Overview', 'open-events-calendar' ), 'edit_posts', $parent, array( $this, 'render_overview' ) );
			add_submenu_page( $parent, __( 'Event Categories', 'open-events-calendar' ), __( 'Event Categories', 'open-events-calendar' ), 'manage_categories', 'edit-tags.php?taxonomy=' . PostTypes::CATEGORY );
		}

		$this->hook_settings = (string) add_submenu_page( $parent, __( 'Event Settings', 'open-events-calendar' ), __( 'Settings', 'open-events-calendar' ), 'manage_options', self::SETTINGS_SLUG, array( $this, 'render_app' ) );
		// Registered under options.php so it has a screen but no menu entry.
		$this->hook_setup = (string) add_submenu_page( 'options.php', __( 'Events setup', 'open-events-calendar' ), __( 'Events setup', 'open-events-calendar' ), 'manage_options', self::SETUP_SLUG, array( $this, 'render_app' ) );
	}

	public function render_app(): void {
		$screen = self::SETUP_SLUG === ( $_GET['page'] ?? '' ) ? 'setup' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<div id="open-events-calendar-admin" class="oec-admin" data-screen="%s"><noscript>%s</noscript></div>',
			esc_attr( $screen ),
			esc_html__( 'Event settings need JavaScript.', 'open-events-calendar' )
		);
	}

	/**
	 * Landing page when events live on existing post types only.
	 */
	public function render_overview(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Events', 'open-events-calendar' ) . '</h1>';
		echo '<p>' . esc_html__( 'Events are added to these post types. Open an item and set a date in the Event panel of the editor sidebar.', 'open-events-calendar' ) . '</p><ul class="ul-disc">';
		foreach ( $this->settings->event_post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object ) {
				printf( '<li><a href="%s">%s</a></li>', esc_url( admin_url( 'edit.php?post_type=' . $type ) ), esc_html( $object->labels->name ) );
			}
		}
		echo '</ul><p><a class="button" href="' . esc_url( oec_calendar_url() ) . '">' . esc_html__( 'View calendar', 'open-events-calendar' ) . '</a></p></div>';
	}

	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( $this->hook_settings, $this->hook_setup ), true ) ) {
			return;
		}
		$asset_file = PLUGIN_DIR . '/build/admin/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'open-events-calendar-admin', plugins_url( 'build/admin/index.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'open-events-calendar-admin', 'open-events-calendar' );
		if ( is_readable( PLUGIN_DIR . '/build/admin/index.css' ) ) {
			wp_enqueue_style( 'open-events-calendar-admin', plugins_url( 'build/admin/index.css', PLUGIN_FILE ), array(), $asset['version'] );
		}

		wp_add_inline_script( 'open-events-calendar-admin', 'window.openEventsCalendarAdmin = ' . wp_json_encode( $this->app_config() ) . ';', 'before' );
	}

	/**
	 * Data the app needs that is not available from REST in a single request.
	 *
	 * @return array<string, mixed>
	 */
	private function app_config(): array {
		$post_types = array();
		foreach ( Settings::eligible_post_types() as $type ) {
			$post_types[] = array(
				'name'  => $type->name,
				'label' => $type->labels->name,
				'count' => (int) ( wp_count_posts( $type->name )->publish ?? 0 ),
			);
		}

		$modules = array();
		foreach ( $this->modules->all() as $id => $module ) {
			$modules[] = array(
				'id'          => $id,
				'label'       => $module->label(),
				'description' => $module->description(),
				'requires'    => $module->requires(),
			);
		}

		$pages = array();
		foreach ( get_pages( array( 'number' => 200, 'sort_column' => 'post_title' ) ) as $page ) {
			$pages[] = array(
				'id'    => $page->ID,
				'title' => $page->post_title ?: sprintf( '#%d', $page->ID ),
			);
		}

		global $wpdb;
		$tec_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tribe_events' AND post_status NOT IN ('auto-draft','trash')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array(
			'postTypes'    => $post_types,
			'modules'      => $modules,
			'pages'        => $pages,
			'tecCount'     => $tec_count,
			'homeUrl'      => home_url( '/' ),
			'adminUrl'     => admin_url( '/' ),
			'settingsUrl'  => self::settings_url(),
			'calendarUrl'  => oec_calendar_url(),
			'siteFormats'  => array(
				'date' => (string) get_option( 'date_format' ),
				'time' => (string) get_option( 'time_format' ),
			),
		);
	}

	/**
	 * Sends the activating admin to the wizard once, unless this was a bulk activation.
	 */
	public function maybe_redirect(): void {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return;
		}
		delete_option( self::REDIRECT_OPTION );
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || isset( $_GET['activate-multi'] ) || $this->settings->is_setup_complete() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( self::setup_url() );
		exit;
	}

	public function setup_notice(): void {
		$screen = get_current_screen();
		if ( $this->settings->is_setup_complete() || ! current_user_can( 'manage_options' ) || ( $screen && str_contains( (string) $screen->id, 'open-events-calendar' ) ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Open Events Calendar is active. Answer a few questions to set it up for your site.', 'open-events-calendar' ),
			esc_url( self::setup_url() ),
			esc_html__( 'Run setup', 'open-events-calendar' )
		);
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::settings_url() ), esc_html__( 'Settings', 'open-events-calendar' ) ) );
		return $links;
	}
}
