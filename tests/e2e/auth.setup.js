const { test: setup, expect } = require( '@playwright/test' );
const { rest } = require( './helpers' );

setup( 'log in as admin', async ( { page } ) => {
	await page.goto( '/wp-admin/' );
	// Playground may already have logged us in automatically.
	if ( await page.locator( '#user_login' ).isVisible() ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
	}
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	// Turn the editor's welcome guide off for good (it's saved with a delay
	// when set from the editor, so tests could still see it).
	const saved = await rest( page, '/wp/v2/users/me?context=edit', {
		method: 'POST',
		data: {
			meta: {
				persisted_preferences: {
					'core/edit-post': { welcomeGuide: false },
					core: { welcomeGuide: false },
					_modified: new Date().toISOString(),
				},
			},
		},
	} );
	expect( saved.status ).toBe( 200 );
	await page
		.context()
		.storageState( { path: 'test-results/.auth/admin.json' } );
} );
