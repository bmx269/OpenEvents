<?php
/**
 * RSVP module.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Rsvp;

use OpenEvents\Attendance\Schema;
use OpenEvents\Content\Event;
use OpenEvents\Modules\AbstractModule;
use OpenEvents\Occurrences\Occurrence;

use const OpenEvents\PLUGIN_DIR;
use const OpenEvents\PLUGIN_FILE;

/**
 * Free RSVPs: settings in the editor, a form in event details, confirmation
 * emails with a private change/cancel link, and an attendee list.
 */
final class Module extends AbstractModule {

	private Service $service;

	public function __construct() {
		$this->service = new Service();
	}

	public function id(): string {
		return 'rsvp';
	}

	public function label(): string {
		return __( 'RSVP', 'openevents' );
	}

	public function description(): string {
		return __( 'Free RSVPs with capacity limits, guests, confirmation emails, an attendee list with CSV export, and check-in.', 'openevents' );
	}

	public function install(): void {
		Schema::install();
	}

	public function boot(): void {
		Schema::maybe_install();
		\OpenEvents\Attendance\WaitlistService::register();

		add_filter( 'openevents/meta_fields', array( $this, 'meta_fields' ), 10, 2 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );

		add_filter( 'openevents/event_details/sections', array( $this, 'section' ), 10, 3 );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_event_pages' ) );
		add_action( 'admin_post_openevents_rsvp', array( $this, 'handle_form' ) );
		add_action( 'admin_post_nopriv_openevents_rsvp', array( $this, 'handle_form' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'template_redirect', array( $this, 'no_cache_private_links' ) );

		( new Rest( $this->service ) )->register();
		if ( is_admin() ) {
			( new Admin( $this->service ) )->register();
		}
		add_action( 'openevents/uninstall', array( Schema::class, 'uninstall' ) );
	}

	/**
	 * Registers the _oe_rsvp settings object on every event post type.
	 *
	 * @param array<string, array<string, mixed>> $fields    Meta fields.
	 * @param string                              $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	public function meta_fields( array $fields, string $post_type ): array {
		if ( ! in_array( $post_type, openevents_event_post_types(), true ) ) {
			return $fields;
		}
		$fields[ Service::META_KEY ] = array(
			'type'              => 'object',
			'default'           => Service::defaults(),
			'sanitize_callback' => array( self::class, 'sanitize_config' ),
			'show_in_rest'      => array(
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'enabled'          => array( 'type' => 'boolean' ),
						'capacity'         => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'max_per_response' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
						'deadline'         => array( 'type' => 'string' ),
						'allow_not_going'  => array( 'type' => 'boolean' ),
						'show_count'       => array( 'type' => 'boolean' ),
						'waitlist'         => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
			),
		);
		return $fields;
	}

	/**
	 * @param mixed $value Raw meta value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_config( mixed $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$defaults = Service::defaults();
		return array(
			'enabled'          => ! empty( $value['enabled'] ),
			'capacity'         => max( 0, (int) ( $value['capacity'] ?? 0 ) ),
			'max_per_response' => max( 1, min( 20, (int) ( $value['max_per_response'] ?? $defaults['max_per_response'] ) ) ),
			'deadline'         => \OpenEvents\Content\Meta::sanitize_datetime( $value['deadline'] ?? '' ),
			'allow_not_going'  => (bool) ( $value['allow_not_going'] ?? $defaults['allow_not_going'] ),
			'show_count'       => (bool) ( $value['show_count'] ?? $defaults['show_count'] ),
			'waitlist'         => ! empty( $value['waitlist'] ),
		);
	}

	/**
	 * @param int|int[] $meta_ids Meta id(s); unused.
	 */
	public function on_meta_change( int|array $meta_ids, int $post_id, string $meta_key ): void {
		// The deadline is stored in event-local time, so a timezone change moves it too.
		if ( in_array( $meta_key, array( Service::META_KEY, '_oe_timezone' ), true ) && Event::is_event_type( (string) get_post_type( $post_id ) ) ) {
			$this->service->sync( $post_id );
		}
	}

	public function register_assets(): void {
		$asset_file = PLUGIN_DIR . '/build/modules/rsvp/view.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = require $asset_file;
			wp_register_script_module( 'openevents-rsvp', plugins_url( 'build/modules/rsvp/view.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'] );
		}
	}

	/**
	 * Enqueued in <head> on event pages with RSVP on; the section also enqueues
	 * itself as a fallback when rendered elsewhere.
	 */
	public function enqueue_on_event_pages(): void {
		if ( is_singular( openevents_event_post_types() ) && $this->service->ticket_type( get_queried_object_id() ) ) {
			wp_enqueue_script_module( 'openevents-rsvp' );
		}
	}

	/**
	 * Adds the RSVP form to event details.
	 *
	 * @param string[] $sections Sections.
	 * @return string[]
	 */
	public function section( array $sections, Event $event, Occurrence $occurrence ): array {
		$type = $this->service->ticket_type( $event->id() );
		if ( $type ) {
			wp_enqueue_script_module( 'openevents-rsvp' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
			$token             = sanitize_text_field( wp_unslash( $_GET['oe_rsvp_token'] ?? '' ) );
			$sections['rsvp'] = Form::render( $event, $type, $this->service, $token );
		}
		return $sections;
	}

	/**
	 * No-JS form submission: handle, then redirect back with an outcome code.
	 */
	public function handle_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, protected by honeypot, signed timestamp, and rate limit (nonces break on cached pages).
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$input    = array(
			'name'     => wp_unslash( $_POST['name'] ?? '' ),
			'email'    => wp_unslash( $_POST['email'] ?? '' ),
			'status'   => sanitize_key( wp_unslash( $_POST['status'] ?? 'going' ) ),
			'quantity' => absint( $_POST['quantity'] ?? 1 ),
			'token'    => sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ),
			'website'  => wp_unslash( $_POST['website'] ?? '' ),
			'ts'       => sanitize_text_field( wp_unslash( $_POST['ts'] ?? '' ) ),
			'ip'       => self::client_ip(),
		);
		$cancel   = ! empty( $_POST['cancel'] );
		// phpcs:enable

		$result = $cancel ? $this->service->cancel( $input['token'] ) : $this->service->respond( $event_id, $input );
		$url    = get_permalink( $event_id ) ?: home_url( '/' );
		$args   = array( 'oe_rsvp' => $result->code );
		if ( ! $result->ok && $input['token'] ) {
			$args['oe_rsvp_token'] = rawurlencode( $input['token'] );
		}
		wp_safe_redirect( add_query_arg( $args, $url ) . '#rsvp', 303 );
		exit;
	}

	/**
	 * Outcome and private-link URLs are per visitor: keep them out of search
	 * indexes and page caches.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['oe_rsvp'] ) || isset( $_GET['oe_rsvp_token'] ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	public function no_cache_private_links(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['oe_rsvp_token'] ) || isset( $_GET['oe_rsvp'] ) ) {
			nocache_headers();
		}
	}

	public static function client_ip(): string {
		return \OpenEvents\Attendance\Guard::client_ip();
	}
}
