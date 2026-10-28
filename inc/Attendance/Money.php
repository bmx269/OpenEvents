<?php
/**
 * Currency amounts.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * Amounts are integers in the currency's minor unit (cents), never floats.
 */
final class Money {

	/** ISO 4217 currencies without minor units (as Stripe and PayPal treat them). */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'HUF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'TWD', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	public static function decimals( string $currency ): int {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
	}

	/**
	 * "12.50" → 1250 (USD). Accepts "12", "12.5", "12,50"; rejects negatives.
	 */
	public static function to_minor( string|int|float $amount, string $currency ): int {
		$amount   = str_replace( ',', '.', trim( (string) $amount ) );
		$decimals = self::decimals( $currency );
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $amount ) ) {
			return 0;
		}
		list( $whole, $fraction ) = array_pad( explode( '.', $amount ), 2, '' );
		$fraction                 = substr( str_pad( $fraction, $decimals, '0' ), 0, $decimals );
		return (int) ( $whole . $fraction );
	}

	/**
	 * 1250 → "12.50" (for gateway APIs that take decimal strings, e.g. PayPal).
	 */
	public static function to_decimal( int $minor, string $currency ): string {
		$decimals = self::decimals( $currency );
		return 0 === $decimals ? (string) $minor : number_format( $minor / ( 10 ** $decimals ), $decimals, '.', '' );
	}

	/**
	 * Localised display: "$12.50", "12,50 €", "¥1,200".
	 */
	public static function format( int $minor, string $currency ): string {
		$currency = strtoupper( $currency );
		if ( 0 === $minor ) {
			return __( 'Free', 'openevents' );
		}
		if ( class_exists( \NumberFormatter::class ) ) {
			$formatter = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
			$out       = $formatter->formatCurrency( $minor / ( 10 ** self::decimals( $currency ) ), $currency );
			if ( false !== $out ) {
				return $out;
			}
		}
		return $currency . ' ' . number_format_i18n( $minor / ( 10 ** self::decimals( $currency ) ), self::decimals( $currency ) );
	}
}
