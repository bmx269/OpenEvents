/**
 * Events → Waitlist: who's waiting, in order, with offer deadlines.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataViews } from '@wordpress/dataviews';
import { Page } from '@wordpress/admin-ui';
import { Notice } from '@wordpress/ui';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

const STATUS = [
	{ value: 'waiting', label: __( 'Waiting', 'openevents' ) },
	{ value: 'offered', label: __( 'Tickets held', 'openevents' ) },
	{ value: 'claimed', label: __( 'Got a spot', 'openevents' ) },
	{ value: 'expired', label: __( 'Hold expired', 'openevents' ) },
	{ value: 'left', label: __( 'Left', 'openevents' ) },
];

const when = ( utc ) =>
	utc ? new Date( `${ utc.replace( ' ', 'T' ) }Z` ).toLocaleString() : '';

export default function WaitlistScreen( { root } ) {
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
		fields: [
			'email',
			'event',
			'type',
			'quantity',
			'status',
			'answers',
			'created_utc',
		],
		titleField: 'name',
		filters: [
			{ field: 'status', operator: 'is', value: 'waiting' },
			...( initialEvent
				? [ { field: 'event', operator: 'is', value: initialEvent } ]
				: [] ),
		],
	} );
	const [ data, setData ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ refresh, setRefresh ] = useState( 0 );

	useEffect( () => {
		const filter = ( field ) =>
			view.filters?.find( ( f ) => f.field === field )?.value;
		setIsLoading( true );
		apiFetch( {
			path: addQueryArgs( '/openevents/v1/waitlist', {
				page: view.page,
				per_page: view.perPage,
				search: view.search || undefined,
				event: filter( 'event' ) || undefined,
				status: filter( 'status' ) || undefined,
			} ),
			parse: false,
		} )
			.then( async ( response ) => {
				setTotal( Number( response.headers.get( 'X-WP-Total' ) ) || 0 );
				setData( await response.json() );
			} )
			.catch( ( e ) => setNotice( { intent: 'error', text: e.message } ) )
			.finally( () => setIsLoading( false ) );
	}, [ view, refresh ] );

	const remove = useCallback( async ( items ) => {
		try {
			for ( const item of items ) {
				await apiFetch( {
					path: `/openevents/v1/waitlist/${ item.id }`,
					method: 'POST',
					data: { action: 'remove' },
				} );
			}
			setNotice( {
				intent: 'success',
				text: sprintf(
					/* translators: %d: number of people */
					_n(
						'%d person removed.',
						'%d people removed.',
						items.length,
						'openevents'
					),
					items.length
				),
			} );
		} catch ( e ) {
			setNotice( { intent: 'error', text: e.message } );
		}
		setRefresh( ( n ) => n + 1 );
	}, [] );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Name', 'openevents' ),
				enableHiding: false,
				enableSorting: false,
			},
			{
				id: 'email',
				label: __( 'Email', 'openevents' ),
				enableSorting: false,
			},
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
				id: 'type',
				label: __( 'For', 'openevents' ),
				render: ( { item } ) => item.type_name,
				enableSorting: false,
			},
			{
				id: 'quantity',
				label: __( 'People', 'openevents' ),
				enableSorting: false,
			},
			{
				id: 'status',
				label: __( 'Status', 'openevents' ),
				elements: STATUS,
				render: ( { item } ) => {
					const label =
						STATUS.find( ( s ) => s.value === item.status )
							?.label || item.status;
					return item.status === 'offered'
						? sprintf(
								/* translators: 1: status, 2: deadline */
								__( '%1$s until %2$s', 'openevents' ),
								label,
								when( item.offer_expires_utc )
						  )
						: label;
				},
				enableSorting: false,
				filterBy: { operators: [ 'is' ], isPrimary: true },
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
				label: __( 'Joined', 'openevents' ),
				render: ( { item } ) => when( item.created_utc ),
				enableSorting: false,
			},
		],
		[ events ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'remove',
				label: __( 'Remove from waitlist', 'openevents' ),
				isDestructive: true,
				supportsBulk: true,
				isEligible: ( item ) =>
					[ 'waiting', 'offered' ].includes( item.status ),
				callback: remove,
			},
		],
		[ remove ]
	);

	return (
		<Page
			title={ __( 'Waitlist', 'openevents' ) }
			subTitle={ __(
				'First come, first served. Freed RSVP spots are filled automatically; freed tickets are held for the next person.',
				'openevents'
			) }
		>
			{ notice && (
				<Notice.Root intent={ notice.intent }>
					<Notice.Description>{ notice.text }</Notice.Description>
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
				searchLabel={ __( 'Search name or email', 'openevents' ) }
			/>
		</Page>
	);
}
