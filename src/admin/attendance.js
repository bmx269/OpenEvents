/**
 * Attendance admin entry: Events → Attendees, Orders, Waitlist, and Check-in. Separate
 * from the main admin app so DataViews and its stylesheet load only here.
 */
import { createRoot } from '@wordpress/element';
import AttendeesScreen from './attendees-screen';
import CheckInScreen from './check-in-screen';
import OrdersScreen from './orders-screen';
import WaitlistScreen from './waitlist-screen';
import './attendance.scss';
import './admin.scss';

const root = document.getElementById( 'openevents-attendance' );
if ( root ) {
	createRoot( root ).render(
		{
			orders: <OrdersScreen root={ root } />,
			waitlist: <WaitlistScreen root={ root } />,
			'check-in': <CheckInScreen root={ root } />,
		}[ root.dataset.screen ] || <AttendeesScreen root={ root } />
	);
}
