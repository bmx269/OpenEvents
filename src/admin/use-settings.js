import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

const SETTINGS = 'openevents_settings';
const MODULES = 'openevents_modules';
const SETUP = 'openevents_setup_complete';

/**
 * Loads and saves plugin options through the core /wp/v2/settings endpoint.
 *
 * @return {Object} State and actions.
 */
export default function useSettings() {
	const [ settings, setSettings ] = useState( null );
	const [ modules, setModules ] = useState( [] );
	const [ status, setStatus ] = useState( { saving: false, error: null } );

	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( data ) => {
				setSettings( data[ SETTINGS ] || {} );
				setModules( data[ MODULES ] || [] );
			} )
			.catch( ( error ) => setStatus( { saving: false, error } ) );
	}, [] );

	const update = useCallback(
		( changes ) =>
			setSettings( ( current ) => ( { ...current, ...changes } ) ),
		[]
	);

	const save = useCallback(
		async ( extra = {} ) => {
			setStatus( { saving: true, error: null } );
			try {
				const data = await apiFetch( {
					path: '/wp/v2/settings',
					method: 'POST',
					data: {
						[ SETTINGS ]: settings,
						[ MODULES ]: [ ...new Set( modules ) ],
						...extra,
					},
				} );
				// The server sanitises (e.g. adds module dependencies); show what was stored.
				setSettings( data[ SETTINGS ] );
				setModules( data[ MODULES ] );
				setStatus( { saving: false, error: null } );
				return true;
			} catch ( error ) {
				setStatus( { saving: false, error } );
				return false;
			}
		},
		[ settings, modules ]
	);

	const completeSetup = useCallback(
		() => save( { [ SETUP ]: true } ),
		[ save ]
	);

	return {
		settings,
		update,
		modules,
		setModules,
		save,
		completeSetup,
		...status,
	};
}
