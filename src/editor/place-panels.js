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
				label={ __( 'Address', 'open-events-calendar' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_city"
				label={ __( 'City', 'open-events-calendar' ) }
			/>
			<Flex align="flex-start">
				<Field
					{ ...props }
					metaKey="_oe_region"
					label={ __( 'State / Province', 'open-events-calendar' ) }
				/>
				<Field
					{ ...props }
					metaKey="_oe_postal_code"
					label={ __( 'Postal code', 'open-events-calendar' ) }
				/>
			</Flex>
			<Field
				{ ...props }
				metaKey="_oe_country"
				label={ __( 'Country', 'open-events-calendar' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_phone"
				type="tel"
				label={ __( 'Phone', 'open-events-calendar' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_website"
				type="url"
				label={ __( 'Website', 'open-events-calendar' ) }
			/>
			<Flex align="flex-start">
				<Field
					{ ...props }
					metaKey="_oe_lat"
					label={ __( 'Latitude', 'open-events-calendar' ) }
				/>
				<Field
					{ ...props }
					metaKey="_oe_lng"
					label={ __( 'Longitude', 'open-events-calendar' ) }
				/>
			</Flex>
			<Slot
				name="OpenEventsCalendarVenuePanel"
				fillProps={ { meta, setMeta } }
			/>
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
				label={ __( 'Email', 'open-events-calendar' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_phone"
				type="tel"
				label={ __( 'Phone', 'open-events-calendar' ) }
			/>
			<Field
				{ ...props }
				metaKey="_oe_website"
				type="url"
				label={ __( 'Website', 'open-events-calendar' ) }
			/>
			<Slot
				name="OpenEventsCalendarOrganizerPanel"
				fillProps={ { meta, setMeta } }
			/>
		</Flex>
	);
}
