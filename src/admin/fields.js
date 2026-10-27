/**
 * Field groups shared by the settings screen and the setup wizard, so both
 * stay in step.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	InputControl,
	InputLayout,
	RadioGroupControl,
	SelectControl,
	Stack,
	SwitchControl,
	Text,
} from '@wordpress/ui';

const config = window.openEventsCalendarAdmin || {};

/**
 * Where events live: the dedicated post type and/or existing post types.
 *
 * @param {Object}   props
 * @param {Object}   props.settings Current settings.
 * @param {Function} props.update   Receives partial settings.
 */
export function EventContentFields( { settings, update } ) {
	const selected = settings.event_post_types || [];
	const toggleType = ( name, checked ) =>
		update( {
			event_post_types: checked
				? [ ...selected, name ]
				: selected.filter( ( t ) => t !== name ),
		} );

	return (
		<Stack direction="column" gap="lg">
			<SwitchControl
				label={ __(
					'Create an Events post type',
					'open-events-calendar'
				) }
				description={ __(
					'Adds an Events menu with its own archive page. Turning this off later hides existing events but does not delete them.',
					'open-events-calendar'
				) }
				checked={ !! settings.register_event_type }
				onCheckedChange={ ( checked ) =>
					update( { register_event_type: checked } )
				}
			/>
			{ settings.register_event_type && (
				<InputControl
					label={ __( 'Events URL slug', 'open-events-calendar' ) }
					prefix={
						<InputLayout.Slot>
							<Text variant="body-md">{ config.homeUrl }</Text>
						</InputLayout.Slot>
					}
					value={ settings.events_slug || '' }
					onValueChange={ ( value ) =>
						update( { events_slug: value } )
					}
				/>
			) }
			{ config.postTypes?.length > 0 && (
				<Stack direction="column" gap="sm">
					<Text variant="heading-sm">
						{ __(
							'Add events to existing post types',
							'open-events-calendar'
						) }
					</Text>
					<Text variant="body-sm">
						{ __(
							'An Event panel appears in the editor for these types. Existing posts are not changed: an item only becomes an event when you give it a date.',
							'open-events-calendar'
						) }
					</Text>
					{ config.postTypes.map( ( type ) => (
						<CheckboxControl
							key={ type.name }
							label={ type.label }
							description={ sprintf(
								/* translators: %d: number of published posts */
								_n(
									'%d published',
									'%d published',
									type.count,
									'open-events-calendar'
								),
								type.count
							) }
							checked={ selected.includes( type.name ) }
							onCheckedChange={ ( checked ) =>
								toggleType( type.name, checked )
							}
						/>
					) ) }
				</Stack>
			) }
		</Stack>
	);
}

/**
 * Calendar location and display.
 *
 * @param {Object}   props
 * @param {Object}   props.settings Current settings.
 * @param {Function} props.update   Receives partial settings.
 */
export function DisplayFields( { settings, update } ) {
	const pageItems = [
		{
			value: '0',
			label: settings.register_event_type
				? __( 'Use the Events archive', 'open-events-calendar' )
				: __( 'None', 'open-events-calendar' ),
		},
		...( config.pages || [] ).map( ( page ) => ( {
			value: String( page.id ),
			label: page.title,
		} ) ),
	];

	return (
		<Stack direction="column" gap="lg">
			<SelectControl
				label={ __( 'Calendar page', 'open-events-calendar' ) }
				description={ __(
					'A page containing the Calendar block. Used for “View all events” links and the iCal feed.',
					'open-events-calendar'
				) }
				items={ pageItems }
				value={ String( settings.calendar_page || 0 ) }
				onValueChange={ ( value ) =>
					update( { calendar_page: Number( value ) } )
				}
			/>
			<RadioGroupControl
				label={ __( 'Default view', 'open-events-calendar' ) }
				items={ [
					{
						value: 'month',
						label: __( 'Month grid', 'open-events-calendar' ),
					},
					{
						value: 'list',
						label: __( 'List', 'open-events-calendar' ),
					},
				] }
				value={ settings.default_view || 'month' }
				onValueChange={ ( value ) => update( { default_view: value } ) }
			/>
		</Stack>
	);
}

/**
 * Formats and listing options (settings screen only).
 *
 * @param {Object}   props
 * @param {Object}   props.settings Current settings.
 * @param {Function} props.update   Receives partial settings.
 */
export function FormatFields( { settings, update } ) {
	return (
		<Stack direction="column" gap="lg">
			<InputControl
				type="number"
				min={ 1 }
				max={ 100 }
				label={ __( 'Events per page', 'open-events-calendar' ) }
				value={ String( settings.per_page ?? 10 ) }
				onValueChange={ ( value ) =>
					update( { per_page: Number( value ) || 10 } )
				}
			/>
			<InputControl
				label={ __( 'Date format', 'open-events-calendar' ) }
				description={ __(
					'Leave blank to use the site date format.',
					'open-events-calendar'
				) }
				placeholder={ config.siteFormats?.date }
				value={ settings.date_format || '' }
				onValueChange={ ( value ) => update( { date_format: value } ) }
			/>
			<InputControl
				label={ __( 'Time format', 'open-events-calendar' ) }
				placeholder={ config.siteFormats?.time }
				value={ settings.time_format || '' }
				onValueChange={ ( value ) => update( { time_format: value } ) }
			/>
			<SwitchControl
				label={ __(
					'Show event details above the content automatically',
					'open-events-calendar'
				) }
				description={ __(
					'Skipped when the Event Details block is already on the page.',
					'open-events-calendar'
				) }
				checked={ !! settings.append_details }
				onCheckedChange={ ( checked ) =>
					update( { append_details: checked } )
				}
			/>
		</Stack>
	);
}

/**
 * Module toggles.
 *
 * @param {Object}   props
 * @param {string[]} props.enabled  Enabled module ids.
 * @param {Function} props.onChange Receives module ids.
 */
export function ModuleFields( { enabled, onChange } ) {
	const modules = config.modules || [];
	const labelFor = ( id ) =>
		modules.find( ( m ) => m.id === id )?.label || id;

	return (
		<Stack direction="column" gap="lg">
			{ modules.map( ( module ) => (
				<SwitchControl
					key={ module.id }
					label={ module.label }
					description={
						module.requires.length
							? `${ module.description } ${ sprintf(
									/* translators: %s: comma-separated module names */
									__(
										'Requires: %s.',
										'open-events-calendar'
									),
									module.requires.map( labelFor ).join( ', ' )
							  ) }`
							: module.description
					}
					checked={ enabled.includes( module.id ) }
					onCheckedChange={ ( checked ) =>
						onChange(
							checked
								? [ ...enabled, module.id, ...module.requires ]
								: enabled.filter( ( id ) => id !== module.id )
						)
					}
				/>
			) ) }
		</Stack>
	);
}
