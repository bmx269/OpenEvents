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

const nextFrame = () =>
	new Promise( ( resolve ) => window.requestAnimationFrame( resolve ) );

store( 'openevents/tickets', {
	actions: {
		recalculate() {
			const context = getContext();
			const { ref } = getElement();
			const form = ref.closest( 'form' ) || ref;
			let minor = 0;
			let count = 0;
			form.querySelectorAll( 'select[data-price]' ).forEach(
				( select ) => {
					const quantity = Number( select.value ) || 0;
					minor += quantity * Number( select.dataset.price );
					count += quantity;
				}
			);
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
			const data = { tickets: {} };
			for ( const [ key, value ] of new window.FormData( form ) ) {
				const match = key.match( /^tickets\[(\d+)\]$/ );
				if ( match ) {
					data.tickets[ match[ 1 ] ] = Number( value );
				} else {
					data[ key ] = value;
				}
			}

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
