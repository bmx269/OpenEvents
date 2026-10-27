/**
 * Editor side of the "open-events-calendar/event" block bindings source:
 * previews bound values from the event being edited and lists the fields in
 * the block bindings UI. Values are read-only; edit them in the Event panel.
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { dateI18n, getSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

const FIELDS = {
	date: __( 'Event date and time', 'open-events-calendar' ),
	start_date: __( 'Start date', 'open-events-calendar' ),
	start_time: __( 'Start time', 'open-events-calendar' ),
	end_date: __( 'End date', 'open-events-calendar' ),
	end_time: __( 'End time', 'open-events-calendar' ),
	venue: __( 'Venue name', 'open-events-calendar' ),
	venue_address: __( 'Venue address', 'open-events-calendar' ),
	venue_url: __( 'Venue link', 'open-events-calendar' ),
	cost: __( 'Cost', 'open-events-calendar' ),
	website: __( 'Event website', 'open-events-calendar' ),
};

const toDate = ( value ) =>
	value ? new Date( value.replace( ' ', 'T' ) ) : null;

function valueFor( key, meta, venue ) {
	const { formats } = getSettings();
	const start = toDate( meta._oe_start );
	const end = toDate( meta._oe_end ) || start;
	const allDay = !! meta._oe_all_day;
	const date = ( d ) => ( d ? dateI18n( formats.date, d ) : '' );
	const time = ( d ) => ( d && ! allDay ? dateI18n( formats.time, d ) : '' );

	switch ( key ) {
		case 'date': {
			if ( ! start ) {
				return '';
			}
			const sameDay = date( start ) === date( end );
			if ( allDay ) {
				return sameDay
					? date( start )
					: `${ date( start ) } – ${ date( end ) }`;
			}
			return sameDay
				? `${ date( start ) }, ${ time( start ) } – ${ time( end ) }`
				: `${ date( start ) }, ${ time( start ) } – ${ date(
						end
				  ) }, ${ time( end ) }`;
		}
		case 'start_date':
			return date( start );
		case 'start_time':
			return time( start );
		case 'end_date':
			return date( end );
		case 'end_time':
			return time( end );
		case 'venue':
			return venue?.title?.raw ?? venue?.title?.rendered ?? '';
		case 'venue_address':
			return venue
				? [
						venue.meta?._oe_address,
						venue.meta?._oe_city,
						venue.meta?._oe_region,
						venue.meta?._oe_country,
				  ]
						.filter( Boolean )
						.join( ', ' )
				: '';
		case 'venue_url':
			return venue?.link ?? '';
		case 'cost':
			return meta._oe_cost ?? '';
		case 'website':
			return meta._oe_url ?? '';
	}
	return '';
}

registerBlockBindingsSource( {
	name: 'open-events-calendar/event',
	label: __( 'Event', 'open-events-calendar' ),
	usesContext: [ 'postId', 'postType' ],
	getValues( { select, context, bindings } ) {
		const values = {};
		const record =
			context?.postId && context?.postType
				? select( coreStore ).getEditedEntityRecord(
						'postType',
						context.postType,
						context.postId
				  )
				: null;
		const meta = record?.meta || {};
		const venue = meta._oe_venue_id
			? select( coreStore ).getEntityRecord(
					'postType',
					'oe_venue',
					meta._oe_venue_id
			  )
			: null;

		for ( const [ attribute, { args } ] of Object.entries( bindings ) ) {
			// Outside an event (e.g. editing a template) show the field name as a placeholder.
			values[ attribute ] = record
				? valueFor( args?.key, meta, venue ) ||
				  FIELDS[ args?.key ] ||
				  ''
				: FIELDS[ args?.key ] || '';
		}
		return values;
	},
	canUserEditValue: () => false,
	getFieldsList() {
		return Object.entries( FIELDS ).map( ( [ key, label ] ) => ( {
			label,
			type: 'string',
			args: { key },
		} ) );
	},
} );
