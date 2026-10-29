/**
 * Ticket checkout: shows a running total and submits over REST, then sends
 * the buyer to the payment provider or their confirmation. Without this
 * script the form posts normally.
 */
import {
	store,
	getContext,
	getElement,
	withSyncEvent,
} from '@wordpress/interactivity';
import formObject from '../shared/form-object';

const nextFrame = () =>
	new Promise( ( resolve ) => window.requestAnimationFrame( resolve ) );

const { state } = store( 'openevents/tickets', {
	state: {
		/** Chosen quantity per ticket type id (set from the quantity pickers). */
		quantities: {},
		/** A ticket holder group is beyond the quantity chosen for its type. */
		get groupHidden() {
			const context = getContext();
			return context.n > ( state.quantities[ context.typeId ] || 0 );
		},
		get groupRequired() {
			const context = getContext();
			return context.n <= ( state.quantities[ context.typeId ] || 0 );
		},
		get holdersHidden() {
			return ! state.quantities[ getContext().typeId ];
		},
	},
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

		/** Joins a sold-out ticket type's waitlist. */
		joinWaitlist: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			const form = event.target;
			const data = Object.fromEntries( new window.FormData( form ) );
			data.quantity = Number( data.quantity || 1 );
			data.ticket_type_id = Number( data.ticket_type_id );
			context.busy = true;
			try {
				const response = yield window.fetch( context.waitlistEndpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( data ),
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
		recalculate() {
			const context = getContext();
			const { ref } = getElement();
			const form = ref.closest( 'form' ) || ref;
			let minor = 0;
			let count = 0;
			const quantities = {};
			form.querySelectorAll( 'select[data-price]' ).forEach(
				( select ) => {
					const quantity = Number( select.value ) || 0;
					minor += quantity * Number( select.dataset.price );
					count += quantity;
					quantities[ select.dataset.type ] = quantity;
				}
			);
			state.quantities = quantities;
			context.total = count
				? new Intl.NumberFormat( context.locale, {
						style: 'currency',
						currency: context.currency,
				  } ).format( minor / 10 ** context.decimals )
				: '';
		},
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			const form = event.target;
			const data = formObject( form );
			Object.keys( data.tickets || {} ).forEach( ( id ) => {
				data.tickets[ id ] = Number( data.tickets[ id ] );
			} );

			context.busy = true;
			context.message = '';
			try {
				const response = yield window.fetch( context.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( data ),
				} );
				const result = yield response.json();
				if ( result.ok && result.redirect ) {
					// To the payment provider, or to the confirmation.
					window.location.assign( result.redirect );
					return;
				}
				context.message = result.message || context.fallbackError;
				context.error = true;
			} catch ( e ) {
				context.message = context.fallbackError;
				context.error = true;
			}
			context.busy = false;
			yield nextFrame();
			form.closest( '.oe-tickets' )
				?.querySelector( '.oe-tickets__live' )
				?.focus();
		} ),
	},
} );
