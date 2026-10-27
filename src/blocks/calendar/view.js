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

store( 'open-events-calendar/calendar', {
	actions: {
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
			document
				.getElementById( regionId )
				?.querySelector( '.oe-calendar__title' )
				?.focus();
		} ),
	},
} );
