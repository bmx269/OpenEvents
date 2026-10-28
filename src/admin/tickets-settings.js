/**
 * Settings → Tickets: currency and payment gateways. Gateway settings use
 * their own REST route so secrets are never sent back to the browser.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Badge,
	Button,
	Card,
	InputControl,
	Notice,
	SelectControl,
	Spinner,
	Stack,
	SwitchControl,
	Text,
} from '@wordpress/ui';
import { useEffect, useState } from '@wordpress/element';

const CURRENCIES = [
	'USD',
	'CAD',
	'EUR',
	'GBP',
	'AUD',
	'NZD',
	'JPY',
	'CHF',
	'SEK',
	'NOK',
	'DKK',
	'MXN',
	'BRL',
	'INR',
	'SGD',
	'HKD',
	'ZAR',
];

function GatewayCard( { gateway, values, onChange } ) {
	return (
		<Card.Root>
			<Card.Header>
				<Stack direction="row" gap="sm" align="center">
					<Card.Title>{ gateway.label }</Card.Title>
					<Badge intent={ gateway.available ? 'stable' : 'draft' }>
						{ gateway.available
							? __( 'Ready', 'openevents' )
							: __( 'Not set up', 'openevents' ) }
					</Badge>
				</Stack>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="md">
					{ Object.entries( gateway.fields ).map(
						( [ key, field ] ) => {
							const value = values[ key ] ?? field.value;
							const set = ( v ) => onChange( key, v );
							const description = field.constant
								? __( 'Set in wp-config.php.', 'openevents' )
								: field.help;
							if ( field.type === 'toggle' ) {
								return (
									<SwitchControl
										key={ key }
										label={ field.label }
										description={ description }
										checked={ !! value }
										disabled={ field.constant }
										onCheckedChange={ set }
									/>
								);
							}
							if ( field.type === 'select' ) {
								return (
									<SelectControl
										key={ key }
										label={ field.label }
										description={ description }
										items={ Object.entries(
											field.options
										).map( ( [ v, label ] ) => ( {
											value: v,
											label,
										} ) ) }
										value={
											value ||
											Object.keys( field.options )[ 0 ]
										}
										disabled={ field.constant }
										onValueChange={ set }
									/>
								);
							}
							return (
								<InputControl
									key={ key }
									label={ field.label }
									description={ description }
									type={
										field.type === 'secret'
											? 'password'
											: 'text'
									}
									autoComplete="off"
									value={ value || '' }
									disabled={ field.constant }
									onValueChange={ set }
								/>
							);
						}
					) }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}

export default function TicketsSettings( { settings, update } ) {
	const [ gateways, setGateways ] = useState( null );
	const [ edits, setEdits ] = useState( {} );
	const [ status, setStatus ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/openevents/v1/gateways' } )
			.then( ( data ) => setGateways( data.gateways ) )
			.catch( ( e ) =>
				setStatus( { intent: 'error', text: e.message } )
			);
	}, [] );

	const change = ( id, key, value ) =>
		setEdits( ( current ) => ( {
			...current,
			[ id ]: { ...( current[ id ] || {} ), [ key ]: value },
		} ) );

	const save = async () => {
		setSaving( true );
		try {
			const data = await apiFetch( {
				path: '/openevents/v1/gateways',
				method: 'POST',
				data: { gateways: edits },
			} );
			setGateways( data.gateways );
			setEdits( {} );
			setStatus( {
				intent: 'success',
				text: __( 'Payment settings saved.', 'openevents' ),
			} );
		} catch ( e ) {
			setStatus( { intent: 'error', text: e.message } );
		}
		setSaving( false );
	};

	return (
		<Stack direction="column" gap="lg">
			<SelectControl
				label={ __( 'Currency', 'openevents' ) }
				description={ __(
					'Used for all ticket prices. Save changes to apply.',
					'openevents'
				) }
				items={ CURRENCIES.map( ( c ) => ( { value: c, label: c } ) ) }
				value={ settings.currency || 'USD' }
				onValueChange={ ( currency ) => update( { currency } ) }
			/>
			<Text variant="heading-sm">
				{ __( 'Payment methods', 'openevents' ) }
			</Text>
			<Text variant="body-sm">
				{ __(
					'Buyers pay on Stripe or PayPal; card details never reach this site. OpenEvents adds no fees.',
					'openevents'
				) }
			</Text>
			{ status && (
				<Notice.Root intent={ status.intent }>
					<Notice.Description>{ status.text }</Notice.Description>
				</Notice.Root>
			) }
			{ ! gateways ? (
				<Spinner />
			) : (
				gateways.map( ( gateway ) => (
					<GatewayCard
						key={ gateway.id }
						gateway={ gateway }
						values={ edits[ gateway.id ] || {} }
						onChange={ ( key, value ) =>
							change( gateway.id, key, value )
						}
					/>
				) )
			) }
			<Stack direction="row" justify="flex-start">
				<Button
					onClick={ save }
					loading={ saving }
					disabled={ ! Object.keys( edits ).length }
				>
					{ __( 'Save payment settings', 'openevents' ) }
				</Button>
			</Stack>
		</Stack>
	);
}
