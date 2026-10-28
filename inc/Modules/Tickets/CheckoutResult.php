<?php
/**
 * Checkout outcome.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

use OpenEvents\Attendance\Order;

/**
 * confirmed: done, show tickets. redirect: send the buyer to the provider.
 * Errors: invalid, closed, full, rate_limited, payment_failed.
 */
final class CheckoutResult {

	private function __construct(
		public readonly bool $ok,
		public readonly string $code,
		public readonly string $message = '',
		public readonly ?Order $order = null,
		public readonly string $token = '',
		public readonly string $redirect = '',
	) {}

	public static function confirmed( Order $order, string $token, string $message ): self {
		return new self( true, 'confirmed', $message, $order, $token, Checkout::confirmation_url( $order, $token ) );
	}

	public static function redirect( Order $order, string $token, string $url ): self {
		return new self( true, 'redirect', '', $order, $token, $url );
	}

	public static function error( string $code, string $message ): self {
		return new self( false, $code, $message );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'ok'       => $this->ok,
			'code'     => $this->code,
			'message'  => $this->message,
			'redirect' => $this->redirect,
		);
	}
}
