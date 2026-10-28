/**
 * Submits the RSVP form over REST and shows the result in place. Without this
 * script the form posts normally and the page reloads with the result.
 */
import {
	store,
	getContext,
	getElement,
	withSyncEvent,
} from '@wordpress/interactivity';

const nextFrame = () =>
	new Promise( ( resolve ) => window.requestAnimationFrame( resolve ) );

store( 'openevents/rsvp', {
	actions: {
		/** Leaves the waitlist (or releases held tickets) with the private token. */
		leaveWaitlist: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			const form = event.target;
			context.busy = true;
			try {
				const response = yield window.fetch( context.leaveEndpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						token: new window.FormData( form ).get( 'token' ),
					} ),
				} );
				const result = yield response.json();
				context.message = result.message || context.fallbackError;
				context.error = ! result.ok;
				if ( result.ok ) {
					form.hidden = true;
				}
			} catch ( e ) {
				context.message = context.fallbackError;
				context.error = true;
			}
			context.busy = false;
			yield nextFrame();
			form.closest( 'section' )?.querySelector( '[aria-live]' )?.focus();
		} ),
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			const { ref } = getElement();
			const form = event.target;
			const data = Object.fromEntries( new window.FormData( form ) );
			if ( event.submitter?.name === 'cancel' ) {
				data.cancel = true;
			}
			data.quantity = Number( data.quantity || 1 );

			context.busy = true;
			context.message = '';
			try {
				const response = yield window.fetch( context.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( data ),
				} );
				const result = yield response.json();
				context.message = result.message || context.fallbackError;
				context.error = ! result.ok;
				context.done = !! result.ok;
				if ( result.count_text ) {
					context.countText = result.count_text;
				}
			} catch ( e ) {
				context.message = context.fallbackError;
				context.error = true;
			}
			context.busy = false;

			// Announce the result and put keyboard focus on it.
			yield nextFrame();
			ref.closest( '.oe-rsvp' )
				?.querySelector( '.oe-rsvp__live' )
				?.focus();
		} ),
	},
} );
