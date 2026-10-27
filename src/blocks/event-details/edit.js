import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const TOGGLES = [
	[ 'showVenue', __( 'Venue', 'openevents' ) ],
	[ 'showOrganizers', __( 'Organizers', 'openevents' ) ],
	[ 'showCost', __( 'Cost', 'openevents' ) ],
	[ 'showLinks', __( 'Calendar links', 'openevents' ) ],
];

export default function Edit( { attributes, setAttributes, context } ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Show', 'openevents' ) }>
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
							'openevents'
						) }
					</p>
				</PanelBody>
			</InspectorControls>
			<div inert="">
				<ServerSideRender
					block="openevents/event-details"
					attributes={ attributes }
					urlQueryArgs={
						context.postId ? { post_id: context.postId } : {}
					}
				/>
			</div>
		</div>
	);
}
