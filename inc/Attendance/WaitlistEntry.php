<?php
/**
 * Waitlist entry value object.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

final class WaitlistEntry {

	public const WAITING = 'waiting';  // In the queue.
	public const OFFERED = 'offered';  // Seats held for them until offer_expires_utc (tickets).
	public const CLAIMED = 'claimed';  // Promoted (RSVP) or bought (tickets).
	public const EXPIRED = 'expired';  // Offer ran out.
	public const LEFT    = 'left';     // Removed by the person or an organiser.

	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly int $ticket_type_id,
		public readonly int $event_id,
		public readonly string $name,
		public readonly string $email,
		public readonly int $quantity,
		public readonly string $status,
		public readonly int $order_id,
		public readonly ?string $offer_expires_utc,
		public readonly string $created_utc,
	) {}

	public static function from_row( object $row ): WaitlistEntry {
		return new self(
			(int) $row->id,
			(string) $row->uuid,
			(int) $row->ticket_type_id,
			(int) $row->event_id,
			(string) $row->name,
			(string) $row->email,
			(int) $row->quantity,
			(string) $row->status,
			(int) $row->order_id,
			$row->offer_expires_utc ?: null,
			(string) $row->created_utc,
		);
	}

	public function offer_is_live(): bool {
		return self::OFFERED === $this->status && $this->offer_expires_utc && $this->offer_expires_utc > gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'ticket_type_id'    => $this->ticket_type_id,
			'event_id'          => $this->event_id,
			'name'              => $this->name,
			'email'             => $this->email,
			'quantity'          => $this->quantity,
			'status'            => $this->status,
			'offer_expires_utc' => $this->offer_expires_utc,
			'created_utc'       => $this->created_utc,
		);
	}
}
