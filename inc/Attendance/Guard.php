<?php
/**
 * Abuse protection for public forms.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Shared by the RSVP and ticket forms. No nonces (they break on cached
 * pages); instead a honeypot, a signed timestamp that rejects instant
 * submissions, and a per-IP rate limit on hashed IPs.
 */
final class Guard {

	public const MIN_FILL = 2; // Seconds.

	public static function sign_timestamp( ?int $time = null ): string {
		$time ??= time();
		return $time . '.' . substr( hash_hmac( 'sha256', (string) $time, wp_salt( 'nonce' ) ), 0, 16 );
	}

	public static function verify_timestamp( string $value ): bool {
		$parts = explode( '.', $value );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		$expected = substr( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), 0, 16 );
		return hash_equals( $expected, $parts[1] ) && time() - (int) $parts[0] >= self::MIN_FILL;
	}

	public static function is_bot( array $input ): bool {
		return '' !== trim( (string) ( $input['website'] ?? '' ) );
	}

	public static function hash_ip( string $ip ): string {
		return '' === $ip ? '' : hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	public static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
