import { __, _n, sprintf } from '@wordpress/i18n';
import {
	ExternalLink,
	Flex,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { DateField } from './event-panel';
import AttendeeFields from './attendee-fields';

const DEFAULTS = {
	enabled: false,
	capacity: 0,
	max_per_response: 2,
	deadline: '',
	allow_not_going: true,
	show_count: true,
	waitlist: false,
	attendee_details: 'none',
	fields: [],
};

/**
 * Whether the event repeats (each date then has its own places).
 *
 * @param {Object} meta Post meta.
 * @return {boolean} Repeats.
 */
export const repeats = ( meta ) =>
	!! ( meta._oe_recurrence?.rrule || meta._oe_recurrence?.rdates?.length );

/**
 * Live "12 going · 8 spots left" summary for published events. A repeating
 * event's dates each have their own, so it links to the attendee list
 * (which filters by date) instead.
 *
 * @param {Object}  props
 * @param {number}  props.postId  Event id.
 * @param {Object}  props.config  Editor config.
 * @param {boolean} props.repeats Whether the event repeats.
 */
function Summary( { postId, config, repeats: perDate } ) {
	const [ summary, setSummary ] = useState( null );
	const status = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'status' ),
		[]
	);
	const isSaving = useSelect(
		( select ) => select( editorStore ).isSavingPost(),
		[]
	);

	useEffect( () => {
		if ( status !== 'publish' || isSaving || perDate ) {
			return;
		}
		apiFetch( { path: `/openevents/v1/events/${ postId }/rsvp` } )
			.then( setSummary )
			.catch( () => setSummary( null ) );
	}, [ postId, status, isSaving, perDate ] );

	const attendees = (
		<ExternalLink href={ `${ config.attendeesUrl }&event=${ postId }` }>
			{ __( 'Attendees', 'openevents' ) }
		</ExternalLink>
	);
	if ( perDate ) {
		return status === 'publish' ? (
			<Flex justify="space-between" wrap>
				<span>{ __( 'Counted per date.', 'openevents' ) }</span>
				{ attendees }
			</Flex>
		) : null;
	}
	if ( ! summary ) {
		return null;
	}
	return (
		<Flex justify="space-between" wrap>
			<span>
				{ summary.going !== null &&
					sprintf(
						/* translators: %d: people going */
						_n(
							'%d going',
							'%d going',
							summary.going,
							'openevents'
						),
						summary.going
					) }
				{ summary.remaining !== null &&
					` · ${ sprintf(
						/* translators: %d: spots left */
						_n(
							'%d spot left',
							'%d spots left',
							summary.remaining,
							'openevents'
						),
						summary.remaining
					) }` }
			</span>
			{ attendees }
		</Flex>
	);
}

export default function RsvpPanel( { meta, setMeta, postId, config } ) {
	const rsvp = { ...DEFAULTS, ...( meta._oe_rsvp || {} ) };
	const update = ( changes ) =>
		setMeta( { _oe_rsvp: { ...rsvp, ...changes } } );

	return (
		<Flex direction="column" gap={ 4 }>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Take RSVPs', 'openevents' ) }
				help={ __(
					'Adds an RSVP form to the event page. Free for attendees.',
					'openevents'
				) }
				checked={ rsvp.enabled }
				onChange={ ( enabled ) => update( { enabled } ) }
			/>
			{ rsvp.enabled && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Capacity', 'openevents' ) }
						help={
							repeats( meta )
								? __(
										'For each date. 0 means unlimited.',
										'openevents'
								  )
								: __( '0 means unlimited.', 'openevents' )
						}
						value={ String( rsvp.capacity ) }
						onChange={ ( value ) =>
							update( {
								capacity: Math.max(
									0,
									parseInt( value, 10 ) || 0
								),
							} )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'People per response', 'openevents' ) }
						help={ __( 'Lets people bring guests.', 'openevents' ) }
						value={ String( rsvp.max_per_response ) }
						options={ [ 1, 2, 3, 4, 5, 6, 8, 10 ].map( ( n ) => ( {
							value: String( n ),
							label: String( n ),
						} ) ) }
						onChange={ ( value ) =>
							update( { max_per_response: Number( value ) } )
						}
					/>
					<DateField
						label={ __( 'RSVP deadline', 'openevents' ) }
						value={ rsvp.deadline }
						emptyLabel={ __(
							'When the event starts',
							'openevents'
						) }
						onChange={ ( deadline ) => update( { deadline } ) }
						onClear={ () => update( { deadline: '' } ) }
						is12Hour={ config.timeFormat12h }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Offer “Not going”', 'openevents' ) }
						checked={ rsvp.allow_not_going }
						onChange={ ( value ) =>
							update( { allow_not_going: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show how many are going', 'openevents' ) }
						checked={ rsvp.show_count }
						onChange={ ( value ) =>
							update( { show_count: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Waitlist when full', 'openevents' ) }
						help={ __(
							'People join a queue and are confirmed automatically when a spot opens up.',
							'openevents'
						) }
						checked={ !! rsvp.waitlist }
						onChange={ ( value ) => update( { waitlist: value } ) }
					/>
					<h3 className="oe-editor-panel__subhead">
						{ __( 'Questions for each person', 'openevents' ) }
					</h3>
					<AttendeeFields
						fields={ rsvp.fields }
						details={ rsvp.attendee_details }
						forWhom="guest"
						onChange={ update }
					/>
					<Summary
						postId={ postId }
						config={ config }
						repeats={ repeats( meta ) }
					/>
				</>
			) }
		</Flex>
	);
}
