<?php
/**
 * Order value object.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

final class Order {

	public const PENDING   = 'pending';   // Seats held; waiting for the payment provider.
	public const UNPAID    = 'unpaid';    // Confirmed; to be paid later (manual gateway).
	public const COMPLETED = 'completed'; // Paid (or free).
	public const CANCELLED = 'cancelled';
	public const EXPIRED   = 'expired';   // Hold ran out before payment.
	public const FAILED    = 'failed';
	public const REFUNDED  = 'refunded';

	/** Statuses whose tickets are valid for entry. */
	public const VALID = array( self::UNPAID, self::COMPLETED );

	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly int $event_id,
		public readonly string $status,
		public readonly string $gateway,
		public readonly string $gateway_ref,
		public readonly string $gateway_txn,
		public readonly string $name,
		public readonly string $email,
		public readonly int $total_minor,
		public readonly string $currency,
		public readonly ?string $expires_utc,
		public readonly string $created_utc,
		/** @var array<string, mixed> */
		public readonly array $meta,
	) {}

	public static function from_row( object $row ): Order {
		return new self(
			(int) $row->id,
			(string) $row->uuid,
			(int) $row->event_id,
			(string) $row->status,
			(string) $row->gateway,
			(string) $row->gateway_ref,
			(string) $row->gateway_txn,
			(string) $row->name,
			(string) $row->email,
			(int) $row->total_minor,
			(string) $row->currency,
			$row->expires_utc ?: null,
			(string) $row->created_utc,
			(array) json_decode( (string) $row->meta, true ),
		);
	}

	public function is_final(): bool {
		return in_array( $this->status, array( self::COMPLETED, self::CANCELLED, self::EXPIRED, self::FAILED, self::REFUNDED ), true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'uuid'        => $this->uuid,
			'event_id'    => $this->event_id,
			'status'      => $this->status,
			'gateway'     => $this->gateway,
			'gateway_ref' => $this->gateway_ref,
			'name'        => $this->name,
			'email'       => $this->email,
			'total'       => $this->total_minor,
			'total_text'  => Money::format( $this->total_minor, $this->currency ),
			'currency'    => $this->currency,
			'created_utc' => $this->created_utc,
		);
	}
}
