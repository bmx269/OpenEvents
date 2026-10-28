<?php
/**
 * Pay later / at the door.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets\Gateways;

use OpenEvents\Attendance\Order;
use OpenEvents\Modules\Tickets\AbstractGateway;
use OpenEvents\Modules\Tickets\PaymentResult;

/**
 * Confirms tickets immediately and records them as unpaid; an organiser
 * marks the order paid later (cash at the door, invoice, e-transfer).
 */
final class Manual extends AbstractGateway {

	public function id(): string {
		return 'manual';
	}

	public function label(): string {
		return (string) $this->setting( 'title', '' ) ?: __( 'Pay at the door', 'openevents' );
	}

	public function description(): string {
		return (string) $this->setting( 'instructions', '' ) ?: __( 'Your tickets are reserved now; pay when you arrive.', 'openevents' );
	}

	public function is_available( string $currency ): bool {
		return $this->enabled();
	}

	public function start_payment( Order $order ): PaymentResult {
		return PaymentResult::unpaid();
	}

	public function refund( Order $order ): true|\WP_Error {
		// Nothing was charged online, so cancelling is the refund.
		return true;
	}

	public function settings_fields(): array {
		return parent::settings_fields() + array(
			'title'        => array(
				'label' => __( 'Name shown at checkout', 'openevents' ),
				'type'  => 'text',
				'help'  => __( 'For example “Pay at the door” or “Pay by invoice”.', 'openevents' ),
			),
			'instructions' => array(
				'label' => __( 'Instructions', 'openevents' ),
				'type'  => 'text',
				'help'  => __( 'Shown at checkout and in the confirmation email.', 'openevents' ),
			),
		);
	}
}
