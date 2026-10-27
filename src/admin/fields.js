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

const config = window.openEventsAdmin || {};

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
				label={ __( 'Create an Events post type', 'openevents' ) }
				description={ __(
					'Adds an Events menu with its own archive page. Turning this off later hides existing events but does not delete them.',
					'openevents'
				) }
				checked={ !! settings.register_event_type }
				onCheckedChange={ ( checked ) =>
					update( { register_event_type: checked } )
				}
			/>
			{ settings.register_event_type && (
				<InputControl
					label={ __( 'Events URL slug', 'openevents' ) }
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
			{ ( settings.event_post_types || [] ).length > 0 && (
				<SwitchControl
					label={ __(
						'Use the event template for events in existing post types',
						'openevents'
					) }
					description={ __(
						'Dated posts in the types below display with the Single Event template instead of their usual one. Off by default so existing posts keep their look.',
						'openevents'
					) }
					checked={ !! settings.event_template }
					onCheckedChange={ ( checked ) =>
						update( { event_template: checked } )
					}
				/>
			) }
			{ config.postTypes?.length > 0 && (
				<Stack direction="column" gap="sm">
					<Text variant="heading-sm">
						{ __(
							'Add events to existing post types',
							'openevents'
						) }
					</Text>
					<Text variant="body-sm">
						{ __(
							'An Event panel appears in the editor for these types. Existing posts are not changed: an item only becomes an event when you give it a date.',
							'openevents'
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
									'openevents'
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
				? __( 'Use the Events archive', 'openevents' )
				: __( 'None', 'openevents' ),
		},
		...( config.pages || [] ).map( ( page ) => ( {
			value: String( page.id ),
			label: page.title,
		} ) ),
	];

	return (
		<Stack direction="column" gap="lg">
			<SelectControl
				label={ __( 'Calendar page', 'openevents' ) }
				description={ __(
					'A page containing the Calendar block. Used for “View all events” links and the iCal feed.',
					'openevents'
				) }
				items={ pageItems }
				value={ String( settings.calendar_page || 0 ) }
				onValueChange={ ( value ) =>
					update( { calendar_page: Number( value ) } )
				}
			/>
			<RadioGroupControl
				label={ __( 'When someone selects an event', 'openevents' ) }
				description={ __(
					'Calendar blocks can override this.',
					'openevents'
				) }
				items={ [
					{
						value: 'page',
						label: __( 'Go to the event page', 'openevents' ),
					},
					{
						value: 'popup',
						label: __( 'Show a summary popup', 'openevents' ),
						description: __(
							'Date, venue, and summary, with a link to the full event.',
							'openevents'
						),
					},
				] }
				value={ settings.click_action || 'page' }
				onValueChange={ ( value ) => update( { click_action: value } ) }
			/>
			<RadioGroupControl
				label={ __( 'Default view', 'openevents' ) }
				items={ [
					{
						value: 'month',
						label: __( 'Month grid', 'openevents' ),
					},
					{
						value: 'list',
						label: __( 'List', 'openevents' ),
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
				label={ __( 'Events per page', 'openevents' ) }
				value={ String( settings.per_page ?? 10 ) }
				onValueChange={ ( value ) =>
					update( { per_page: Number( value ) || 10 } )
				}
			/>
			<InputControl
				label={ __( 'Date format', 'openevents' ) }
				description={ __(
					'Leave blank to use the site date format.',
					'openevents'
				) }
				placeholder={ config.siteFormats?.date }
				value={ settings.date_format || '' }
				onValueChange={ ( value ) => update( { date_format: value } ) }
			/>
			<InputControl
				label={ __( 'Time format', 'openevents' ) }
				placeholder={ config.siteFormats?.time }
				value={ settings.time_format || '' }
				onValueChange={ ( value ) => update( { time_format: value } ) }
			/>
			<SwitchControl
				label={ __(
					'Show event details above the content automatically',
					'openevents'
				) }
				description={ __(
					'Skipped when the Event Details block is already on the page.',
					'openevents'
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
									__( 'Requires: %s.', 'openevents' ),
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
