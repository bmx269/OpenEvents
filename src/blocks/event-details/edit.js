import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const TOGGLES = [
	[ 'showVenue', __( 'Venue', 'open-events-calendar' ) ],
	[ 'showOrganizers', __( 'Organizers', 'open-events-calendar' ) ],
	[ 'showCost', __( 'Cost', 'open-events-calendar' ) ],
	[ 'showLinks', __( 'Calendar links', 'open-events-calendar' ) ],
];

export default function Edit( { attributes, setAttributes, context } ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Show', 'open-events-calendar' ) }>
					{ TOGGLES.map( ( [ key, label ] ) => (
						<ToggleControl
							key={ key }
							__nextHasNoMarginBottom
							label={ label }
							checked={ attributes[ key ] }
							onChange={ ( value ) =>
								setAttributes( { [ key ]: value } )
							}
						/>
					) ) }
					<p className="description">
						{ __(
							'The preview shows the last saved event details.',
							'open-events-calendar'
						) }
					</p>
				</PanelBody>
			</InspectorControls>
			<div inert="">
				<ServerSideRender
					block="open-events-calendar/event-details"
					attributes={ attributes }
					urlQueryArgs={
						context.postId ? { post_id: context.postId } : {}
					}
				/>
			</div>
		</div>
	);
}
