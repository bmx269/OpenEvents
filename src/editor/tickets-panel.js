import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ToggleControl,
	ExternalLink,
	Flex,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { DateField } from './event-panel';
import AttendeeFields from './attendee-fields';
import { repeats } from './rsvp-panel';

const newType = () => ( {
	key: Math.random().toString( 36 ).slice( 2, 10 ),
	name: '',
	description: '',
	price: '0',
	capacity: 0,
	max_per_order: 10,
	sale_start: '',
	sale_end: '',
	waitlist: false,
	attendee_details: 'none',
	fields: [],
} );

/**
 * Ticket types for this event, saved with the post in _oe_tickets.
 *
 * @param {Object}   props
 * @param {Object}   props.meta    Post meta.
 * @param {Function} props.setMeta Meta updater.
 * @param {number}   props.postId  Event id.
 * @param {Object}   props.config  Editor config.
 */
export default function TicketsPanel( { meta, setMeta, postId, config } ) {
	const types = meta._oe_tickets || [];
	const setTypes = ( next ) => setMeta( { _oe_tickets: next } );
	const update = ( index, changes ) =>
		setTypes(
			types.map( ( type, i ) =>
				i === index ? { ...type, ...changes } : type
			)
		);

	return (
		<Flex direction="column" gap={ 4 }>
			{ types.length === 0 && (
				<p>
					{ __(
						'Add a ticket type to sell (or give away) tickets for this event.',
						'openevents'
					) }
				</p>
			) }
			{ types.map( ( type, index ) => (
				<fieldset key={ type.key } className="oe-editor-panel__ticket">
					<legend>
						{ type.name ||
							sprintf(
								/* translators: %d: ticket type number */
								__( 'Ticket type %d', 'openevents' ),
								index + 1
							) }
					</legend>
					<Flex direction="column" gap={ 3 }>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Name', 'openevents' ) }
							value={ type.name }
							onChange={ ( name ) => update( index, { name } ) }
						/>
						<Flex align="flex-start">
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ sprintf(
									/* translators: %s: currency code */
									__( 'Price (%s)', 'openevents' ),
									config.currency || 'USD'
								) }
								help={ __( '0 is free.', 'openevents' ) }
								inputMode="decimal"
								value={ type.price }
								onChange={ ( price ) =>
									update( index, { price } )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="number"
								min={ 0 }
								label={ __( 'Capacity', 'openevents' ) }
								help={
									repeats( meta )
										? __(
												'For each date. 0 is unlimited.',
												'openevents'
										  )
										: __( '0 is unlimited.', 'openevents' )
								}
								value={ String( type.capacity ) }
								onChange={ ( value ) =>
									update( index, {
										capacity: Math.max(
											0,
											parseInt( value, 10 ) || 0
										),
									} )
								}
							/>
						</Flex>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 1 }
							max={ 50 }
							label={ __( 'Maximum per order', 'openevents' ) }
							value={ String( type.max_per_order ) }
							onChange={ ( value ) =>
								update( index, {
									max_per_order: Math.min(
										50,
										Math.max(
											1,
											parseInt( value, 10 ) || 1
										)
									),
								} )
							}
						/>
						<DateField
							label={ __( 'Sales end', 'openevents' ) }
							value={ type.sale_end }
							emptyLabel={ __(
								'When the event starts',
								'openevents'
							) }
							onChange={ ( value ) =>
								update( index, { sale_end: value } )
							}
							onClear={ () => update( index, { sale_end: '' } ) }
							is12Hour={ config.timeFormat12h }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Waitlist when sold out',
								'openevents'
							) }
							help={ __(
								'Freed tickets are held for the next person, who gets a link to buy them.',
								'openevents'
							) }
							checked={ !! type.waitlist }
							onChange={ ( value ) =>
								update( index, { waitlist: value } )
							}
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Description', 'openevents' ) }
							rows={ 2 }
							value={ type.description }
							onChange={ ( description ) =>
								update( index, { description } )
							}
						/>
						<h3 className="oe-editor-panel__subhead">
							{ __(
								'Questions for each ticket holder',
								'openevents'
							) }
						</h3>
						<AttendeeFields
							fields={ type.fields || [] }
							details={ type.attendee_details || 'none' }
							onChange={ ( changes ) => update( index, changes ) }
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								setTypes(
									types.filter( ( _, i ) => i !== index )
								)
							}
						>
							{ sprintf(
								/* translators: %s: ticket type name */
								__( 'Remove %s', 'openevents' ),
								type.name || __( 'ticket type', 'openevents' )
							) }
						</Button>
					</Flex>
				</fieldset>
			) ) }
			<Button
				variant="secondary"
				onClick={ () => setTypes( [ ...types, newType() ] ) }
			>
				{ __( 'Add ticket type', 'openevents' ) }
			</Button>
			{ types.length > 0 && postId && (
				<ExternalLink
					href={ `${ config.ordersUrl }&event=${ postId }` }
				>
					{ __( 'Orders for this event', 'openevents' ) }
				</ExternalLink>
			) }
		</Flex>
	);
}
