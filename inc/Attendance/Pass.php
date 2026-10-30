<?php
/**
 * Check-in passes: one page per person with their QR code.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

use OpenEvents\Content\EventStatus;
use OpenEvents\Frontend\Formatter;

/**
 * Each attendee (RSVP guest or ticket holder) has a pass at a private link:
 * the event, date, ticket, their check-in code, and a QR code that opens
 * the door check-in screen for staff (any phone camera reads it; so do
 * handheld scanners, which type it into the screen).
 *
 * The link is the attendee's uuid signed with an HMAC (site salt), so it
 * needs no storage, can be sent in any email at any time, and shows only
 * that one person's pass.
 */
final class Pass {

	public const QUERY_VAR = 'oe_pass';

	/** Plain, high-contrast, printable; the page loads nothing else. */
	private const CSS = 'body{margin:0;background:#f6f7f7;color:#1e1e1e;font:1rem/1.5 system-ui,sans-serif}'
		. '.oe-pass{box-sizing:border-box;max-width:26rem;margin:0 auto;padding:1.5rem 1rem;background:#fff;min-height:100vh}'
		. '.oe-pass__kicker{margin:0;font-size:.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}'
		. 'h1{margin:.25rem 0 1rem;font-size:1.5rem;line-height:1.25}'
		. '.oe-pass__qr{margin:0 auto 1rem;max-width:18rem}.oe-qr{display:block;width:100%;height:auto}'
		. 'dl{margin:0 0 1rem}dl div{display:grid;grid-template-columns:7.5rem 1fr;gap:.5rem;padding:.4rem 0;border-top:1px solid #ddd}'
		. 'dt{font-weight:600}dd{margin:0}.oe-pass__code{font-size:1.25rem;font-weight:700;letter-spacing:.1em}'
		. '.oe-pass__alert{padding:.75rem;border:2px solid #b32d2e;border-radius:4px}'
		. '.oe-pass__note,.oe-pass__site{font-size:.875rem;color:#50575e}'
		. '@media print{body{background:#fff}.oe-pass{min-height:0}}';

	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 1 );
	}

	public static function url( Attendee $attendee ): string {
		return add_query_arg( self::QUERY_VAR, $attendee->uuid . '.' . self::signature( $attendee->uuid ), home_url( '/' ) );
	}

	/**
	 * Plain-text email lines: a heading, then each confirmed person's name,
	 * code, and pass link. Empty when nobody in the group is going.
	 *
	 * @param Attendee[] $people Attendees, such as one RSVP or order.
	 * @return string[]
	 */
	public static function email_lines( array $people ): array {
		$going = array_values( array_filter( $people, static fn( Attendee $a ) => Attendee::GOING === $a->status ) );
		if ( ! $going ) {
			return array();
		}
		$lines = array( '', __( 'Passes (show one per person at the door, on your phone or printed):', 'openevents' ) );
		foreach ( $going as $i => $attendee ) {
			$lines[] = sprintf( '  %d. %s (%s)', $i + 1, $attendee->name, $attendee->security_code );
			$lines[] = '     ' . self::url( $attendee );
		}
		return $lines;
	}

	/**
	 * The attendee behind a pass link, or null when the link isn't genuine.
	 */
	public static function attendee( string $token ): ?Attendee {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! wp_is_uuid( $parts[0], 4 ) || ! hash_equals( self::signature( $parts[0] ), $parts[1] ) ) {
			return null;
		}
		return ( new Attendees() )->find_by_uuid( $parts[0] );
	}

	/**
	 * What the QR code holds: the check-in screen with the code filled in.
	 * Opening it needs a staff login; checking in needs a tap there.
	 */
	public static function check_in_url( Attendee $attendee ): string {
		return add_query_arg(
			array(
				'page' => 'openevents-check-in',
				'code' => $attendee->security_code,
			),
			admin_url( 'admin.php' )
		);
	}

	private static function signature( string $uuid ): string {
		return substr( hash_hmac( 'sha256', 'openevents-pass|' . $uuid, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed link is the authorisation.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$token    = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$attendee = self::attendee( $token );

		// Private and personal: never cached, indexed, or leaked in a Referer.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		if ( ! $attendee ) {
			status_header( 404 );
		}
		echo self::page( $attendee ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in page().
		exit;
	}

	/**
	 * The whole pass page: plain, printable, and readable without styles.
	 */
	public static function page( ?Attendee $attendee ): string {
		$title = __( 'Check-in pass', 'openevents' );
		$body  = $attendee ? self::body( $attendee ) : '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html__( 'This pass link isn’t valid. Check the link in your email.', 'openevents' ) . '</p>';
		if ( $attendee ) {
			$title = html_entity_decode( get_the_title( $attendee->event_id ), ENT_QUOTES ) . ' · ' . $title;
		}
		return '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( $title ) . '</title><style>' . self::CSS . '</style></head>'
			. '<body><main class="oe-pass">' . $body . '<p class="oe-pass__site">' . esc_html( get_bloginfo( 'name' ) ) . '</p></main></body></html>';
	}

	private static function body( Attendee $attendee ): string {
		$event      = openevents_get_event( $attendee->event_id );
		$type       = ( new TicketTypes() )->get( $attendee->ticket_type_id );
		$occurrence = ( $type ? TicketTypes::date_of( $type ) : null ) ?? openevents()->occurrences()->for_event( $attendee->event_id );
		$valid      = Attendee::GOING === $attendee->status;
		$cancelled  = $occurrence && EventStatus::CANCELLED === $occurrence->event_status;

		$html = '<p class="oe-pass__kicker">' . esc_html__( 'Check-in pass', 'openevents' ) . '</p>';
		$html .= '<h1>' . esc_html( html_entity_decode( get_the_title( $attendee->event_id ), ENT_QUOTES ) ) . '</h1>';

		if ( $occurrence && $occurrence->is_called_off() ) {
			$reason = EventStatus::reason( $occurrence );
			$html  .= '<p class="oe-pass__alert" role="alert"><strong>' . esc_html( EventStatus::label( $occurrence->event_status ) ) . '</strong>' . ( '' !== $reason ? ' ' . esc_html( $reason ) : '' ) . '</p>';
		}
		if ( ! $valid ) {
			$html .= '<p class="oe-pass__alert" role="alert">' . esc_html__( 'This pass is no longer valid: the booking was cancelled or isn’t confirmed.', 'openevents' ) . '</p>';
		} elseif ( ! $cancelled ) {
			/* translators: %s: check-in code */
			$label = sprintf( __( 'QR code for check-in code %s', 'openevents' ), $attendee->security_code );
			$html .= '<div class="oe-pass__qr">' . QrCode::svg( self::check_in_url( $attendee ), $label ) . '</div>';
		}

		$rows = array(
			__( 'Name', 'openevents' ) => esc_html( $attendee->name ),
		);
		if ( $type ) {
			$rows[ __( 'Ticket', 'openevents' ) ] = esc_html( 'rsvp' === $type->kind ? __( 'RSVP', 'openevents' ) : $type->name );
		}
		if ( $occurrence ) {
			$rows[ __( 'When', 'openevents' ) ] = '<time datetime="' . esc_attr( Formatter::iso( $occurrence->start, $occurrence->all_day ) ) . '">' . esc_html( Formatter::range( $occurrence ) ) . '</time>';
		}
		$venue = $event?->venue();
		if ( $venue ) {
			$address = \OpenEvents\Content\Event::venue_address( $venue );
			$rows[ __( 'Where', 'openevents' ) ] = esc_html( get_the_title( $venue ) . ( $address ? ', ' . $address : '' ) );
		}
		$rows[ __( 'Check-in code', 'openevents' ) ] = '<code class="oe-pass__code">' . esc_html( $attendee->security_code ) . '</code>';
		if ( $attendee->checked_in_utc ) {
			$rows[ __( 'Checked in', 'openevents' ) ] = esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $attendee->checked_in_utc . ' UTC' ) ) );
		}
		$html .= '<dl>';
		foreach ( $rows as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
		}
		$html .= '</dl>';
		if ( $valid && ! $cancelled ) {
			$html .= '<p class="oe-pass__note">' . esc_html__( 'Show this pass, or say your code, at the door. Each person has their own pass.', 'openevents' ) . '</p>';
		}
		return $html;
	}
}
