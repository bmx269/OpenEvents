import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ComboboxControl,
	DateTimePicker,
	Dropdown,
	FormTokenField,
	Notice,
	Slot,
	TextControl,
	ToggleControl,
	Flex,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { dateI18n, getSettings } from '@wordpress/date';
import { useMemo, useState } from '@wordpress/element';

/** Meta stores "Y-m-d H:i:s" wall-clock time; the picker uses ISO "Y-m-dTH:i:s". */
/**
 * @param {string} value Stored meta value.
 * @return {string|null} Picker value.
 */
const toPicker = ( value ) => ( value ? value.replace( ' ', 'T' ) : null );
const fromPicker = ( value ) =>
	value ? value.slice( 0, 19 ).replace( 'T', ' ' ) : '';

const parse = ( value ) =>
	value ? new Date( value.replace( ' ', 'T' ) ) : null;
const pad = ( n ) => String( n ).padStart( 2, '0' );
const format = ( date ) =>
	`${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad(
		date.getDate()
	) } ${ pad( date.getHours() ) }:${ pad( date.getMinutes() ) }:00`;

function nextHour() {
	const date = new Date();
	date.setMinutes( 0, 0, 0 );
	date.setHours( date.getHours() + 1 );
	return date;
}

function DateField( { label, value, onChange, allDay, is12Hour } ) {
	const settings = getSettings();
	const display = value
		? dateI18n(
				allDay
					? settings.formats.date
					: `${ settings.formats.date } ${ settings.formats.time }`,
				parse( value )
		  )
		: __( 'Not set', 'open-events-calendar' );

	return (
		<Flex justify="space-between" className="oe-editor-panel__row">
			<span>{ label }</span>
			<Dropdown
				popoverProps={ { placement: 'left-start', offset: 36 } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						variant="tertiary"
						onClick={ onToggle }
						aria-expanded={ isOpen }
						aria-label={ sprintf(
							/* translators: 1: field label, 2: current value */
							__( '%1$s: %2$s', 'open-events-calendar' ),
							label,
							display
						) }
					>
						{ display }
					</Button>
				) }
				renderContent={ () => (
					<div className="oe-editor-panel__picker">
						<DateTimePicker
							currentDate={ toPicker( value ) }
							onChange={ ( next ) =>
								onChange( fromPicker( next ) )
							}
							is12Hour={ is12Hour }
						/>
					</div>
				) }
			/>
		</Flex>
	);
}

/**
 * Lets editors pick an existing post or create one by typing a new name.
 *
 * @param {string} postType Linked post type.
 * @return {Array} Options and a create( title ) function.
 */
function useLinkedPosts( postType ) {
	const records = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'postType', postType, {
				per_page: 100,
				status: 'publish',
				orderby: 'title',
				order: 'asc',
				_fields: 'id,title',
			} ) ?? [],
		[ postType ]
	);
	const { saveEntityRecord } = useDispatch( coreStore );
	const create = ( title ) =>
		saveEntityRecord( 'postType', postType, { title, status: 'publish' } );
	return [
		// ComboboxControl matches values strictly; use strings throughout.
		records.map( ( r ) => ( {
			value: String( r.id ),
			label: r.title?.rendered || r.title?.raw || `#${ r.id }`,
		} ) ),
		create,
	];
}

function VenueField( { value, onChange } ) {
	const [ options, create ] = useLinkedPosts( 'oe_venue' );
	const [ typed, setTyped ] = useState( '' );
	const exists = options.some(
		( o ) => o.label.toLowerCase() === typed.trim().toLowerCase()
	);

	return (
		<>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Venue', 'open-events-calendar' ) }
				value={ value ? String( value ) : null }
				options={ options }
				onChange={ ( id ) => onChange( id ? Number( id ) : 0 ) }
				onFilterValueChange={ setTyped }
			/>
			{ typed.trim() && ! exists && (
				<Button
					variant="link"
					onClick={ async () => {
						const venue = await create( typed.trim() );
						if ( venue?.id ) {
							onChange( venue.id );
							setTyped( '' );
						}
					} }
				>
					{ sprintf(
						/* translators: %s: venue name */
						__( 'Add “%s” as a new venue', 'open-events-calendar' ),
						typed.trim()
					) }
				</Button>
			) }
		</>
	);
}

function OrganizersField( { value, onChange } ) {
	const [ options, create ] = useLinkedPosts( 'oe_organizer' );
	const byId = useMemo(
		() => new Map( options.map( ( o ) => [ Number( o.value ), o.label ] ) ),
		[ options ]
	);
	const byName = useMemo(
		() => new Map( options.map( ( o ) => [ o.label, Number( o.value ) ] ) ),
		[ options ]
	);

	const handleChange = async ( names ) => {
		const ids = [];
		for ( const name of names ) {
			const label = typeof name === 'string' ? name : name.value;
			if ( byName.has( label ) ) {
				ids.push( byName.get( label ) );
			} else if ( label.trim() ) {
				// New names become new organizers.
				const organizer = await create( label.trim() );
				if ( organizer?.id ) {
					ids.push( organizer.id );
				}
			}
		}
		onChange( ids );
	};

	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Organizers', 'open-events-calendar' ) }
			value={ ( value || [] )
				.map( ( id ) => byId.get( id ) )
				.filter( Boolean ) }
			suggestions={ options.map( ( o ) => o.label ) }
			onChange={ handleChange }
			__experimentalExpandOnFocus
			__experimentalShowHowTo={ false }
			help={ __(
				'Type a new name to create an organizer.',
				'open-events-calendar'
			) }
		/>
	);
}

export default function EventPanel( {
	meta,
	setMeta,
	postType,
	isDedicated,
	config,
} ) {
	const start = meta._oe_start;
	const end = meta._oe_end;
	const allDay = !! meta._oe_all_day;
	const isEvent = isDedicated || !! start;

	const timezoneOptions = useMemo(
		() => [
			{
				value: '',
				label: sprintf(
					/* translators: %s: site timezone */
					__( 'Site timezone (%s)', 'open-events-calendar' ),
					config.siteTimezone || 'UTC'
				),
			},
			...( config.timezones || [] ).map( ( tz ) => ( {
				value: tz,
				label: tz.replace( /_/g, ' ' ),
			} ) ),
		],
		[ config ]
	);

	// Moving the start keeps the event's length, so the end never lands before it.
	const changeStart = ( value ) => {
		const changes = { _oe_start: value };
		const oldStart = parse( start );
		const oldEnd = parse( end );
		const newStart = parse( value );
		if ( newStart && oldStart && oldEnd && oldEnd >= oldStart ) {
			changes._oe_end = format(
				new Date( newStart.getTime() + ( oldEnd - oldStart ) )
			);
		} else if ( newStart && ( ! oldEnd || oldEnd < newStart ) ) {
			changes._oe_end = format(
				new Date( newStart.getTime() + 3600000 )
			);
		}
		setMeta( changes );
	};

	const endBeforeStart = start && end && parse( end ) < parse( start );

	if ( ! isEvent ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'This is an event', 'open-events-calendar' ) }
				help={ __(
					'Adds a date so this item shows on the calendar.',
					'open-events-calendar'
				) }
				checked={ false }
				onChange={ () => {
					const s = nextHour();
					setMeta( {
						_oe_start: format( s ),
						_oe_end: format( new Date( s.getTime() + 3600000 ) ),
					} );
				} }
			/>
		);
	}

	return (
		<Flex direction="column" gap={ 4 }>
			{ ! isDedicated && (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'This is an event', 'open-events-calendar' ) }
					checked
					onChange={ () => setMeta( { _oe_start: '', _oe_end: '' } ) }
				/>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'All day', 'open-events-calendar' ) }
				checked={ allDay }
				onChange={ ( value ) => setMeta( { _oe_all_day: value } ) }
			/>
			<DateField
				label={ __( 'Starts', 'open-events-calendar' ) }
				value={ start }
				onChange={ changeStart }
				allDay={ allDay }
				is12Hour={ config.timeFormat12h }
			/>
			<DateField
				label={ __( 'Ends', 'open-events-calendar' ) }
				value={ end }
				onChange={ ( value ) => setMeta( { _oe_end: value } ) }
				allDay={ allDay }
				is12Hour={ config.timeFormat12h }
			/>
			{ endBeforeStart && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The end is before the start. The event will be saved as ending when it starts.',
						'open-events-calendar'
					) }
				</Notice>
			) }
			{ ! allDay && (
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Timezone', 'open-events-calendar' ) }
					value={ meta._oe_timezone || '' }
					options={ timezoneOptions }
					onChange={ ( value ) =>
						setMeta( { _oe_timezone: value || '' } )
					}
				/>
			) }
			<VenueField
				value={ meta._oe_venue_id }
				onChange={ ( id ) => setMeta( { _oe_venue_id: id } ) }
			/>
			<OrganizersField
				value={ meta._oe_organizer_ids }
				onChange={ ( ids ) => setMeta( { _oe_organizer_ids: ids } ) }
			/>
			<Flex align="flex-start">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Cost', 'open-events-calendar' ) }
					help={ __( '0 shows “Free”.', 'open-events-calendar' ) }
					value={ meta._oe_cost || '' }
					onChange={ ( value ) => setMeta( { _oe_cost: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Symbol', 'open-events-calendar' ) }
					value={ meta._oe_currency_symbol || '' }
					onChange={ ( value ) =>
						setMeta( { _oe_currency_symbol: value } )
					}
					className="oe-editor-panel__symbol"
				/>
			</Flex>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="url"
				label={ __( 'Event website', 'open-events-calendar' ) }
				value={ meta._oe_url || '' }
				onChange={ ( value ) => setMeta( { _oe_url: value } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Featured event', 'open-events-calendar' ) }
				checked={ !! meta._oe_featured }
				onChange={ ( value ) => setMeta( { _oe_featured: value } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Hide from event listings',
					'open-events-calendar'
				) }
				help={ __(
					'Still reachable by its link.',
					'open-events-calendar'
				) }
				checked={ !! meta._oe_hide_from_listings }
				onChange={ ( value ) =>
					setMeta( { _oe_hide_from_listings: value } )
				}
			/>
			<Slot
				name="OpenEventsCalendarEventPanel"
				fillProps={ { meta, setMeta, postType } }
			/>
		</Flex>
	);
}
