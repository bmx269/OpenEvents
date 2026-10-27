import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import CategoryPicker from '../shared/category-picker';

const VIEWS = [
	{ value: 'month', label: __( 'Month', 'open-events-calendar' ) },
	{ value: 'list', label: __( 'List', 'open-events-calendar' ) },
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		views,
		defaultView,
		categories,
		showLegend,
		perPage,
		headingLevel,
	} = attributes;

	const toggleView = ( view, checked ) => {
		const next = checked
			? [ ...views, view ]
			: views.filter( ( v ) => v !== view );
		if ( next.length ) {
			setAttributes( { views: next } );
		}
	};

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Views', 'open-events-calendar' ) }>
					{ VIEWS.map( ( view ) => (
						<CheckboxControl
							key={ view.value }
							__nextHasNoMarginBottom
							label={ view.label }
							checked={ views.includes( view.value ) }
							onChange={ ( checked ) =>
								toggleView( view.value, checked )
							}
						/>
					) ) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Default view', 'open-events-calendar' ) }
						value={ defaultView }
						options={ [
							{
								value: '',
								label: __(
									'Site default',
									'open-events-calendar'
								),
							},
							...VIEWS.filter( ( v ) =>
								views.includes( v.value )
							),
						] }
						onChange={ ( value ) =>
							setAttributes( { defaultView: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Filter', 'open-events-calendar' ) }>
					<CategoryPicker
						value={ categories }
						onChange={ ( value ) =>
							setAttributes( { categories: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Display', 'open-events-calendar' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show category colour legend',
							'open-events-calendar'
						) }
						checked={ showLegend }
						onChange={ ( value ) =>
							setAttributes( { showLegend: value } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Events per page (list view)',
							'open-events-calendar'
						) }
						help={ __(
							'0 uses the site setting.',
							'open-events-calendar'
						) }
						min={ 0 }
						max={ 50 }
						value={ perPage }
						onChange={ ( value ) =>
							setAttributes( { perPage: value ?? 0 } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Heading level', 'open-events-calendar' ) }
						value={ String( headingLevel ) }
						options={ [ 1, 2, 3, 4, 5, 6 ].map( ( level ) => ( {
							value: String( level ),
							label: `H${ level }`,
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( { headingLevel: Number( value ) } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div inert="">
				<ServerSideRender
					block="open-events-calendar/calendar"
					attributes={ attributes }
				/>
			</div>
		</div>
	);
}
