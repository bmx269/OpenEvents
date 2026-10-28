<?php
/**
 * Attendee value object.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

final class Attendee {

	public const GOING     = 'going';
	public const NOT_GOING = 'not_going';
	public const CANCELLED = 'cancelled';

	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly string $group_uuid,
		public readonly int $ticket_type_id,
		public readonly int $event_id,
		public readonly string $name,
		public readonly string $email,
		public readonly string $status,
		public readonly string $security_code,
		public readonly ?string $checked_in_utc,
		public readonly string $created_utc,
		public readonly array $fields = array(),
	) {}

	public static function from_row( object $row ): Attendee {
		$fields = isset( $row->fields ) ? json_decode( (string) $row->fields, true ) : array();
		return new self(
			(int) $row->id,
			(string) $row->uuid,
			(string) $row->group_uuid,
			(int) $row->ticket_type_id,
			(int) $row->event_id,
			(string) $row->name,
			(string) $row->email,
			(string) $row->status,
			(string) $row->security_code,
			$row->checked_in_utc ?: null,
			(string) $row->created_utc,
			is_array( $fields ) ? $fields : array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id,
			'uuid'           => $this->uuid,
			'group'          => $this->group_uuid,
			'ticket_type_id' => $this->ticket_type_id,
			'event_id'       => $this->event_id,
			'name'           => $this->name,
			'email'          => $this->email,
			'status'         => $this->status,
			'checked_in'     => null !== $this->checked_in_utc,
			'checked_in_utc' => $this->checked_in_utc,
			'fields'         => (object) $this->fields,
			'created_utc'    => $this->created_utc,
		);
	}
}
