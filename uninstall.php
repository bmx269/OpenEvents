<?php
/**
 * Uninstall. Data is only removed when the "Remove all data on uninstall"
 * setting is on, so deleting the plugin to reinstall it never loses events.
 *
 * @package OpenEvents
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/openevents.php';

$openevents_settings = get_option( OpenEvents\Settings::OPTION, array() );

if ( ! empty( $openevents_settings['remove_data_on_uninstall'] ) ) {
	// Post types must exist for wp_delete_post() to clean up meta and terms.
	( new OpenEvents\Content\PostTypes( new OpenEvents\Settings() ) )->register_types();
	OpenEvents\Installer::uninstall();
}
