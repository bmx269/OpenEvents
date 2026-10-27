<?php
/**
 * Plugin Name:       OpenEvents
 * Description:       Open-source events for WordPress: Calendar, with RSVP, Tickets, and more as modules. Add events to a dedicated post type or the ones you already use.
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Version:           0.1.0
 * Author:            Affinity Bridge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       openevents
 *
 * @package OpenEvents
 */

namespace OpenEvents;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__;

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$file     = PLUGIN_DIR . '/inc/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

require_once PLUGIN_DIR . '/inc/functions.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
