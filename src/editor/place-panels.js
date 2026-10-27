import { __ } from '@wordpress/i18n';
import { Slot, TextControl, Flex } from '@wordpress/components';

function Field( { meta, setMeta, metaKey, label, type = 'text', help } ) {
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			type={ type }
			label={ label }
			help={ help }
			value={ meta[ metaKey ] ?? '' }
			onChange={ ( value ) => setMeta( { [ metaKey ]: value } ) }
		/>
	);
}

export function VenuePanel( { meta, setMeta } ) {
	const props = { meta, setMeta };
	return (
		<Flex direction="column" gap={ 4 }>
			<Field
				{ ...props }
				metaKey="_oe_address"
				label={ __( 'Address', 'openevents' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_city"
				label={ __( 'City', 'openevents' ) }
			/>
			<Flex align="flex-start">
				<Field
					{ ...props }
					metaKey="_oe_region"
					label={ __( 'State / Province', 'openevents' ) }
				/>
				<Field
					{ ...props }
					metaKey="_oe_postal_code"
					label={ __( 'Postal code', 'openevents' ) }
				/>
			</Flex>
			<Field
				{ ...props }
				metaKey="_oe_country"
				label={ __( 'Country', 'openevents' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_phone"
				type="tel"
				label={ __( 'Phone', 'openevents' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_website"
				type="url"
				label={ __( 'Website', 'openevents' ) }
			/>
			<Flex align="flex-start">
				<Field
					{ ...props }
					metaKey="_oe_lat"
					label={ __( 'Latitude', 'openevents' ) }
				/>
				<Field
					{ ...props }
					metaKey="_oe_lng"
					label={ __( 'Longitude', 'openevents' ) }
				/>
			</Flex>
			<Slot name="OpenEventsVenuePanel" fillProps={ { meta, setMeta } } />
		</Flex>
	);
}

export function OrganizerPanel( { meta, setMeta } ) {
	const props = { meta, setMeta };
	return (
		<Flex direction="column" gap={ 4 }>
			<Field
				{ ...props }
				metaKey="_oe_email"
				type="email"
				label={ __( 'Email', 'openevents' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_phone"
				type="tel"
				label={ __( 'Phone', 'openevents' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_website"
				type="url"
				label={ __( 'Website', 'openevents' ) }
			/>
			<Slot
				name="OpenEventsOrganizerPanel"
				fillProps={ { meta, setMeta } }
			/>
		</Flex>
	);
}
