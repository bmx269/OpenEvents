/**
 * Admin app: settings screen and setup wizard, built on the WordPress Design
 * System (@wordpress/ui and @wordpress/admin-ui).
 */
import { createRoot } from '@wordpress/element';
import SettingsScreen from './settings-screen';
import SetupScreen from './setup-screen';
import './admin.scss';

const root = document.getElementById( 'open-events-calendar-admin' );
if ( root ) {
	createRoot( root ).render(
		root.dataset.screen === 'setup' ? <SetupScreen /> : <SettingsScreen />
	);
}
