const { test: setup, expect } = require( '@playwright/test' );

setup( 'log in as admin', async ( { page } ) => {
	await page.goto( '/wp-admin/' );
	// Playground may already have logged us in automatically.
	if ( await page.locator( '#user_login' ).isVisible() ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
	}
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	await page
		.context()
		.storageState( { path: 'test-results/.auth/admin.json' } );
} );
