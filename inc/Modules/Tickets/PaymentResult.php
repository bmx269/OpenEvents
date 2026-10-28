<?php
/**
 * Outcome of a gateway step.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

/**
 * redirect: send the buyer to $url. paid: payment confirmed ($txn).
 * unpaid: tickets confirmed, payment later. pending: still processing.
 * failed: stop and release the seats ($message is shown).
 */
final class PaymentResult {

	private function __construct(
		public readonly string $status,
		public readonly string $url = '',
		public readonly string $reference = '',
		public readonly string $txn = '',
		public readonly string $message = '',
	) {}

	public static function redirect( string $url, string $reference ): self {
		return new self( 'redirect', $url, $reference );
	}

	public static function paid( string $txn = '', string $reference = '' ): self {
		return new self( 'paid', '', $reference, $txn );
	}

	public static function unpaid(): self {
		return new self( 'unpaid' );
	}

	public static function pending(): self {
		return new self( 'pending' );
	}

	public static function failed( string $message ): self {
		return new self( 'failed', '', '', '', $message );
	}
}
