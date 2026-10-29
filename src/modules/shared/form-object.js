/**
 * A form's fields as nested JSON, the way PHP reads the same POST:
 * "attendees[0][fields][phone]" becomes { attendees: { 0: { fields: { phone } } } }
 * and "…[]" collects a list (checkboxes). Disabled fields (unused attendee
 * groups) are left out, as in a normal submission.
 *
 * @param {HTMLFormElement} form Form.
 * @return {Object} Data.
 */
export default function formObject( form ) {
	const data = {};
	for ( const [ name, value ] of new window.FormData( form ) ) {
		const keys = name.replace( /\]/g, '' ).split( '[' );
		let node = data;
		for ( let i = 0; i < keys.length; i++ ) {
			const key = keys[ i ];
			if ( keys[ i + 1 ] === '' ) {
				node[ key ] = Array.isArray( node[ key ] ) ? node[ key ] : [];
				node[ key ].push( value );
				break;
			}
			if ( i === keys.length - 1 ) {
				node[ key ] = value;
				break;
			}
			node[ key ] =
				node[ key ] && typeof node[ key ] === 'object'
					? node[ key ]
					: {};
			node = node[ key ];
		}
	}
	return data;
}
