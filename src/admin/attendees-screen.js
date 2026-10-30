/**
 * Events → Attendees: server-paged DataViews list with search, filters,
 * check-in, and CSV export.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataViews } from '@wordpress/dataviews';
import { Page } from '@wordpress/admin-ui';
import { ButtonLink, Notice } from '@wordpress/ui';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

const STATUS = [
	{ value: 'going', label: __( 'Going', 'openevents' ) },
	{ value: 'not_going', label: __( 'Not going', 'openevents' ) },
	{ value: 'cancelled', label: __( 'Cancelled', 'openevents' ) },
];

export default function AttendeesScreen( { root } ) {
	const events = useMemo(
		() => JSON.parse( root.dataset.events || '[]' ),
		[ root ]
	);
	const initialEvent = Number( root.dataset.event ) || 0;

	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 50,
		search: '',
		sort: { field: 'created_utc', direction: 'desc' },
		fields: [
			'email',
			'event',
			'date',
			'status',
			'checked_in',
			'answers',
			'created_utc',
		],
		titleField: 'name',
		filters: initialEvent
			? [ { field: 'event', operator: 'is', value: initialEvent } ]
			: [],
	} );
	const [ data, setData ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ refresh, setRefresh ] = useState( 0 );
	const [ dates, setDates ] = useState( [] );

	const query = useMemo( () => {
		const filter = ( field ) =>
			view.filters?.find(
				( f ) => f.field === field && f.value !== undefined
			)?.value;
		const checkedIn = filter( 'checked_in' );
		const event = filter( 'event' ) || undefined;
		return {
			page: view.page,
			per_page: view.perPage,
			search: view.search || undefined,
			orderby: view.sort?.field,
			order: view.sort?.direction,
			event,
			// A date (sequence) only means something within one event.
			sequence: event ? filter( 'date' ) : undefined,
			status: filter( 'status' ) || undefined,
			checked_in:
				checkedIn === undefined ? undefined : checkedIn === 'yes',
		};
	}, [ view ] );

	// Dates with attendees, for the Date filter, once an event is chosen.
	useEffect( () => {
		if ( ! query.event ) {
			setDates( [] );
			return;
		}
		apiFetch( {
			path: addQueryArgs( '/openevents/v1/attendees/dates', {
				event: query.event,
			} ),
		} )
			.then( setDates )
			.catch( () => setDates( [] ) );
	}, [ query.event, refresh ] );

	useEffect( () => {
		setIsLoading( true );
		apiFetch( {
			path: addQueryArgs( '/openevents/v1/attendees', query ),
			parse: false,
		} )
			.then( async ( response ) => {
				setTotal( Number( response.headers.get( 'X-WP-Total' ) ) || 0 );
				setData( await response.json() );
				setError( null );
			} )
			.catch( ( e ) =>
				setError(
					e.message || __( 'Could not load attendees.', 'openevents' )
				)
			)
			.finally( () => setIsLoading( false ) );
	}, [ query, refresh ] );

	const update = useCallback( async ( items, changes ) => {
		try {
			await Promise.all(
				items.map( ( item ) =>
					apiFetch( {
						path: `/openevents/v1/attendees/${ item.id }`,
						method: 'POST',
						data: changes,
					} )
				)
			);
		} catch ( e ) {
			setError( e.message );
		}
		setRefresh( ( n ) => n + 1 );
	}, [] );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Name', 'openevents' ),
				enableHiding: false,
			},
			{ id: 'email', label: __( 'Email', 'openevents' ) },
			{
				id: 'event',
				label: __( 'Event', 'openevents' ),
				getValue: ( { item } ) => item.event_id,
				render: ( { item } ) => item.event_title,
				elements: events,
				enableSorting: false,
				filterBy: { operators: [ 'is' ], isPrimary: true },
			},
			{
				id: 'date',
				label: __( 'Date', 'openevents' ),
				render: ( { item } ) => item.date || '—',
				elements: dates,
				enableSorting: false,
				filterBy: dates.length > 1 ? { operators: [ 'is' ] } : false,
			},
			{
				id: 'status',
				label: __( 'Status', 'openevents' ),
				elements: STATUS,
				filterBy: { operators: [ 'is' ], isPrimary: true },
			},
			{
				id: 'checked_in',
				label: __( 'Checked in', 'openevents' ),
				getValue: ( { item } ) => ( item.checked_in ? 'yes' : 'no' ),
				render: ( { item } ) =>
					item.checked_in ? __( 'Yes', 'openevents' ) : '—',
				elements: [
					{ value: 'yes', label: __( 'Yes', 'openevents' ) },
					{ value: 'no', label: __( 'No', 'openevents' ) },
				],
				enableSorting: false,
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'code',
				label: __( 'Check-in code', 'openevents' ),
				enableSorting: false,
			},
			{
				id: 'answers',
				label: __( 'Details', 'openevents' ),
				getValue: ( { item } ) =>
					( item.answers || [] )
						.map( ( a ) => `${ a.label }: ${ a.value }` )
						.join( '; ' ),
				render: ( { item } ) =>
					item.answers?.length ? (
						<ul className="oe-answers">
							{ item.answers.map( ( a ) => (
								<li key={ a.key }>
									<span className="oe-answers__label">
										{ a.label }
									</span>{ ' ' }
									{ a.value }
								</li>
							) ) }
						</ul>
					) : (
						'—'
					),
				enableSorting: false,
			},
			{
				id: 'created_utc',
				label: __( 'Responded', 'openevents' ),
				render: ( { item } ) =>
					new Date(
						`${ item.created_utc.replace( ' ', 'T' ) }Z`
					).toLocaleString(),
			},
		],
		[ events, dates ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'check-in',
				label: __( 'Check in', 'openevents' ),
				isPrimary: true,
				supportsBulk: true,
				isEligible: ( item ) =>
					item.status === 'going' && ! item.checked_in,
				callback: ( items ) => update( items, { checked_in: true } ),
			},
			{
				id: 'undo-check-in',
				label: __( 'Undo check-in', 'openevents' ),
				supportsBulk: true,
				isEligible: ( item ) => item.checked_in,
				callback: ( items ) => update( items, { checked_in: false } ),
			},
			{
				id: 'pass',
				label: __( 'Open pass', 'openevents' ),
				isEligible: ( item ) => item.status === 'going',
				callback: ( [ item ] ) =>
					window.open( item.pass_url, '_blank', 'noopener' ),
			},
			{
				id: 'cancel',
				label: __( 'Cancel RSVP', 'openevents' ),
				supportsBulk: true,
				isDestructive: true,
				isEligible: ( item ) => item.status === 'going',
				callback: ( items ) => update( items, { status: 'cancelled' } ),
			},
		],
		[ update ]
	);

	const csvUrl = addQueryArgs( root.dataset.csv, {
		event: query.event,
		sequence: query.sequence,
		status: query.status,
	} );

	return (
		<Page
			title={ __( 'Attendees', 'openevents' ) }
			subTitle={ sprintf(
				/* translators: %d: number of attendees */
				_n( '%d attendee', '%d attendees', total, 'openevents' ),
				total
			) }
			actions={
				<ButtonLink variant="outline" href={ csvUrl }>
					{ __( 'Download CSV', 'openevents' ) }
				</ButtonLink>
			}
		>
			{ error && (
				<Notice.Root intent="error">
					<Notice.Description>{ error }</Notice.Description>
				</Notice.Root>
			) }
			<DataViews
				view={ view }
				onChangeView={ setView }
				fields={ fields }
				data={ data }
				actions={ actions }
				isLoading={ isLoading }
				getItemId={ ( item ) => String( item.id ) }
				paginationInfo={ {
					totalItems: total,
					totalPages: Math.max(
						1,
						Math.ceil( total / view.perPage )
					),
				} }
				defaultLayouts={ { table: {} } }
				searchLabel={ __(
					'Search name, email, or check-in code',
					'openevents'
				) }
			/>
		</Page>
	);
}
