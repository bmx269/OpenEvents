/**
 * Attendance admin entry: Events → Attendees and Events → Orders. Separate
 * from the main admin app so DataViews and its stylesheet load only here.
 */
import { createRoot } from '@wordpress/element';
import AttendeesScreen from './attendees-screen';
import OrdersScreen from './orders-screen';
import './attendance.scss';
import './admin.scss';

const root = document.getElementById( 'openevents-attendance' );
if ( root ) {
	createRoot( root ).render(
		root.dataset.screen === 'orders' ? (
			<OrdersScreen root={ root } />
		) : (
			<AttendeesScreen root={ root } />
		)
	);
}
