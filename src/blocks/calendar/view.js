/**
 * Upgrades calendar navigation links to in-place updates. The links work
 * without this script; it only avoids full page loads.
 */
import { store, getElement, withSyncEvent } from '@wordpress/interactivity';

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

store( 'openevents/calendar', {
	actions: {
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
