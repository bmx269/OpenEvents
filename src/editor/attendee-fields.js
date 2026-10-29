import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Flex,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

const TYPES = () => [
	{ value: 'text', label: __( 'Short text', 'openevents' ) },
	{ value: 'textarea', label: __( 'Long text', 'openevents' ) },
	{ value: 'email', label: __( 'Email', 'openevents' ) },
	{ value: 'tel', label: __( 'Phone', 'openevents' ) },
	{ value: 'url', label: __( 'Website', 'openevents' ) },
	{ value: 'number', label: __( 'Number', 'openevents' ) },
	{ value: 'date', label: __( 'Date', 'openevents' ) },
	{ value: 'select', label: __( 'Dropdown', 'openevents' ) },
	{ value: 'radio', label: __( 'One choice', 'openevents' ) },
	{
		value: 'checkbox',
		label: __( 'Checkboxes (or one “I agree” box)', 'openevents' ),
	},
];
const CHOICES = [ 'select', 'radio', 'checkbox' ];

/** Saved question sets, shared by every event (loaded once per editor). */
let setsRequest = null;
function useFieldSets() {
	const [ sets, setSets ] = useState( [] );
	useEffect( () => {
		setsRequest =
			setsRequest || apiFetch( { path: '/openevents/v1/field-sets' } );
		setsRequest.then( setSets ).catch( () => setSets( [] ) );
	}, [] );
	const save = async ( name, fields ) => {
		const saved = await apiFetch( {
			path: '/openevents/v1/field-sets',
			method: 'POST',
			data: { name, fields },
		} );
		setsRequest = Promise.resolve( saved );
		setSets( saved );
	};
	return [ sets, save ];
}

/**
 * One question.
 *
 * @param {Object}   props
 * @param {Object}   props.field    Definition.
 * @param {number}   props.index    Position.
 * @param {number}   props.count    Number of questions.
 * @param {Function} props.onChange Updates the definition.
 * @param {Function} props.onMove   Moves it by -1 or +1.
 * @param {Function} props.onRemove Removes it.
 */
function Question( { field, index, count, onChange, onMove, onRemove } ) {
	const name =
		field.label ||
		sprintf(
			/* translators: %d: question number */
			__( 'Question %d', 'openevents' ),
			index + 1
		);
	return (
		<fieldset className="oe-editor-panel__question">
			<legend>{ name }</legend>
			<Flex direction="column" gap={ 3 }>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Type', 'openevents' ) }
					value={ field.type }
					options={ TYPES() }
					onChange={ ( type ) => onChange( { type } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Question', 'openevents' ) }
					rows={ 2 }
					value={ field.label }
					onChange={ ( label ) => onChange( { label } ) }
				/>
				{ CHOICES.includes( field.type ) && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Options, one per line', 'openevents' ) }
						help={
							field.type === 'checkbox'
								? __(
										'Leave empty for a single “I agree” checkbox.',
										'openevents'
								  )
								: undefined
						}
						rows={ 3 }
						value={ ( field.options || [] ).join( '\n' ) }
						onChange={ ( value ) =>
							onChange( { options: value.split( '\n' ) } )
						}
					/>
				) }
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Help text', 'openevents' ) }
					value={ field.help || '' }
					onChange={ ( help ) => onChange( { help } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Required', 'openevents' ) }
					checked={ !! field.required }
					onChange={ ( required ) => onChange( { required } ) }
				/>
				<Flex justify="flex-start" wrap>
					<Button
						size="compact"
						variant="tertiary"
						disabled={ index === 0 }
						accessibleWhenDisabled
						onClick={ () => onMove( -1 ) }
						label={ sprintf(
							/* translators: %s: question */
							__( 'Move “%s” up', 'openevents' ),
							name
						) }
					>
						{ __( 'Up', 'openevents' ) }
					</Button>
					<Button
						size="compact"
						variant="tertiary"
						disabled={ index === count - 1 }
						accessibleWhenDisabled
						onClick={ () => onMove( 1 ) }
						label={ sprintf(
							/* translators: %s: question */
							__( 'Move “%s” down', 'openevents' ),
							name
						) }
					>
						{ __( 'Down', 'openevents' ) }
					</Button>
					<Button
						size="compact"
						variant="tertiary"
						isDestructive
						onClick={ onRemove }
						label={ sprintf(
							/* translators: %s: question */
							__( 'Remove “%s”', 'openevents' ),
							name
						) }
					>
						{ __( 'Remove', 'openevents' ) }
					</Button>
				</Flex>
			</Flex>
		</fieldset>
	);
}

/**
 * Questions asked of each person on an RSVP or ticket, plus whether each
 * person gives their own name and email. Saved with the RSVP settings or the
 * ticket type; answers are stored per attendee.
 *
 * @param {Object}   props
 * @param {Array}    props.fields   Definitions.
 * @param {string}   props.details  "none", "optional", or "required".
 * @param {Function} props.onChange Receives { fields } or { attendee_details }.
 * @param {string}   props.forWhom  "guest" (RSVP) or "ticket".
 */
export default function AttendeeFields( {
	fields = [],
	details = 'none',
	onChange,
	forWhom = 'ticket',
} ) {
	const [ sets, saveSet ] = useFieldSets();
	const [ setName, setSetName ] = useState( '' );
	const setFields = ( next ) => onChange( { fields: next } );
	const update = ( index, changes ) =>
		setFields(
			fields.map( ( f, i ) => ( i === index ? { ...f, ...changes } : f ) )
		);
	const move = ( index, by ) => {
		const next = [ ...fields ];
		const [ item ] = next.splice( index, 1 );
		next.splice( index + by, 0, item );
		setFields( next );
	};

	return (
		<Flex direction="column" gap={ 3 } className="oe-editor-panel__fields">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={
					forWhom === 'guest'
						? __( 'Each guest’s name and email', 'openevents' )
						: __(
								'Each ticket holder’s name and email',
								'openevents'
						  )
				}
				value={ details }
				options={ [
					{ value: 'none', label: __( 'Don’t ask', 'openevents' ) },
					{
						value: 'optional',
						label: __( 'Optional', 'openevents' ),
					},
					{
						value: 'required',
						label: __( 'Required', 'openevents' ),
					},
				] }
				onChange={ ( value ) =>
					onChange( { attendee_details: value } )
				}
			/>
			{ fields.map( ( field, index ) => (
				<Question
					// Positions are stable enough here: reordering re-renders every row.
					key={ index }
					field={ field }
					index={ index }
					count={ fields.length }
					onChange={ ( changes ) => update( index, changes ) }
					onMove={ ( by ) => move( index, by ) }
					onRemove={ () =>
						setFields( fields.filter( ( _, i ) => i !== index ) )
					}
				/>
			) ) }
			<Flex justify="flex-start" wrap>
				<Button
					variant="secondary"
					size="compact"
					onClick={ () =>
						setFields( [
							...fields,
							{
								type: 'text',
								label: '',
								required: false,
								help: '',
								options: [],
							},
						] )
					}
				>
					{ __( 'Add question', 'openevents' ) }
				</Button>
			</Flex>
			{ sets.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Use a saved question set', 'openevents' ) }
					help={ __( 'Replaces the questions above.', 'openevents' ) }
					value=""
					options={ [
						{ value: '', label: __( 'Choose…', 'openevents' ) },
						...sets.map( ( set, i ) => ( {
							value: String( i ),
							label: set.name,
						} ) ),
					] }
					onChange={ ( value ) =>
						value !== '' &&
						setFields(
							sets[ Number( value ) ].fields.map( ( f ) => ( {
								...f,
							} ) )
						)
					}
				/>
			) }
			{ fields.length > 0 && (
				<Flex align="flex-end">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Save these questions as a set',
							'openevents'
						) }
						value={ setName }
						onChange={ setSetName }
					/>
					<Button
						variant="secondary"
						size="compact"
						disabled={ ! setName.trim() }
						accessibleWhenDisabled
						onClick={ () =>
							saveSet( setName.trim(), fields ).then( () =>
								setSetName( '' )
							)
						}
					>
						{ __( 'Save set', 'openevents' ) }
					</Button>
				</Flex>
			) }
		</Flex>
	);
}
