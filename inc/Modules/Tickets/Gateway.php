<?php
/**
 * Payment gateway contract.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Order;

/**
 * A way to pay for tickets. Register with the openevents/tickets/gateways
 * filter; extend AbstractGateway for settings storage and defaults.
 *
 * Gateways never see card details: hosted gateways redirect to the
 * provider's checkout and confirm through return handling and webhooks.
 */
interface Gateway {

	public function id(): string;

	public function label(): string;

	/** Shown to buyers next to the payment option. */
	public function description(): string;

	/** Configured, enabled, and able to charge in this currency. */
	public function is_available( string $currency ): bool;

	/** Called once the order and seat holds exist. */
	public function start_payment( Order $order ): PaymentResult;

	/**
	 * The buyer came back from the provider (return URL). Confirm with the
	 * provider (never trust query args alone).
	 *
	 * @param array<string, string> $query Return URL query args.
	 */
	public function handle_return( Order $order, array $query ): PaymentResult;

	/** Provider-to-site notification (POST /openevents/v1/gateways/{id}/webhook). */
	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response;

	public function refund( Order $order ): true|\WP_Error;

	/**
	 * Settings form definition: key => [label, type (text|secret|select|toggle), options?, help?].
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_fields(): array;
}
