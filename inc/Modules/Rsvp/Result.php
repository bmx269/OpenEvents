<?php
/**
 * RSVP outcome.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Rsvp;

/**
 * Outcome of respond()/cancel(). `code` is machine-readable: going, not_going,
 * exists, cancelled (success) or invalid, closed, full, disabled, rate_limited.
 */
final class Result {

	private function __construct(
		public readonly bool $ok,
		public readonly string $code,
		public readonly string $message,
		public readonly ?string $group = null,
	) {}

	public static function ok( string $code, string $message, ?string $group = null ): self {
		return new self( true, $code, $message, $group );
	}

	public static function error( string $code, string $message ): self {
		return new self( false, $code, $message );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'ok'      => $this->ok,
			'code'    => $this->code,
			'message' => $this->message,
		);
	}
}
