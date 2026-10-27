import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import CategoryPicker from '../shared/category-picker';

export default function Edit( { attributes, setAttributes } ) {
	const { count, categories } = attributes;

	const toggle = ( key, label ) => (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ label }
			checked={ attributes[ key ] }
			onChange={ ( value ) => setAttributes( { [ key ]: value } ) }
		/>
	);

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'openevents' ) }>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Number of events', 'openevents' ) }
						min={ 1 }
						max={ 20 }
						value={ count }
						onChange={ ( value ) =>
							setAttributes( { count: value ?? 5 } )
						}
					/>
					<CategoryPicker
						value={ categories }
						onChange={ ( value ) =>
							setAttributes( { categories: value } )
						}
					/>
					{ toggle(
						'showImage',
						__( 'Show featured image', 'openevents' )
					) }
					{ toggle(
						'showExcerpt',
						__( 'Show excerpt', 'openevents' )
					) }
					{ toggle( 'showVenue', __( 'Show venue', 'openevents' ) ) }
					{ toggle(
						'showViewAll',
						__( 'Show “View all events” link', 'openevents' )
					) }
				</PanelBody>
			</InspectorControls>
			<div inert="">
				<ServerSideRender
					block="openevents/upcoming-events"
					attributes={ attributes }
				/>
			</div>
		</div>
	);
}
