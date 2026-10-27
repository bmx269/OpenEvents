import { __ } from '@wordpress/i18n';
import { Page } from '@wordpress/admin-ui';
import {
	Button,
	ButtonLink,
	Card,
	Notice,
	Spinner,
	Stack,
	SwitchControl,
	Tabs,
	Text,
} from '@wordpress/ui';
import { useState } from '@wordpress/element';
import useSettings from './use-settings';
import {
	DisplayFields,
	EventContentFields,
	FormatFields,
	ModuleFields,
} from './fields';

const config = window.openEventsCalendarAdmin || {};

function Section( { title, children } ) {
	return (
		<Card.Root>
			<Card.Header>
				<Card.Title>{ title }</Card.Title>
			</Card.Header>
			<Card.Content>{ children }</Card.Content>
		</Card.Root>
	);
}

export default function SettingsScreen() {
	const state = useSettings();
	const { settings, update, modules, setModules, save, saving, error } =
		state;
	const [ saved, setSaved ] = useState( false );

	if ( ! settings ) {
		return error ? (
			<Notice.Root intent="error">
				<Notice.Description>{ error.message }</Notice.Description>
			</Notice.Root>
		) : (
			<Spinner />
		);
	}

	const onSave = async () => {
		setSaved( false );
		setSaved( await save() );
	};

	return (
		<Page
			title={ __( 'Event Settings', 'open-events-calendar' ) }
			actions={
				<Stack direction="row" gap="sm">
					<ButtonLink
						variant="outline"
						href={ `${ config.adminUrl }admin.php?page=open-events-calendar-setup` }
					>
						{ __( 'Run setup wizard', 'open-events-calendar' ) }
					</ButtonLink>
					<Button
						onClick={ onSave }
						loading={ saving }
						loadingAnnouncement={ __(
							'Saving',
							'open-events-calendar'
						) }
					>
						{ __( 'Save changes', 'open-events-calendar' ) }
					</Button>
				</Stack>
			}
			hasPadding
		>
			<Stack direction="column" gap="lg" className="oec-admin__body">
				{ saved && (
					<Notice.Root intent="success">
						<Notice.Description>
							{ __( 'Settings saved.', 'open-events-calendar' ) }
						</Notice.Description>
					</Notice.Root>
				) }
				{ error && (
					<Notice.Root intent="error">
						<Notice.Description>
							{ error.message }
						</Notice.Description>
					</Notice.Root>
				) }
				<Tabs.Root defaultValue="content">
					<Tabs.List>
						<Tabs.Tab value="content">
							{ __( 'Event content', 'open-events-calendar' ) }
						</Tabs.Tab>
						<Tabs.Tab value="display">
							{ __( 'Display', 'open-events-calendar' ) }
						</Tabs.Tab>
						<Tabs.Tab value="modules">
							{ __( 'Modules', 'open-events-calendar' ) }
						</Tabs.Tab>
						<Tabs.Tab value="advanced">
							{ __( 'Advanced', 'open-events-calendar' ) }
						</Tabs.Tab>
					</Tabs.List>
					<Tabs.Panel value="content">
						<Section
							title={ __(
								'Where events live',
								'open-events-calendar'
							) }
						>
							<EventContentFields
								settings={ settings }
								update={ update }
							/>
						</Section>
					</Tabs.Panel>
					<Tabs.Panel value="display">
						<Stack direction="column" gap="lg">
							<Section
								title={ __(
									'Calendar',
									'open-events-calendar'
								) }
							>
								<DisplayFields
									settings={ settings }
									update={ update }
								/>
							</Section>
							<Section
								title={ __(
									'Formats',
									'open-events-calendar'
								) }
							>
								<FormatFields
									settings={ settings }
									update={ update }
								/>
							</Section>
						</Stack>
					</Tabs.Panel>
					<Tabs.Panel value="modules">
						<Section
							title={ __( 'Modules', 'open-events-calendar' ) }
						>
							<Stack direction="column" gap="md">
								<Text variant="body-sm">
									{ __(
										'Turn features on or off. Modules that others depend on are enabled automatically.',
										'open-events-calendar'
									) }
								</Text>
								<ModuleFields
									enabled={ modules }
									onChange={ setModules }
								/>
							</Stack>
						</Section>
					</Tabs.Panel>
					<Tabs.Panel value="advanced">
						<Section
							title={ __( 'Uninstall', 'open-events-calendar' ) }
						>
							<SwitchControl
								label={ __(
									'Delete all event data when the plugin is deleted',
									'open-events-calendar'
								) }
								description={ __(
									'Removes events, venues, organizers, categories, and settings. On existing post types only the event fields are removed; the posts are kept.',
									'open-events-calendar'
								) }
								checked={ !! settings.remove_data_on_uninstall }
								onCheckedChange={ ( checked ) =>
									update( {
										remove_data_on_uninstall: checked,
									} )
								}
							/>
						</Section>
					</Tabs.Panel>
				</Tabs.Root>
			</Stack>
		</Page>
	);
}
