import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Page } from '@wordpress/admin-ui';
import {
	Button,
	ButtonLink,
	Card,
	Link,
	Notice,
	RadioGroupControl,
	Spinner,
	Stack,
	Text,
} from '@wordpress/ui';
import { useState } from '@wordpress/element';
import useSettings from './use-settings';
import { DisplayFields, EventContentFields, ModuleFields } from './fields';

const config = window.openEventsCalendarAdmin || {};

const STEPS = [
	{ id: 'content', label: __( 'Event content', 'open-events-calendar' ) },
	{ id: 'calendar', label: __( 'Calendar', 'open-events-calendar' ) },
	{ id: 'modules', label: __( 'Features', 'open-events-calendar' ) },
	{ id: 'done', label: __( 'Done', 'open-events-calendar' ) },
];

function Steps( { current } ) {
	return (
		<ol className="oec-setup__steps">
			{ STEPS.map( ( step, index ) => (
				<li
					key={ step.id }
					aria-current={ index === current ? 'step' : undefined }
					className={ index < current ? 'is-complete' : undefined }
				>
					<Text variant="body-sm">
						{ index + 1 }. { step.label }
					</Text>
				</li>
			) ) }
		</ol>
	);
}

export default function SetupScreen() {
	const {
		settings,
		update,
		modules,
		setModules,
		save,
		completeSetup,
		saving,
		error,
	} = useSettings();
	const [ step, setStep ] = useState( 0 );
	const [ pageMode, setPageMode ] = useState( null );
	const [ calendarUrl, setCalendarUrl ] = useState( config.calendarUrl );

	if ( ! settings ) {
		return error ? (
			<Notice.Root intent="error">
				<Notice.Description>{ error.message }</Notice.Description>
			</Notice.Root>
		) : (
			<Spinner />
		);
	}

	let mode = pageMode;
	if ( ! mode ) {
		if ( settings.calendar_page ) {
			mode = 'existing';
		} else {
			mode = settings.register_event_type ? 'archive' : 'create';
		}
	}

	const next = async () => {
		const id = STEPS[ step ].id;
		if ( id === 'calendar' && mode === 'create' ) {
			const page = await apiFetch( {
				path: '/wp/v2/pages',
				method: 'POST',
				data: {
					title: __( 'Calendar', 'open-events-calendar' ),
					status: 'publish',
					content:
						'<!-- wp:open-events-calendar/calendar {"showLegend":true} /-->',
				},
			} );
			update( { calendar_page: page.id } );
			setCalendarUrl( page.link );
			setPageMode( 'existing' );
			// Settings state updates asynchronously; save with the new id explicitly.
			await save( {
				open_events_calendar_settings: {
					...settings,
					calendar_page: page.id,
				},
			} );
		} else if ( id === 'modules' ) {
			await completeSetup();
		} else {
			await save();
		}
		setStep( step + 1 );
	};

	const skip = async () => {
		await completeSetup();
		window.location.href = config.settingsUrl;
	};

	const current = STEPS[ step ].id;

	return (
		<Page
			title={ __(
				'Set up Open Events Calendar',
				'open-events-calendar'
			) }
			hasPadding
		>
			<Stack
				direction="column"
				gap="lg"
				className="oec-admin__body oec-setup"
			>
				<Steps current={ step } />
				{ error && (
					<Notice.Root intent="error">
						<Notice.Description>
							{ error.message }
						</Notice.Description>
					</Notice.Root>
				) }
				<Card.Root>
					<Card.Header>
						<Card.Title>
							{ current === 'content' &&
								__(
									'Where should events live?',
									'open-events-calendar'
								) }
							{ current === 'calendar' &&
								__(
									'Where should the calendar appear?',
									'open-events-calendar'
								) }
							{ current === 'modules' &&
								__(
									'Which features do you want?',
									'open-events-calendar'
								) }
							{ current === 'done' &&
								__( 'You’re all set', 'open-events-calendar' ) }
						</Card.Title>
					</Card.Header>
					<Card.Content>
						{ current === 'content' && (
							<Stack direction="column" gap="lg">
								<Text>
									{ __(
										'Create a dedicated Events post type, add event dates to post types you already use, or both.',
										'open-events-calendar'
									) }
								</Text>
								<EventContentFields
									settings={ settings }
									update={ update }
								/>
								{ config.tecCount > 0 && (
									<Notice.Root intent="info">
										<Notice.Description>
											{ sprintf(
												/* translators: %d: number of events */
												_n(
													'We found %d event from The Events Calendar. It is left untouched; an importer is planned for an upcoming release.',
													'We found %d events from The Events Calendar. They are left untouched; an importer is planned for an upcoming release.',
													config.tecCount,
													'open-events-calendar'
												),
												config.tecCount
											) }
										</Notice.Description>
									</Notice.Root>
								) }
							</Stack>
						) }
						{ current === 'calendar' && (
							<Stack direction="column" gap="lg">
								<RadioGroupControl
									label={ __(
										'Calendar location',
										'open-events-calendar'
									) }
									items={ [
										...( settings.register_event_type
											? [
													{
														value: 'archive',
														label: sprintf(
															/* translators: %s: URL */
															__(
																'The Events archive at %s',
																'open-events-calendar'
															),
															`${ config.homeUrl }${ settings.events_slug }/`
														),
													},
											  ]
											: [] ),
										{
											value: 'create',
											label: __(
												'Create a “Calendar” page with the calendar block',
												'open-events-calendar'
											),
										},
										{
											value: 'existing',
											label: __(
												'An existing page',
												'open-events-calendar'
											),
										},
									] }
									value={ mode }
									onValueChange={ ( value ) => {
										setPageMode( value );
										if ( value !== 'existing' ) {
											update( { calendar_page: 0 } );
										}
									} }
								/>
								{ mode === 'existing' ? (
									<DisplayFields
										settings={ settings }
										update={ update }
									/>
								) : (
									<RadioGroupControl
										label={ __(
											'Default view',
											'open-events-calendar'
										) }
										items={ [
											{
												value: 'month',
												label: __(
													'Month grid',
													'open-events-calendar'
												),
											},
											{
												value: 'list',
												label: __(
													'List',
													'open-events-calendar'
												),
											},
										] }
										value={
											settings.default_view || 'month'
										}
										onValueChange={ ( value ) =>
											update( { default_view: value } )
										}
									/>
								) }
							</Stack>
						) }
						{ current === 'modules' && (
							<Stack direction="column" gap="lg">
								<ModuleFields
									enabled={ modules }
									onChange={ setModules }
								/>
								<Text variant="body-sm">
									{ __(
										'Recurring events, RSVP, and ticket sales are in development and will appear here as modules.',
										'open-events-calendar'
									) }
								</Text>
							</Stack>
						) }
						{ current === 'done' && (
							<Stack direction="column" gap="md">
								{ settings.register_event_type && (
									<Link
										href={ `${ config.adminUrl }post-new.php?post_type=oe_event` }
									>
										{ __(
											'Add your first event',
											'open-events-calendar'
										) }
									</Link>
								) }
								{ config.postTypes
									.filter( ( type ) =>
										(
											settings.event_post_types || []
										).includes( type.name )
									)
									.map( ( type ) => (
										<Link
											key={ type.name }
											href={ `${ config.adminUrl }edit.php?post_type=${ type.name }` }
										>
											{ sprintf(
												/* translators: %s: post type name */
												__(
													'Open any item in %s and set a date in the Event panel',
													'open-events-calendar'
												),
												type.label
											) }
										</Link>
									) ) }
								<Link
									href={ `${ config.adminUrl }post-new.php?post_type=oe_venue` }
								>
									{ __(
										'Add a venue',
										'open-events-calendar'
									) }
								</Link>
								<Link href={ calendarUrl }>
									{ __(
										'View your calendar',
										'open-events-calendar'
									) }
								</Link>
							</Stack>
						) }
					</Card.Content>
				</Card.Root>
				<Stack
					direction="row"
					gap="sm"
					justify="space-between"
					align="center"
				>
					{ current !== 'done' ? (
						<>
							<Button
								variant="minimal"
								onClick={ skip }
								disabled={ saving }
							>
								{ __(
									'Skip setup and use the defaults',
									'open-events-calendar'
								) }
							</Button>
							<Stack direction="row" gap="sm">
								{ step > 0 && (
									<Button
										variant="outline"
										onClick={ () => setStep( step - 1 ) }
										disabled={ saving }
									>
										{ __( 'Back', 'open-events-calendar' ) }
									</Button>
								) }
								<Button
									onClick={ next }
									loading={ saving }
									loadingAnnouncement={ __(
										'Saving',
										'open-events-calendar'
									) }
								>
									{ __( 'Continue', 'open-events-calendar' ) }
								</Button>
							</Stack>
						</>
					) : (
						<ButtonLink
							href={ config.settingsUrl }
							variant="outline"
						>
							{ __(
								'Review all settings',
								'open-events-calendar'
							) }
						</ButtonLink>
					) }
				</Stack>
			</Stack>
		</Page>
	);
}
