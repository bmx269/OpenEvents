<?php
/**
 * Attendee fields: questions asked of each person on an RSVP or ticket.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Field definitions live on the ticket type (RSVP settings, or each ticket in
 * `_oe_tickets`) as a list of
 *
 *   { key, type, label, required, help, options }
 *
 * and answers are stored per attendee in `oe_attendees.fields` as key => value
 * (a list of strings for checkboxes). Each ticket type also says whether every
 * person gives their own name and email (`attendee_details`: none, optional,
 * required), as Event Tickets Plus's per-attendee collection does.
 *
 * Forms render one group of inputs per person. Without JavaScript every group
 * is shown and only the first carries `required`; the server checks the
 * groups for the people actually booked.
 */
final class Fields {

	public const TYPES = array( 'text', 'textarea', 'email', 'tel', 'url', 'number', 'date', 'select', 'radio', 'checkbox' );

	/** Types that pick from `options`. */
	public const CHOICE_TYPES = array( 'select', 'radio', 'checkbox' );

	public const DETAILS = array( 'none', 'optional', 'required' );

	private const MAX_FIELDS = 30;

	/**
	 * REST schema for a list of definitions.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'key'      => array( 'type' => 'string' ),
					'type'     => array(
						'type' => 'string',
						'enum' => self::TYPES,
					),
					'label'    => array( 'type' => 'string' ),
					'required' => array( 'type' => 'boolean' ),
					'help'     => array( 'type' => 'string' ),
					'options'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Cleans a list of definitions: known types, unique keys (made from the
	 * label when missing), and options for choice types.
	 *
	 * @return array<int, array{key: string, type: string, label: string, required: bool, help: string, options: string[]}>
	 */
	public static function sanitize( mixed $value ): array {
		$out  = array();
		$keys = array();
		foreach ( is_array( $value ) ? array_slice( $value, 0, self::MAX_FIELDS ) : array() as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $field['label'] ?? '' ) ) );
			$type  = in_array( $field['type'] ?? '', self::TYPES, true ) ? $field['type'] : 'text';
			if ( '' === $label ) {
				continue;
			}
			$options = array();
			foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
				$option = trim( sanitize_text_field( (string) $option ) );
				if ( '' !== $option && ! in_array( $option, $options, true ) ) {
					$options[] = $option;
				}
			}
			if ( in_array( $type, array( 'select', 'radio' ), true ) && ! $options ) {
				continue;
			}
			if ( 'checkbox' === $type && ! $options ) {
				// A lone checkbox ("I agree"): its label is the question.
				$options = array( __( 'Yes', 'openevents' ) );
			}

			$key  = substr( sanitize_key( (string) ( $field['key'] ?? '' ) ) ?: sanitize_title( $label ), 0, 40 );
			$key  = '' !== $key ? $key : 'field';
			$base = $key;
			for ( $n = 2; in_array( $key, $keys, true ); $n++ ) {
				$key = substr( $base, 0, 36 ) . '-' . $n;
			}
			$keys[] = $key;

			$out[] = array(
				'key'      => $key,
				'type'     => $type,
				'label'    => $label,
				'required' => ! empty( $field['required'] ),
				'help'     => sanitize_text_field( (string) ( $field['help'] ?? '' ) ),
				'options'  => in_array( $type, self::CHOICE_TYPES, true ) ? $options : array(),
			);
		}
		return $out;
	}

	public static function sanitize_details( mixed $value ): string {
		return in_array( $value, self::DETAILS, true ) ? $value : 'none';
	}

	/**
	 * Whether a ticket type asks anything per person.
	 */
	public static function asks( TicketType $type ): bool {
		return (bool) $type->setting( 'fields', array() ) || 'none' !== self::sanitize_details( $type->setting( 'attendee_details', 'none' ) );
	}

	/**
	 * Inputs for one person.
	 *
	 * @param array<int, array<string, mixed>> $fields  Definitions.
	 * @param string                           $name    Input name prefix, e.g. "attendees[1][fields]".
	 * @param string                           $id      Id prefix (unique in the page).
	 * @param array<string, mixed>             $values  Current answers.
	 * @param bool                             $enforce Mark required inputs `required`. Groups that may
	 *                                                  go unused (guests, extra tickets) get it from the
	 *                                                  view script instead, through state.groupRequired:
	 *                                                  undefined on the server, so no-JS forms never
	 *                                                  block on a group nobody fills in.
	 */
	public static function inputs( array $fields, string $name, string $id, array $values = array(), bool $enforce = true ): string {
		$html = '';
		foreach ( $fields as $field ) {
			$key      = $field['key'];
			$input_id = $id . '-' . $key;
			$value    = $values[ $key ] ?? ( 'checkbox' === $field['type'] ? array() : '' );
			$help_id  = '' !== $field['help'] ? $input_id . '-help' : '';
			$required = $field['required'] ? ( $enforce ? ' required' : ' data-wp-bind--required="state.groupRequired"' ) : '';
			$describe = $help_id ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '';
			$help     = $help_id ? '<span class="oe-field__help" id="' . esc_attr( $help_id ) . '">' . esc_html( $field['help'] ) . '</span>' : '';
			$label    = esc_html( $field['label'] ) . ( $field['required'] ? '' : ' <span class="oe-field__optional">' . esc_html__( '(optional)', 'openevents' ) . '</span>' );
			$attr     = esc_attr( $name . '[' . $key . ']' );

			if ( 'checkbox' === $field['type'] && 1 === count( $field['options'] ) ) {
				$html .= sprintf(
					'<p class="oe-field oe-field--consent"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s%5$s%6$s> <label for="%1$s">%7$s</label>%8$s</p>',
					esc_attr( $input_id ),
					$attr,
					esc_attr( $field['options'][0] ),
					checked( in_array( $field['options'][0], (array) $value, true ), true, false ),
					$required,
					$describe,
					$label,
					$help
				);
				continue;
			}

			if ( in_array( $field['type'], array( 'radio', 'checkbox' ), true ) ) {
				$html .= '<fieldset class="oe-field oe-field--choices"' . $describe . '><legend>' . $label . '</legend>';
				foreach ( $field['options'] as $i => $option ) {
					$option_id = $input_id . '-' . $i;
					$is_on     = 'checkbox' === $field['type'] ? in_array( $option, (array) $value, true ) : $option === $value;
					// One required radio is enough to make the group required; checkbox groups are checked on the server.
					$req   = 'radio' === $field['type'] && 0 === $i ? $required : '';
					$html .= sprintf(
						'<label class="oe-field__choice" for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$s"%5$s%6$s> %7$s</label>',
						esc_attr( $option_id ),
						esc_attr( $field['type'] ),
						'checkbox' === $field['type'] ? $attr . '[]' : $attr,
						esc_attr( $option ),
						checked( $is_on, true, false ),
						$req,
						esc_html( $option )
					);
				}
				$html .= $help . '</fieldset>';
				continue;
			}

			if ( 'select' === $field['type'] ) {
				$control = '<select id="' . esc_attr( $input_id ) . '" name="' . $attr . '"' . $required . $describe . '><option value="">' . esc_html__( 'Choose…', 'openevents' ) . '</option>';
				foreach ( $field['options'] as $option ) {
					$control .= '<option value="' . esc_attr( $option ) . '"' . selected( $option, $value, false ) . '>' . esc_html( $option ) . '</option>';
				}
				$control .= '</select>';
			} elseif ( 'textarea' === $field['type'] ) {
				$control = '<textarea id="' . esc_attr( $input_id ) . '" name="' . $attr . '" rows="3" maxlength="5000"' . $required . $describe . '>' . esc_textarea( (string) $value ) . '</textarea>';
			} else {
				$autocomplete = array(
					'email' => 'email',
					'tel'   => 'tel',
					'url'   => 'url',
				)[ $field['type'] ] ?? 'off';
				$control      = sprintf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" autocomplete="%5$s"%6$s%7$s%8$s>',
					esc_attr( $field['type'] ),
					esc_attr( $input_id ),
					$attr,
					esc_attr( (string) $value ),
					esc_attr( $autocomplete ),
					in_array( $field['type'], array( 'text', 'email', 'tel', 'url' ), true ) ? ' maxlength="1000"' : '',
					$required,
					$describe
				);
			}
			$html .= '<p class="oe-field"><label for="' . esc_attr( $input_id ) . '">' . $label . '</label>' . $control . $help . '</p>';
		}
		return $html;
	}

	/**
	 * Validates and cleans one person's answers.
	 *
	 * @param array<int, array<string, mixed>> $fields Definitions.
	 * @param mixed                            $raw    Submitted key => value.
	 * @return array{values: array<string, mixed>, error: ?string}
	 */
	public static function collect( array $fields, mixed $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$values = array();
		foreach ( $fields as $field ) {
			$key   = $field['key'];
			$input = $raw[ $key ] ?? null;

			if ( 'checkbox' === $field['type'] ) {
				$picked = array_values( array_intersect( $field['options'], array_map( 'strval', (array) $input ) ) );
				if ( $field['required'] && ! $picked ) {
					return self::fail(
						$field,
						1 === count( $field['options'] )
							/* translators: %s: field label */
							? __( 'Please check “%s”.', 'openevents' )
							/* translators: %s: field label */
							: __( 'Please choose at least one option for “%s”.', 'openevents' )
					);
				}
				if ( $picked ) {
					$values[ $key ] = $picked;
				}
				continue;
			}

			$value = 'textarea' === $field['type'] ? sanitize_textarea_field( (string) $input ) : trim( sanitize_text_field( is_scalar( $input ) ? (string) $input : '' ) );
			if ( '' === $value ) {
				if ( $field['required'] ) {
					/* translators: %s: field label */
					return self::fail( $field, __( 'Please fill in “%s”.', 'openevents' ) );
				}
				continue;
			}

			$valid = match ( $field['type'] ) {
				'email'           => (bool) is_email( $value ),
				'tel'             => (bool) preg_match( '/^[0-9+().\-\s]{4,30}$/', $value ) && preg_match_all( '/\d/', $value ) >= 4,
				'url'             => false !== filter_var( $value, FILTER_VALIDATE_URL ) && (bool) preg_match( '#^https?://#i', $value ),
				'number'          => is_numeric( $value ),
				'date'            => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ),
				'select', 'radio' => in_array( $value, $field['options'], true ),
				default           => mb_strlen( $value ) <= ( 'textarea' === $field['type'] ? 5000 : 1000 ),
			};
			if ( ! $valid ) {
				/* translators: %s: field label */
				return self::fail( $field, __( 'Please check “%s”.', 'openevents' ) );
			}
			$values[ $key ] = $value;
		}
		return array(
			'values' => $values,
			'error'  => null,
		);
	}

	/**
	 * Name, email, and answers for each of $count people booked on one ticket
	 * type, from `attendees[i][name|email|fields]` (already unslashed).
	 *
	 * @param TicketType           $type   Ticket type (its settings hold the definitions).
	 * @param mixed                $raw    Submitted list of people.
	 * @param int                  $count  People booked.
	 * @param array<string, mixed> $lead   name and email of whoever is booking; used for
	 *                                     person 1 (RSVP) or as the fallback (tickets).
	 * @param bool                 $lead_is_first Person 1 is the respondent (RSVP), so their
	 *                                     own name and email aren't asked again.
	 * @param string               $label  Prefix for errors, e.g. the ticket name.
	 * @return array{people: array<int, array{name: string, email: string, fields: array<string, mixed>}>, error: ?string}
	 */
	public static function people( TicketType $type, mixed $raw, int $count, array $lead, bool $lead_is_first, string $label = '' ): array {
		$fields  = self::sanitize( $type->setting( 'fields', array() ) );
		$details = self::sanitize_details( $type->setting( 'attendee_details', 'none' ) );
		$raw     = is_array( $raw ) ? array_values( $raw ) : array();
		$people  = array();

		for ( $i = 0; $i < max( 1, $count ); $i++ ) {
			$input  = is_array( $raw[ $i ] ?? null ) ? $raw[ $i ] : array();
			$person = array(
				'name'   => '',
				'email'  => '',
				'fields' => array(),
			);
			$who    = self::person_label( $i, $lead_is_first, $label );

			if ( $lead_is_first && 0 === $i ) {
				$person['name']  = (string) $lead['name'];
				$person['email'] = (string) $lead['email'];
			} elseif ( 'none' !== $details ) {
				$person['name']  = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
				$person['email'] = sanitize_email( (string) ( $input['email'] ?? '' ) );
				if ( 'required' === $details && ( '' === $person['name'] || ! is_email( $person['email'] ) ) ) {
					return array(
						'people' => array(),
						/* translators: %s: who, e.g. "Guest 2" or "Adult, ticket 1" */
						'error'  => sprintf( __( '%s: please enter a name and email.', 'openevents' ), $who ),
					);
				}
				if ( '' !== $person['email'] && ! is_email( $person['email'] ) ) {
					return array(
						'people' => array(),
						/* translators: %s: who, e.g. "Guest 2" or "Adult, ticket 1" */
						'error'  => sprintf( __( '%s: please check the email address.', 'openevents' ), $who ),
					);
				}
			}

			if ( $fields ) {
				$answers = self::collect( $fields, $input['fields'] ?? array() );
				if ( $answers['error'] ) {
					return array(
						'people' => array(),
						'error'  => $who . ': ' . $answers['error'],
					);
				}
				$person['fields'] = $answers['values'];
			}
			$people[] = $person;
		}

		return array(
			'people' => $people,
			'error'  => null,
		);
	}

	/**
	 * "You", "Guest 2", or "Adult, ticket 2": who a group of inputs is for.
	 */
	public static function person_label( int $index, bool $lead_is_first, string $type_name = '' ): string {
		if ( $lead_is_first ) {
			/* translators: %d: guest number (2 for the first guest) */
			return 0 === $index ? __( 'You', 'openevents' ) : sprintf( __( 'Guest %d', 'openevents' ), $index + 1 );
		}
		/* translators: 1: ticket name, 2: ticket number */
		return sprintf( __( '%1$s, ticket %2$d', 'openevents' ), $type_name, $index + 1 );
	}

	/**
	 * Name and email inputs for a person other than the respondent.
	 *
	 * @param array<string, mixed> $values Current name and email.
	 */
	public static function contact_inputs( string $details, string $name, string $id, array $values, bool $enforce ): string {
		if ( 'none' === $details ) {
			return '';
		}
		$req  = 'required' === $details ? ( $enforce ? ' required' : ' data-wp-bind--required="state.groupRequired"' ) : '';
		$opt  = 'required' === $details ? '' : ' <span class="oe-field__optional">' . esc_html__( '(optional)', 'openevents' ) . '</span>';
		$html = '<p class="oe-field"><label for="' . esc_attr( $id ) . '-name">' . esc_html__( 'Name', 'openevents' ) . $opt . '</label><input id="' . esc_attr( $id ) . '-name" type="text" name="' . esc_attr( $name ) . '[name]" maxlength="200" autocomplete="off" value="' . esc_attr( (string) ( $values['name'] ?? '' ) ) . '"' . $req . '></p>';
		$html .= '<p class="oe-field"><label for="' . esc_attr( $id ) . '-email">' . esc_html__( 'Email', 'openevents' ) . $opt . '</label><input id="' . esc_attr( $id ) . '-email" type="email" name="' . esc_attr( $name ) . '[email]" autocomplete="off" value="' . esc_attr( (string) ( $values['email'] ?? '' ) ) . '"' . $req . '></p>';
		return $html;
	}

	/**
	 * Display text for one answer (checkbox lists joined).
	 *
	 * @param mixed $value Stored answer.
	 */
	public static function display( mixed $value ): string {
		return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
	}

	/**
	 * @param array<string, mixed> $field Definition.
	 * @return array{values: array<string, mixed>, error: string}
	 */
	private static function fail( array $field, string $message ): array {
		// Consent labels can be paragraphs; quote the start.
		$label = mb_strlen( $field['label'] ) > 80 ? rtrim( mb_substr( $field['label'], 0, 78 ) ) . '…' : $field['label'];
		return array(
			'values' => array(),
			'error'  => sprintf( $message, $label ),
		);
	}

	/**
	 * One person's group: a fieldset the view script hides and disables while
	 * it's beyond the chosen quantity (state.groupHidden; undefined on the
	 * server, so without JavaScript every group shows).
	 *
	 * @param array<string, mixed> $context Interactivity context for the group (n, and typeId for tickets).
	 */
	public static function group( string $legend, string $inner, array $context ): string {
		return '<fieldset class="oe-person" data-wp-context="' . esc_attr( (string) wp_json_encode( $context ) ) . '" data-wp-bind--hidden="state.groupHidden" data-wp-bind--disabled="state.groupHidden"><legend>' . esc_html( $legend ) . '</legend>' . $inner . '</fieldset>';
	}

	/**
	 * An attendee's answers with their question labels, in question order.
	 *
	 * @param array<int, TicketType|null> $types Ticket types by id (a cache the caller keeps).
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	public static function answers( Attendee $attendee, array &$types ): array {
		if ( ! $attendee->fields ) {
			return array();
		}
		if ( ! array_key_exists( $attendee->ticket_type_id, $types ) ) {
			$types[ $attendee->ticket_type_id ] = ( new TicketTypes() )->get( $attendee->ticket_type_id );
		}
		return self::labelled( $attendee->fields, $types[ $attendee->ticket_type_id ] );
	}

	/**
	 * Answers with their question labels, in question order. Answers whose
	 * question was since removed (or came from an import) keep their key as
	 * the label, so nothing is dropped.
	 *
	 * @param array<string, mixed> $values Stored answers.
	 * @param TicketType|null      $type   Ticket type whose settings hold the questions.
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	public static function labelled( array $values, ?TicketType $type ): array {
		$labels = array();
		foreach ( self::sanitize( $type ? $type->setting( 'fields', array() ) : array() ) as $field ) {
			$labels[ $field['key'] ] = $field['label'];
		}
		$out = array();
		// `+` keeps keys (array_merge would renumber a numeric key such as "18").
		foreach ( array_intersect_key( $labels, $values ) + $values as $key => $unused ) {
			$out[] = array(
				'key'   => (string) $key,
				'label' => $labels[ $key ] ?? (string) $key,
				'value' => self::display( $values[ $key ] ),
			);
		}
		return $out;
	}
}
