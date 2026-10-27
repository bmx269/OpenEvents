/**
 * Editor sidebar panels for events, venues, and organizers.
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
 * Slots: OpenEventsEventPanel, OpenEventsVenuePanel,
 * OpenEventsOrganizerPanel. Register your meta with the
 * openevents/meta_fields PHP filter so it saves over REST.
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
import './editor.scss';

const config = window.openEvents || {};

function OpenEventsSidebar() {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const [ meta, setMetaRaw ] = useEntityProp( 'postType', postType, 'meta' );
	const setMeta = ( changes ) => setMetaRaw( { ...meta, ...changes } );

	if ( ! meta ) {
		return null;
	}

	if ( ( config.eventPostTypes || [] ).includes( postType ) ) {
		return (
			<PluginDocumentSettingPanel
				name="openevents-event"
				title={ __( 'Event', 'openevents' ) }
				className="oe-editor-panel"
			>
				<EventPanel
					meta={ meta }
					setMeta={ setMeta }
					postType={ postType }
					isDedicated={ postType === config.dedicatedType }
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
