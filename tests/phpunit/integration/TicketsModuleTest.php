<?php
/**
 * Tickets module wiring: editor data, checkout form, REST, returns, settings.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use OpenEvents\Attendance\Guard;
use OpenEvents\Attendance\Order;
use OpenEvents\Attendance\Orders;
use OpenEvents\Attendance\TicketTypes;
use OpenEvents\Modules\Tickets\AbstractGateway;
use OpenEvents\Modules\Tickets\Checkout;
use OpenEvents\Modules\Tickets\Gateways;
use OpenEvents\Modules\Tickets\Module;
use OpenEvents\Modules\Tickets\TicketForm;
use OpenEvents\Tests\TestCase;

final class TicketsModuleTest extends TestCase {

	private int $event;

	public function set_up(): void {
		parent::set_up();
		update_option( AbstractGateway::OPTION, array( 'manual' => array( 'enabled' => true ) ) );
		Gateways::reset();
		reset_phpmailer_instance();
		$day         = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$this->event = $this->event( array( '_oe_start' => "{$day} 19:00:00" ), array( 'post_title' => 'Concert' ) );
		$this->rows( $this->event );
		update_post_meta(
			$this->event,
			Module::META_KEY,
			array(
				array( 'key' => 'adult', 'name' => 'Adult', 'price' => '25.00', 'capacity' => 2, 'max_per_order' => 4 ),
				array( 'key' => 'child', 'name' => 'Child', 'price' => '0', 'capacity' => 0, 'max_per_order' => 4 ),
			)
		);
	}

	private function type( string $key ) {
		return ( new TicketTypes() )->find( $this->event, Module::KEY_PREFIX . $key );
	}

	public function test_editor_ticket_list_syncs_to_ticket_types(): void {
		$adult = $this->type( 'adult' );
		$this->assertSame( 'Adult', $adult->name );
		$this->assertSame( 2500, $adult->setting( 'price_minor' ) );
		$this->assertSame( 2, $adult->capacity );
		$this->assertNull( $this->type( 'child' )->capacity, '0 is unlimited.' );

		update_post_meta( $this->event, Module::META_KEY, array( array( 'key' => 'adult', 'name' => 'Grown-up', 'price' => '30', 'capacity' => 5 ) ) );
		$this->assertSame( 'Grown-up', $this->type( 'adult' )->name );
		$this->assertSame( $adult->id, $this->type( 'adult' )->id, 'Same row: sold counts survive edits.' );
		$this->assertSame( 'archived', $this->type( 'child' )->status, 'Removed types are archived, not deleted.' );
	}

	public function test_sanitizer_rejects_bad_prices_and_keeps_keys_unique(): void {
		$clean = Module::sanitize_types(
			array(
				array( 'key' => 'a', 'name' => 'One', 'price' => '-4' ),
				array( 'key' => 'a', 'name' => 'Two', 'price' => '12,5' ),
				array( 'name' => '' ),
			)
		);
		$this->assertCount( 2, $clean );
		$this->assertSame( '0', $clean[0]['price'] );
		$this->assertSame( '12.5', $clean[1]['price'] );
		$this->assertNotSame( $clean[0]['key'], $clean[1]['key'] );
	}

	public function test_checkout_form_is_accessible(): void {
		$checkout = new Checkout();
		$html     = TicketForm::render( openevents_get_event( $this->event ), $checkout->ticket_types( $this->event ), $checkout );
		$adult    = $this->type( 'adult' );

		$this->assertStringContainsString( '<caption class="oe-visually-hidden">Ticket types</caption>', $html );
		$this->assertStringContainsString( '<th scope="col">Quantity</th>', $html );
		$this->assertMatchesRegularExpression( '/<label for="(oe-tickets-\d+-qty-' . $adult->id . ')">Adult<\/label><span class="oe-tickets__availability" id="\1-help">2 left<\/span>/', $html );
		$this->assertStringContainsString( 'aria-describedby="oe-tickets-' . $this->event . '-qty-' . $adult->id . '-help"', $html );
		$this->assertStringContainsString( '<legend>Payment</legend>', $html );
		$this->assertStringContainsString( 'Pay at the door', $html );
		$this->assertStringNotContainsString( 'Stripe', $html, 'Only configured gateways are offered.' );
		preg_match( '/<select id="oe-tickets-\d+-qty-' . $adult->id . '"[^>]*>(.*?)<\/select>/s', $html, $select );
		$this->assertSame( 3, substr_count( $select[1], '<option value=' ), 'Quantity options capped by seats left (0, 1, 2).' );
	}

	public function test_rest_checkout_status_codes(): void {
		$adult   = $this->type( 'adult' );
		$request = function ( array $tickets, array $extra = array() ) {
			$r = new \WP_REST_Request( 'POST', "/openevents/v1/events/{$this->event}/orders" );
			$r->set_body_params( array_merge( array( 'name' => 'Ada', 'email' => 'ada@example.com', 'tickets' => $tickets, 'gateway' => 'manual', 'ts' => Guard::sign_timestamp( time() - 10 ) ), $extra ) );
			return rest_do_request( $r );
		};

		$ok = $request( array( $adult->id => 2 ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertStringContainsString( 'oe_order_token=', $ok->get_data()['redirect'] );

		$this->assertSame( 409, $request( array( $adult->id => 1 ) )->get_status(), 'Sold out.' );
		$this->assertSame( 422, $request( array( $adult->id => 1 ), array( 'email' => 'nope' ) )->get_status() );
	}

	public function test_confirmation_view_shows_check_in_codes(): void {
		$adult  = $this->type( 'adult' );
		$result = ( new Checkout() )->place( $this->event, array( 'name' => 'Ada', 'email' => 'ada@example.com', 'tickets' => array( $adult->id => 2 ), 'gateway' => 'manual', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$html   = TicketForm::render( openevents_get_event( $this->event ), ( new Checkout() )->ticket_types( $this->event ), new Checkout(), $result->token );

		$this->assertStringContainsString( 'Your order', $html );
		$this->assertStringContainsString( 'Reserved — payment due', $html );
		$this->assertSame( 2, substr_count( $html, '<code>' ) );
	}

	public function test_return_from_stripe_completes_and_redirects_to_the_event(): void {
		update_option( AbstractGateway::OPTION, array( 'stripe' => array( 'enabled' => true, 'secret_key' => 'sk_test_x' ) ) );
		Gateways::reset();
		$uuid = '';
		$mock = static function ( $pre, $args, $url ) use ( &$uuid ) {
			if ( str_contains( $url, '/checkout/sessions/cs_9' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'cs_9', 'payment_status' => 'paid', 'payment_intent' => 'pi_9', 'amount_total' => 2500, 'currency' => 'usd', 'client_reference_id' => $uuid ) ) );
			}
			if ( str_contains( $url, '/checkout/sessions' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'cs_9', 'url' => 'https://checkout.stripe.com/x' ) ) );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );

		$adult  = $this->type( 'adult' );
		$result = ( new Checkout() )->place( $this->event, array( 'name' => 'Ada', 'email' => 'ada@example.com', 'tickets' => array( $adult->id => 1 ), 'gateway' => 'stripe', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$uuid   = $result->order->uuid;

		$return = new \WP_REST_Request( 'GET', '/openevents/v1/orders/return' );
		$return->set_query_params( array( 'oe_order' => $uuid, 'oe_checkout' => 'success', 'session_id' => 'cs_9' ) );
		$response = rest_do_request( $return );
		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertSame( 302, $response->get_status() );
		$this->assertStringContainsString( 'oe_checkout=done', $response->get_headers()['Location'] );
		$this->assertSame( Order::COMPLETED, ( new Orders() )->get( $result->order->id )->status );
	}

	public function test_cancelled_return_releases_the_hold(): void {
		update_option( AbstractGateway::OPTION, array( 'stripe' => array( 'enabled' => true, 'secret_key' => 'sk_test_x' ) ) );
		Gateways::reset();
		$mock = static fn( $pre, $args, $url ) => str_contains( $url, 'stripe.com' ) ? array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'cs_c', 'url' => 'https://checkout.stripe.com/x' ) ) ) : $pre;
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$adult  = $this->type( 'adult' );
		$result = ( new Checkout() )->place( $this->event, array( 'name' => 'Ada', 'email' => 'ada@example.com', 'tickets' => array( $adult->id => 2 ), 'gateway' => 'stripe', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		remove_filter( 'pre_http_request', $mock, 10 );

		$return = new \WP_REST_Request( 'GET', '/openevents/v1/orders/return' );
		$return->set_query_params( array( 'oe_order' => $result->order->uuid, 'oe_checkout' => 'cancel' ) );
		$this->assertStringContainsString( 'oe_checkout=cancelled', rest_do_request( $return )->get_headers()['Location'] );
		$this->assertSame( 0, $this->type( 'adult' )->sold );
	}

	public function test_gateway_settings_never_return_secrets(): void {
		update_option( AbstractGateway::OPTION, array( 'stripe' => array( 'enabled' => true, 'secret_key' => 'sk_test_supersecret1234' ) ) );
		Gateways::reset();
		$get = new \WP_REST_Request( 'GET', '/openevents/v1/gateways' );

		$this->assertGreaterThanOrEqual( 401, rest_do_request( $get )->get_status(), 'Admins only.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$body   = wp_json_encode( rest_do_request( $get )->get_data() );
		$stripe = array_values( array_filter( rest_do_request( $get )->get_data()['gateways'], static fn( $g ) => 'stripe' === $g['id'] ) )[0];
		$this->assertStringNotContainsString( 'supersecret', $body );
		$this->assertStringEndsWith( '1234', $stripe['fields']['secret_key']['value'] );

		$save = new \WP_REST_Request( 'POST', '/openevents/v1/gateways' );
		$save->set_body_params( array( 'gateways' => array( 'stripe' => array( 'secret_key' => $stripe['fields']['secret_key']['value'], 'webhook_secret' => 'whsec_new' ) ) ) );
		rest_do_request( $save );
		$stored = get_option( AbstractGateway::OPTION );
		$this->assertSame( 'sk_test_supersecret1234', $stored['stripe']['secret_key'], 'Masked value leaves the secret unchanged.' );
		$this->assertSame( 'whsec_new', $stored['stripe']['webhook_secret'] );
	}

	public function test_order_actions_need_edit_rights(): void {
		$adult  = $this->type( 'adult' );
		$result = ( new Checkout() )->place( $this->event, array( 'name' => 'Ada', 'email' => 'ada@example.com', 'tickets' => array( $adult->id => 1 ), 'gateway' => 'manual', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$action = new \WP_REST_Request( 'POST', '/openevents/v1/orders/' . $result->order->id );
		$action->set_body_params( array( 'action' => 'mark_paid' ) );

		$this->assertGreaterThanOrEqual( 401, rest_do_request( $action )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'completed', rest_do_request( $action )->get_data()['status'] );
		$this->assertSame( 409, rest_do_request( $action )->get_status(), 'Already paid.' );
	}

	public function test_unknown_webhook_gateway_is_404(): void {
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'POST', '/openevents/v1/gateways/nope/webhook' ) )->get_status() );
	}
}
