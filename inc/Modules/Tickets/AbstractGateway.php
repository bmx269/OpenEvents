<?php
/**
 * Gateway base class.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Order;

/**
 * Settings live in the openevents_gateways option (not autoloaded, never in
 * REST settings). Any setting can instead come from a constant such as
 * OPENEVENTS_STRIPE_SECRET_KEY in wp-config.php, which wins over the option.
 */
abstract class AbstractGateway implements Gateway {

	public const OPTION = 'openevents_gateways';

	public function description(): string {
		return '';
	}

	public function setting( string $key, mixed $default = '' ): mixed {
		$constant = 'OPENEVENTS_' . strtoupper( $this->id() . '_' . $key );
		if ( defined( $constant ) ) {
			return constant( $constant );
		}
		$all = get_option( self::OPTION, array() );
		return $all[ $this->id() ][ $key ] ?? $default;
	}

	public function is_constant( string $key ): bool {
		return defined( 'OPENEVENTS_' . strtoupper( $this->id() . '_' . $key ) );
	}

	public function enabled(): bool {
		return (bool) $this->setting( 'enabled', false );
	}

	public function handle_return( Order $order, array $query ): PaymentResult {
		return PaymentResult::pending();
	}

	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'ok' => false ), 404 );
	}

	public function refund( Order $order ): true|\WP_Error {
		return new \WP_Error( 'openevents_refund_unsupported', __( 'This payment method can’t issue refunds. Refund the buyer directly.', 'openevents' ) );
	}

	public function settings_fields(): array {
		return array(
			'enabled' => array(
				'label' => __( 'Enabled', 'openevents' ),
				'type'  => 'toggle',
			),
		);
	}

	/**
	 * Where the provider sends the buyer back to.
	 */
	protected function return_url( Order $order, string $outcome ): string {
		return add_query_arg(
			array(
				'oe_checkout' => $outcome,
				'oe_order'    => $order->uuid,
			),
			rest_url( 'openevents/v1/orders/return' )
		);
	}

	protected function webhook_url(): string {
		return rest_url( 'openevents/v1/gateways/' . $this->id() . '/webhook' );
	}
}
