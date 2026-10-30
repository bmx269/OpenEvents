<?php
/**
 * Ticket type value object.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * A row in oe_ticket_types: an RSVP or a paid ticket with optional capacity.
 */
final class TicketType {

	public function __construct(
		public readonly int $id,
		public readonly int $event_id,
		public readonly string $type_key,
		public readonly string $kind,
		public readonly string $name,
		public readonly ?int $capacity,
		public readonly int $sold,
		public readonly ?string $sale_end_utc,
		public readonly string $status,
		/** @var array<string, mixed> */
		public readonly array $settings,
		/** Which date of the event this row's seats are for; 0 is the event's own date. */
		public readonly int $sequence = 0,
	) {}

	public static function from_row( object $row ): TicketType {
		return new self(
			(int) $row->id,
			(int) $row->event_id,
			(string) $row->type_key,
			(string) $row->kind,
			(string) $row->name,
			null === $row->capacity ? null : (int) $row->capacity,
			(int) $row->sold,
			$row->sale_end_utc ?: null,
			(string) $row->status,
			(array) json_decode( (string) $row->settings, true ),
			(int) ( $row->sequence ?? 0 ),
		);
	}

	/**
	 * Seats left, or null when unlimited.
	 */
	public function remaining(): ?int {
		return null === $this->capacity ? null : max( 0, $this->capacity - $this->sold );
	}

	public function is_full(): bool {
		return null !== $this->capacity && $this->sold >= $this->capacity;
	}

	public function setting( string $key, mixed $default = null ): mixed {
		return $this->settings[ $key ] ?? $default;
	}
}
