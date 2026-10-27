/**
 * Admin app: settings and setup wizard, built on the WordPress Design System
 * (@wordpress/ui, @wordpress/admin-ui). Module screens such as the attendee
 * list are separate entries so their dependencies load only where needed.
 */
import { createRoot } from '@wordpress/element';
import SettingsScreen from './settings-screen';
import SetupScreen from './setup-screen';
import './admin.scss';

const root = document.getElementById( 'openevents-admin' );
if ( root ) {
	createRoot( root ).render(
		root.dataset.screen === 'setup' ? <SetupScreen /> : <SettingsScreen />
	);
}
