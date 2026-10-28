<?php
/**
 * Gateway registry.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Tickets;

/**
 * Bundled gateways plus any added with the openevents/tickets/gateways filter.
 */
final class Gateways {

	/** @var array<string, Gateway>|null */
	private static ?array $all = null;

	/**
	 * @return array<string, Gateway>
	 */
	public static function all(): array {
		if ( null === self::$all ) {
			$gateways = array( new Gateways\Manual(), new Gateways\Stripe(), new Gateways\PayPal() );

			/**
			 * Filters the payment gateways.
			 *
			 * @param Gateway[] $gateways Gateways.
			 */
			$gateways   = apply_filters( 'openevents/tickets/gateways', $gateways );
			self::$all = array();
			foreach ( $gateways as $gateway ) {
				if ( $gateway instanceof Gateway ) {
					self::$all[ $gateway->id() ] = $gateway;
				}
			}
		}
		return self::$all;
	}

	public static function get( string $id ): ?Gateway {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * @return array<string, Gateway>
	 */
	public static function available( string $currency ): array {
		return array_filter( self::all(), static fn( Gateway $g ) => $g->is_available( $currency ) );
	}

	/**
	 * Forgets the cached list (tests, or after settings change in one request).
	 */
	public static function reset(): void {
		self::$all = null;
	}
}
