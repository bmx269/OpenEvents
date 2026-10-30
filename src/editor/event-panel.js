import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ComboboxControl,
	DateTimePicker,
	Dropdown,
	FormTokenField,
	Notice,
	SelectControl,
	Slot,
	TextControl,
	ToggleControl,
	Flex,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { date as formatDate, dateI18n, getSettings } from '@wordpress/date';
import { useMemo, useState } from '@wordpress/element';

/** Meta stores "Y-m-d H:i:s" wall-clock time; the picker uses ISO "Y-m-dTH:i:s". */
/**
 * @param {string} value Stored meta value.
 * @return {string|null} Picker value.
 */
const toPicker = ( value ) => ( value ? value.replace( ' ', 'T' ) : null );
const fromPicker = ( value ) =>
	value ? value.slice( 0, 19 ).replace( 'T', ' ' ) : '';

/*
 * Stored times are wall-clock times in the event's timezone, with no offset.
 * Treat them as UTC internally so neither the browser's nor the site's timezone
 * shifts them (and DST in the browser cannot skew durations).
 */
const parse = ( value ) =>
	value ? new Date( `${ value.replace( ' ', 'T' ) }Z` ) : null;
const pad = ( n ) => String( n ).padStart( 2, '0' );
const format = ( date ) =>
	`${ date.getUTCFullYear() }-${ pad( date.getUTCMonth() + 1 ) }-${ pad(
		date.getUTCDate()
	) } ${ pad( date.getUTCHours() ) }:${ pad( date.getUTCMinutes() ) }:00`;
/**
 * Localised display of a stored wall-clock value, without timezone conversion.
 *
 * @param {string} fmt   PHP-style date format.
 * @param {string} value Stored "Y-m-d H:i:s" value.
 * @return {string} Formatted date.
 */
export const display = ( fmt, value ) =>
	dateI18n( fmt, `${ value.replace( ' ', 'T' ) }Z`, true );

/** The next full hour in the site's timezone, as a wall-clock Date (see parse). */
function nextHour() {
	const now = parse( formatDate( 'Y-m-d H:i:s' ) );
	now.setUTCMinutes( 0, 0, 0 );
	now.setUTCHours( now.getUTCHours() + 1 );
	return now;
}

export function DateField( {
	label,
	value,
	onChange,
	allDay,
	is12Hour,
	emptyLabel,
	onClear,
} ) {
	const settings = getSettings();
	const labelText = value
		? display(
				allDay
					? settings.formats.date
					: `${ settings.formats.date } ${ settings.formats.time }`,
				value
		  )
		: emptyLabel || __( 'Not set', 'openevents' );

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
							__( '%1$s: %2$s', 'openevents' ),
							label,
							labelText
						) }
					>
						{ labelText }
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
						{ onClear && value && (
							<Button variant="link" onClick={ onClear }>
								{ __( 'Clear', 'openevents' ) }
							</Button>
						) }
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

/**
 * Picks a linked post (venue, series), or creates one from the typed name.
 *
 * @param {Object}   props
 * @param {string}   props.postType    Linked post type.
 * @param {string}   props.label       Field label.
 * @param {Function} props.createLabel Button text for a new name.
 * @param {number}   props.value       Selected id.
 * @param {Function} props.onChange    Receives the id (0 for none).
 */
function LinkedPostField( { postType, label, createLabel, value, onChange } ) {
	const [ options, create ] = useLinkedPosts( postType );
	const [ typed, setTyped ] = useState( '' );
	const exists = options.some(
		( o ) => o.label.toLowerCase() === typed.trim().toLowerCase()
	);

	return (
		<>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				value={ value ? String( value ) : null }
				options={ options }
				onChange={ ( id ) => onChange( id ? Number( id ) : 0 ) }
				onFilterValueChange={ setTyped }
			/>
			{ typed.trim() && ! exists && (
				<Button
					variant="link"
					onClick={ async () => {
						const post = await create( typed.trim() );
						if ( post?.id ) {
							onChange( post.id );
							setTyped( '' );
						}
					} }
				>
					{ createLabel( typed.trim() ) }
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
			label={ __( 'Organizers', 'openevents' ) }
			value={ ( value || [] )
				.map( ( id ) => byId.get( id ) )
				.filter( Boolean ) }
			suggestions={ options.map( ( o ) => o.label ) }
			onChange={ handleChange }
			__experimentalExpandOnFocus
			__experimentalShowHowTo={ false }
			help={ __(
				'Type a new name to create an organizer.',
				'openevents'
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
					__( 'Site timezone (%s)', 'openevents' ),
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
				label={ __( 'This is an event', 'openevents' ) }
				help={ __(
					'Adds a date so this item shows on the calendar.',
					'openevents'
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
					label={ __( 'This is an event', 'openevents' ) }
					checked
					onChange={ () => setMeta( { _oe_start: '', _oe_end: '' } ) }
				/>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'All day', 'openevents' ) }
				checked={ allDay }
				onChange={ ( value ) => setMeta( { _oe_all_day: value } ) }
			/>
			<DateField
				label={ __( 'Starts', 'openevents' ) }
				value={ start }
				onChange={ changeStart }
				allDay={ allDay }
				is12Hour={ config.timeFormat12h }
			/>
			<DateField
				label={ __( 'Ends', 'openevents' ) }
				value={ end }
				onChange={ ( value ) => setMeta( { _oe_end: value } ) }
				allDay={ allDay }
				is12Hour={ config.timeFormat12h }
			/>
			{ endBeforeStart && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The end is before the start. The event will be saved as ending when it starts.',
						'openevents'
					) }
				</Notice>
			) }
			{ ! allDay && (
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Timezone', 'openevents' ) }
					value={ meta._oe_timezone || '' }
					options={ timezoneOptions }
					onChange={ ( value ) =>
						setMeta( { _oe_timezone: value || '' } )
					}
				/>
			) }
			<LinkedPostField
				postType="oe_venue"
				label={ __( 'Venue', 'openevents' ) }
				createLabel={ ( name ) =>
					sprintf(
						/* translators: %s: venue name */
						__( 'Add “%s” as a new venue', 'openevents' ),
						name
					)
				}
				value={ meta._oe_venue_id }
				onChange={ ( id ) => setMeta( { _oe_venue_id: id } ) }
			/>
			{ ( config.modules || [] ).includes( 'series' ) && (
				<LinkedPostField
					postType="oe_series"
					label={ __( 'Series', 'openevents' ) }
					createLabel={ ( name ) =>
						sprintf(
							/* translators: %s: series name */
							__( 'Add “%s” as a new series', 'openevents' ),
							name
						)
					}
					value={ meta._oe_series_id }
					onChange={ ( id ) => setMeta( { _oe_series_id: id } ) }
				/>
			) }
			<OrganizersField
				value={ meta._oe_organizer_ids }
				onChange={ ( ids ) => setMeta( { _oe_organizer_ids: ids } ) }
			/>
			<Flex align="flex-start">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Cost', 'openevents' ) }
					help={ __( '0 shows “Free”.', 'openevents' ) }
					value={ meta._oe_cost || '' }
					onChange={ ( value ) => setMeta( { _oe_cost: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Symbol', 'openevents' ) }
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
				label={ __( 'Event website', 'openevents' ) }
				value={ meta._oe_url || '' }
				onChange={ ( value ) => setMeta( { _oe_url: value } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Status', 'openevents' ) }
				help={ __(
					'Cancelled and postponed events stay listed with a label, and stop taking RSVPs and ticket orders.',
					'openevents'
				) }
				value={ meta._oe_status || 'scheduled' }
				options={ [
					{
						value: 'scheduled',
						label: __( 'Scheduled', 'openevents' ),
					},
					{
						value: 'cancelled',
						label: __( 'Cancelled', 'openevents' ),
					},
					{
						value: 'postponed',
						label: __( 'Postponed', 'openevents' ),
					},
				] }
				onChange={ ( value ) => setMeta( { _oe_status: value } ) }
			/>
			{ ( meta._oe_status || 'scheduled' ) !== 'scheduled' && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Note for visitors', 'openevents' ) }
					help={ __(
						'Optional, shown with the status. For example, the reason or what happens next.',
						'openevents'
					) }
					maxLength={ 500 }
					value={ meta._oe_status_reason || '' }
					onChange={ ( value ) =>
						setMeta( { _oe_status_reason: value } )
					}
				/>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Featured event', 'openevents' ) }
				checked={ !! meta._oe_featured }
				onChange={ ( value ) => setMeta( { _oe_featured: value } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Hide from event listings', 'openevents' ) }
				help={ __( 'Still reachable by its link.', 'openevents' ) }
				checked={ !! meta._oe_hide_from_listings }
				onChange={ ( value ) =>
					setMeta( { _oe_hide_from_listings: value } )
				}
			/>
			<Slot
				name="OpenEventsEventPanel"
				fillProps={ { meta, setMeta, postType } }
			/>
		</Flex>
	);
}
