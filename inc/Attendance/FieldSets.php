<?php
/**
 * Saved attendee question sets.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Named lists of attendee questions that editors reuse across events (Event
 * Tickets Plus calls them fieldsets). Applying a set copies its questions to
 * the RSVP or ticket, so later edits to the set don't change past events.
 *
 * GET  /openevents/v1/field-sets         [ { name, fields } ]
 * POST /openevents/v1/field-sets         { name, fields } adds or replaces by name
 * DELETE /openevents/v1/field-sets/{n}   removes the set at index n
 */
final class FieldSets {

	public const OPTION = 'openevents_field_sets';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * @return array<int, array{name: string, fields: array<int, array<string, mixed>>}>
	 */
	public static function all(): array {
		$sets = get_option( self::OPTION, array() );
		return is_array( $sets ) ? array_values( $sets ) : array();
	}

	/**
	 * Adds a set, or replaces the one with the same name.
	 *
	 * @param mixed $fields Definitions.
	 * @return array<int, array{name: string, fields: array<int, array<string, mixed>>}>
	 */
	public static function save( string $name, mixed $fields ): array {
		$name = trim( sanitize_text_field( $name ) );
		$sets = array_values( array_filter( self::all(), static fn( $set ) => ( $set['name'] ?? '' ) !== $name ) );
		if ( '' !== $name ) {
			$sets[] = array(
				'name'   => $name,
				'fields' => Fields::sanitize( $fields ),
			);
			usort( $sets, static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
		}
		update_option( self::OPTION, $sets, false );
		return $sets;
	}

	public static function routes(): void {
		$can = static fn() => current_user_can( 'edit_posts' );
		register_rest_route(
			'openevents/v1',
			'/field-sets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static fn() => new WP_REST_Response( self::all() ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => static fn( WP_REST_Request $request ) => new WP_REST_Response( self::save( (string) $request['name'], $request['fields'] ) ),
					'permission_callback' => $can,
					'args'                => array(
						'name'   => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
						),
						'fields' => Fields::schema() + array( 'required' => true ),
					),
				),
			)
		);
		register_rest_route(
			'openevents/v1',
			'/field-sets/(?P<index>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => static function ( WP_REST_Request $request ) {
					$sets = self::all();
					unset( $sets[ (int) $request['index'] ] );
					update_option( self::OPTION, array_values( $sets ), false );
					return new WP_REST_Response( array_values( $sets ) );
				},
				'permission_callback' => $can,
			)
		);
	}
}
