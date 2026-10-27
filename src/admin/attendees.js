/**
 * Events → Attendees entry. Separate from the main admin app so DataViews and
 * its stylesheet load only on this screen.
 */
import { createRoot } from '@wordpress/element';
import AttendeesScreen from './attendees-screen';
import './attendees.scss';
import './admin.scss';

const root = document.getElementById( 'openevents-attendees' );
if ( root ) {
	createRoot( root ).render( <AttendeesScreen root={ root } /> );
}
