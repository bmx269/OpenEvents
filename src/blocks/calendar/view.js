/**
 * Upgrades calendar navigation links to in-place updates. The links work
 * without this script; it only avoids full page loads.
 */
import {
	store,
	getContext,
	getElement,
	withSyncEvent,
} from '@wordpress/interactivity';

const isModifiedClick = ( event ) =>
	event.button !== 0 ||
	event.metaKey ||
	event.ctrlKey ||
	event.altKey ||
	event.shiftKey ||
	event.defaultPrevented;

const focusTitle = ( regionId ) =>
	document
		.getElementById( regionId )
		?.querySelector( '.oe-calendar__title' )
		?.focus();

// The element that opened the popup, so focus can return to it on close.
let opener = null;

store( 'openevents/calendar', {
	callbacks: {
		/** Opens or closes the native modal dialog to match context. */
		syncDialog() {
			const { ref } = getElement();
			const context = getContext();
			if ( context.popupOpen && ! ref.open ) {
				ref.showModal();
			} else if ( ! context.popupOpen && ref.open ) {
				ref.close();
			}
		},
	},
	actions: {
		/** Shows an event's summary popup instead of following the link. */
		openEvent: withSyncEvent( function ( event ) {
			if ( isModifiedClick( event ) ) {
				return;
			}
			event.preventDefault();
			const context = getContext();
			opener = getElement().ref;
			context.popup = context.event;
			context.popupOpen = true;
		} ),
		closePopup() {
			getContext().popupOpen = false;
		},
		/** Escape (or close()) fired the dialog's close event. */
		dialogClosed() {
			getContext().popupOpen = false;
			opener?.focus();
			opener = null;
		},
		/**
		 * A click on the backdrop (outside the inner box) closes the popup.
		 *
		 * @param {MouseEvent} event Click event.
		 */
		dialogBackdrop( event ) {
			const { ref } = getElement();
			if ( event.target === ref ) {
				getContext().popupOpen = false;
			}
		},
		/**
		 * Submits the filter form through the router, keeping only filled fields.
		 */
		filter: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const { ref } = getElement();
			const form = ref.closest( 'form' ) || ref;
			const regionId = form.closest( '[data-wp-router-region]' )?.id;
			const url = new URL( form.action, window.location.href );
			for ( const [ key, value ] of new window.FormData( form ) ) {
				if ( value ) {
					url.searchParams.set( key, value );
				}
			}
			const { actions } = yield import(
				'@wordpress/interactivity-router'
			);
			yield actions.navigate( url.toString() );
			focusTitle( regionId );
		} ),
		navigate: withSyncEvent( function* ( event ) {
			if ( isModifiedClick( event ) ) {
				return;
			}
			event.preventDefault();

			const { ref } = getElement();
			const regionId = ref.closest( '[data-wp-router-region]' )?.id;
			const { actions } = yield import(
				'@wordpress/interactivity-router'
			);
			yield actions.navigate( ref.href );

			// Move focus to the new heading so screen reader users hear the change.
			focusTitle( regionId );
		} ),
	},
} );
