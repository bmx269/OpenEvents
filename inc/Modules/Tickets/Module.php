<?php
/**
 * Tickets module.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Money;
use OpenEvents\Attendance\Schema;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Content\Event;
use OpenEvents\Content\Meta;
use OpenEvents\Modules\AbstractModule;
use OpenEvents\Occurrences\Occurrence;

use const OpenEvents\PLUGIN_DIR;
use const OpenEvents\PLUGIN_FILE;

/**
 * Free and paid tickets: ticket types in the editor, checkout in event
 * details, Stripe / PayPal / pay-at-the-door gateways, order emails, and an
 * orders screen. No platform fees.
 */
final class Module extends AbstractModule {

	public const META_KEY   = '_oe_tickets';
	public const CRON_HOOK  = 'openevents_expire_orders';
	public const KEY_PREFIX = 'tkt-';

	public function id(): string {
		return 'tickets';
	}

	public function label(): string {
		return __( 'Tickets', 'openevents' );
	}

	public function description(): string {
		return __( 'Sell or give away tickets: several ticket types per event, capacity limits, Stripe, PayPal, or pay at the door, with no platform fees.', 'openevents' );
	}

	public function install(): void {
		Schema::install();
	}

	public function boot(): void {
		Schema::maybe_install();

		add_filter( 'openevents/meta_fields', array( $this, 'meta_fields' ), 10, 2 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );

		add_filter( 'openevents/event_details/sections', array( $this, 'section' ), 20, 3 );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_event_pages' ) );
		add_action( 'admin_post_openevents_checkout', array( $this, 'handle_form' ) );
		add_action( 'admin_post_nopriv_openevents_checkout', array( $this, 'handle_form' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'template_redirect', array( $this, 'no_cache_order_pages' ) );

		// Release abandoned seat holds every five minutes.
		add_filter( 'cron_schedules', array( self::class, 'cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON_HOOK, array( $this, 'expire_orders' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'openevents_five_minutes', self::CRON_HOOK );
		}

		( new Rest() )->register();
		if ( is_admin() ) {
			( new Admin() )->register();
		}
		add_action( 'openevents/uninstall', array( self::class, 'uninstall' ) );
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules Cron schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public static function cron_schedule( array $schedules ): array {
		$schedules['openevents_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes', 'openevents' ),
		);
		return $schedules;
	}

	public function expire_orders(): void {
		( new Checkout() )->expire_due( 200 );
	}

	public static function uninstall(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( AbstractGateway::OPTION );
	}

	/**
	 * Registers the _oe_tickets list on event post types.
	 *
	 * @param array<string, array<string, mixed>> $fields    Meta fields.
	 * @param string                              $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	public function meta_fields( array $fields, string $post_type ): array {
		if ( ! in_array( $post_type, openevents_event_post_types(), true ) ) {
			return $fields;
		}
		$fields[ self::META_KEY ] = array(
			'type'              => 'array',
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_types' ),
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'           => array( 'type' => 'string' ),
							'name'          => array( 'type' => 'string' ),
							'description'   => array( 'type' => 'string' ),
							'price'         => array( 'type' => 'string' ),
							'capacity'      => array( 'type' => 'integer' ),
							'max_per_order' => array( 'type' => 'integer' ),
							'sale_start'    => array( 'type' => 'string' ),
							'sale_end'      => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
			),
		);
		return $fields;
	}

	/**
	 * @param mixed $value Raw list.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_types( mixed $value ): array {
		$out  = array();
		$keys = array();
		foreach ( is_array( $value ) ? $value : array() as $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $type['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			// Stable key: rows (and their sold counts) follow it across edits.
			$key = sanitize_key( (string) ( $type['key'] ?? '' ) ) ?: substr( md5( $name . wp_rand() ), 0, 8 );
			while ( in_array( $key, $keys, true ) ) {
				$key .= 'x';
			}
			$keys[] = $key;
			$price  = str_replace( ',', '.', trim( (string) ( $type['price'] ?? '0' ) ) );
			$out[]  = array(
				'key'           => $key,
				'name'          => $name,
				'description'   => sanitize_textarea_field( (string) ( $type['description'] ?? '' ) ),
				'price'         => preg_match( '/^\d+(\.\d{1,2})?$/', $price ) ? $price : '0',
				'capacity'      => max( 0, (int) ( $type['capacity'] ?? 0 ) ),
				'max_per_order' => max( 1, min( 50, (int) ( $type['max_per_order'] ?? 10 ) ) ),
				'sale_start'    => Meta::sanitize_datetime( $type['sale_start'] ?? '' ),
				'sale_end'      => Meta::sanitize_datetime( $type['sale_end'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * @param int|int[] $meta_ids Meta id(s); unused.
	 */
	public function on_meta_change( int|array $meta_ids, int $post_id, string $meta_key ): void {
		if ( in_array( $meta_key, array( self::META_KEY, '_oe_timezone' ), true ) && Event::is_event_type( (string) get_post_type( $post_id ) ) ) {
			self::sync( $post_id );
		}
	}

	/**
	 * Mirrors the event's ticket list into ticket type rows; removed types are
	 * archived (their orders and attendees stay).
	 */
	public static function sync( int $event_id ): void {
		global $wpdb;
		$event = Event::get( $event_id );
		if ( ! $event ) {
			return;
		}
		$repo     = new TicketTypes();
		$currency = Checkout::currency();
		$keep     = array();
		$utc      = new \DateTimeZone( 'UTC' );
		$to_utc   = static function ( string $local ) use ( $event, $utc ): ?string {
			if ( '' === $local ) {
				return null;
			}
			$date = \DateTimeImmutable::createFromFormat( Meta::DATETIME_FORMAT, $local, $event->timezone() );
			return $date ? $date->setTimezone( $utc )->format( Meta::DATETIME_FORMAT ) : null;
		};

		foreach ( (array) get_post_meta( $event_id, self::META_KEY, true ) as $type ) {
			if ( ! is_array( $type ) || empty( $type['key'] ) ) {
				continue;
			}
			$key    = self::KEY_PREFIX . $type['key'];
			$keep[] = $key;
			$repo->upsert(
				$event_id,
				$key,
				array(
					'kind'         => 'paid',
					'name'         => (string) $type['name'],
					'description'  => (string) ( $type['description'] ?? '' ),
					'price_minor'  => Money::to_minor( (string) $type['price'], $currency ),
					'currency'     => $currency,
					'capacity'     => (int) $type['capacity'] > 0 ? (int) $type['capacity'] : null,
					'sale_end_utc' => $to_utc( (string) ( $type['sale_end'] ?? '' ) ),
					'status'       => 'active',
					'settings'     => array(
						'price_minor'    => Money::to_minor( (string) $type['price'], $currency ),
						'max_per_order'  => (int) $type['max_per_order'],
						'sale_start_utc' => (string) $to_utc( (string) ( $type['sale_start'] ?? '' ) ),
					),
				)
			);
		}

		$table = Schema::ticket_types();
		$like  = $wpdb->esc_like( self::KEY_PREFIX ) . '%';
		$sql   = $wpdb->prepare( "UPDATE {$table} SET status = 'archived' WHERE event_id = %d AND kind = 'paid' AND type_key LIKE %s", $event_id, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $keep ) {
			$sql .= " AND type_key NOT IN ('" . implode( "','", array_map( 'esc_sql', $keep ) ) . "')";
		}
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	public function register_assets(): void {
		$asset_file = PLUGIN_DIR . '/build/modules/tickets/view.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = require $asset_file;
			wp_register_script_module( 'openevents-tickets', plugins_url( 'build/modules/tickets/view.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'] );
		}
	}

	public function enqueue_on_event_pages(): void {
		if ( is_singular( openevents_event_post_types() ) && ( new Checkout() )->ticket_types( get_queried_object_id() ) ) {
			wp_enqueue_script_module( 'openevents-tickets' );
		}
	}

	/**
	 * @param string[] $sections Sections.
	 * @return string[]
	 */
	public function section( array $sections, Event $event, Occurrence $occurrence ): array {
		$checkout = new Checkout();
		$types    = $checkout->ticket_types( $event->id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['oe_order_token'] ?? '' ) );
		if ( $types || '' !== $token ) {
			wp_enqueue_script_module( 'openevents-tickets' );
			$sections['tickets'] = TicketForm::render( $event, $types, $checkout, $token );
		}
		return $sections;
	}

	/**
	 * No-JS checkout: place the order, then redirect to the provider or back.
	 */
	public function handle_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; honeypot, signed timestamp, and rate limit instead of a nonce (cached pages).
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$result   = ( new Checkout() )->place(
			$event_id,
			array(
				'name'    => wp_unslash( $_POST['name'] ?? '' ),
				'email'   => wp_unslash( $_POST['email'] ?? '' ),
				'tickets' => array_map( 'absint', (array) wp_unslash( $_POST['tickets'] ?? array() ) ),
				'gateway' => sanitize_key( wp_unslash( $_POST['gateway'] ?? '' ) ),
				'website' => wp_unslash( $_POST['website'] ?? '' ),
				'ts'      => sanitize_text_field( wp_unslash( $_POST['ts'] ?? '' ) ),
				'ip'      => \OpenEvents\Attendance\Guard::client_ip(),
			)
		);
		// phpcs:enable
		if ( $result->ok ) {
			self::remember_order( $result );
			wp_redirect( $result->redirect, 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- provider checkout URLs are external.
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'oe_checkout' => 'error', 'oe_checkout_code' => $result->code ), (string) get_permalink( $event_id ) ) . '#tickets', 303 );
		exit;
	}

	/**
	 * Lets the buyer's browser show their tickets on return from the provider
	 * (the private token is otherwise only in the email).
	 */
	public static function remember_order( CheckoutResult $result ): void {
		if ( $result->order && '' !== $result->token && ! headers_sent() ) {
			setcookie(
				'oe_order_' . $result->order->uuid,
				$result->token,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => COOKIEPATH ?: '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['oe_checkout'] ) || isset( $_GET['oe_order_token'] ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	public function no_cache_order_pages(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['oe_checkout'] ) || isset( $_GET['oe_order_token'] ) ) {
			nocache_headers();
		}
	}
}
