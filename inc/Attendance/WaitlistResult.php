<?php
/**
 * Waitlist outcome.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Codes: waitlisted, exists, left (success); invalid, disabled, available.
 */
final class WaitlistResult {

	private function __construct(
		public readonly bool $ok,
		public readonly string $code,
		public readonly string $message,
		public readonly ?WaitlistEntry $entry = null,
		public readonly string $token = '',
		public readonly int $position = 0,
	) {}

	public static function ok( string $code, string $message, ?WaitlistEntry $entry = null, string $token = '', int $position = 0 ): self {
		return new self( true, $code, $message, $entry, $token, $position );
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
			'position' => $this->position ?: null,
		);
	}
}
