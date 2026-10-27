import { __ } from '@wordpress/i18n';
import { FormTokenField } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Picks event categories by name; stores term ids.
 *
 * @param {Object}   props
 * @param {number[]} props.value    Selected term ids.
 * @param {Function} props.onChange Receives term ids.
 */
export default function CategoryPicker( { value, onChange } ) {
	const terms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords(
				'taxonomy',
				'oe_event_category',
				{ per_page: 100, _fields: 'id,name' }
			) ?? [],
		[]
	);
	const byId = new Map( terms.map( ( t ) => [ t.id, t.name ] ) );
	const byName = new Map( terms.map( ( t ) => [ t.name, t.id ] ) );

	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Event categories', 'open-events-calendar' ) }
			value={ value.map( ( id ) => byId.get( id ) ).filter( Boolean ) }
			suggestions={ terms.map( ( t ) => t.name ) }
			__experimentalExpandOnFocus
			onChange={ ( names ) =>
				onChange(
					names
						.map( ( name ) => byName.get( name ) )
						.filter( Boolean )
				)
			}
		/>
	);
}
