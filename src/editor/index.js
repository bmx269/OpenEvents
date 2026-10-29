/**
 * Editor sidebar panels for events, RSVP, venues, and organizers.
 *
 * Add-ons can add controls to these panels with SlotFill:
 *
 *   import { Fill } from '@wordpress/components';
 *   registerPlugin( 'my-addon', { render: () => (
 *     <Fill name="OpenEventsEventPanel">
 *       { ( { meta, setMeta, postType } ) => <MyControl /> }
 *     </Fill>
 *   ) } );
 *
 * Slots: OpenEventsEventPanel, OpenEventsVenuePanel, OpenEventsOrganizerPanel.
 * Register your meta with the openevents/meta_fields PHP filter so it saves
 * over REST.
 */
import { registerPlugin } from '@wordpress/plugins';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import EventPanel from './event-panel';
import { VenuePanel, OrganizerPanel } from './place-panels';
import RsvpPanel from './rsvp-panel';
import TicketsPanel from './tickets-panel';
import RepeatPanel from './repeat-panel';
import './editor.scss';

const config = window.openEvents || {};

function OpenEventsSidebar() {
	const { postType, postId } = useSelect(
		( select ) => ( {
			postType: select( editorStore ).getCurrentPostType(),
			postId: select( editorStore ).getCurrentPostId(),
		} ),
		[]
	);
	const [ meta, setMetaRaw ] = useEntityProp( 'postType', postType, 'meta' );
	const setMeta = ( changes ) => setMetaRaw( { ...meta, ...changes } );

	if ( ! meta ) {
		return null;
	}

	if ( ( config.eventPostTypes || [] ).includes( postType ) ) {
		const isDedicated = postType === config.dedicatedType;
		const isEvent = isDedicated || !! meta._oe_start;
		const rsvpEnabled = ( config.modules || [] ).includes( 'rsvp' );
		const ticketsEnabled = ( config.modules || [] ).includes( 'tickets' );
		const repeatEnabled = ( config.modules || [] ).includes( 'recurrence' );
		return (
			<>
				<PluginDocumentSettingPanel
					name="openevents-event"
					title={ __( 'Event', 'openevents' ) }
					className="oe-editor-panel"
				>
					<EventPanel
						meta={ meta }
						setMeta={ setMeta }
						postType={ postType }
						isDedicated={ isDedicated }
						config={ config }
					/>
				</PluginDocumentSettingPanel>
				{ repeatEnabled && isEvent && (
					<PluginDocumentSettingPanel
						name="openevents-repeat"
						title={ __( 'Repeat', 'openevents' ) }
						className="oe-editor-panel"
					>
						<RepeatPanel
							meta={ meta }
							setMeta={ setMeta }
							postId={ postId }
							config={ config }
						/>
					</PluginDocumentSettingPanel>
				) }
				{ rsvpEnabled && isEvent && (
					<PluginDocumentSettingPanel
						name="openevents-rsvp"
						title={ __( 'RSVP', 'openevents' ) }
						className="oe-editor-panel"
					>
						<RsvpPanel
							meta={ meta }
							setMeta={ setMeta }
							postId={ postId }
							config={ config }
						/>
					</PluginDocumentSettingPanel>
				) }
				{ ticketsEnabled && isEvent && (
					<PluginDocumentSettingPanel
						name="openevents-tickets"
						title={ __( 'Tickets', 'openevents' ) }
						className="oe-editor-panel"
					>
						<TicketsPanel
							meta={ meta }
							setMeta={ setMeta }
							postId={ postId }
							config={ config }
						/>
					</PluginDocumentSettingPanel>
				) }
			</>
		);
	}

	if (
		postType === 'oe_series' &&
		( config.modules || [] ).includes( 'tickets' )
	) {
		return (
			<PluginDocumentSettingPanel
				name="openevents-series-passes"
				title={ __( 'Series passes', 'openevents' ) }
				className="oe-editor-panel"
			>
				<p className="oe-editor-panel__hint">
					{ __(
						'Passes are sold on the series page and cover every event in the series.',
						'openevents'
					) }
				</p>
				<TicketsPanel
					meta={ meta }
					setMeta={ setMeta }
					postId={ postId }
					config={ config }
				/>
			</PluginDocumentSettingPanel>
		);
	}

	if ( postType === 'oe_venue' ) {
		return (
			<PluginDocumentSettingPanel
				name="openevents-venue"
				title={ __( 'Venue details', 'openevents' ) }
				className="oe-editor-panel"
			>
				<VenuePanel meta={ meta } setMeta={ setMeta } />
			</PluginDocumentSettingPanel>
		);
	}

	if ( postType === 'oe_organizer' ) {
		return (
			<PluginDocumentSettingPanel
				name="openevents-organizer"
				title={ __( 'Organizer details', 'openevents' ) }
				className="oe-editor-panel"
			>
				<OrganizerPanel meta={ meta } setMeta={ setMeta } />
			</PluginDocumentSettingPanel>
		);
	}

	return null;
}

registerPlugin( 'openevents', { render: OpenEventsSidebar } );
