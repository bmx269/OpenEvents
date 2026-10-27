const { test, expect } = require( '@playwright/test' );

// The form rejects instant submissions (bots); wait past the 2-second minimum.
const humanPause = ( page ) => page.waitForTimeout( 2100 );

test.describe( 'RSVP', () => {
	test( 'responding with JavaScript shows the result in place and moves focus to it', async ( {
		page,
	} ) => {
		await page.goto( '/events/next-month-gala/' );
		const rsvp = page.locator( '.oe-rsvp' );
		await expect( rsvp.locator( '.oe-rsvp__count' ) ).toHaveText(
			/6 going · 114 spots left/
		);

		await rsvp.getByLabel( 'Name' ).fill( 'Playwright Tester' );
		await rsvp.getByLabel( 'Email' ).fill( 'tester@example.com' );
		await rsvp.getByLabel( 'People' ).selectOption( '2' );
		await humanPause( page );
		await rsvp.getByRole( 'button', { name: 'RSVP' } ).click();

		const message = rsvp.locator( '.oe-rsvp__live' );
		await expect( message ).toHaveText( /You’re going, party of 2/ );
		await expect( message ).toBeFocused();
		await expect( rsvp.locator( '.oe-rsvp__form' ) ).toBeHidden();
		await expect( rsvp.locator( '.oe-rsvp__count' ) ).toHaveText(
			/8 going · 112 spots left/
		);
	} );

	test( 'responding without JavaScript works and fills the last places', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( '/events/intro-to-bookbinding-3-places/' );
		const rsvp = page.locator( '.oe-rsvp' );
		await expect( rsvp.locator( '.oe-rsvp__count' ) ).toHaveText(
			/2 going · 1 spot left/
		);

		await rsvp.getByLabel( 'Name' ).fill( 'No Script' );
		await rsvp.getByLabel( 'Email' ).fill( 'noscript@example.com' );
		await humanPause( page );
		await rsvp.getByRole( 'button', { name: 'RSVP' } ).click();

		await expect( page ).toHaveURL( /oe_rsvp=going/ );
		await expect( rsvp.getByRole( 'status' ).first() ).toHaveText(
			/You’re going!/
		);
		await expect( rsvp.getByText( 'This event is full.' ) ).toBeVisible();
		await expect(
			rsvp.getByRole( 'radio', { name: 'Going', exact: true } )
		).toBeDisabled();
		await context.close();
	} );

	test( 'the same email cannot change someone else’s response', async ( {
		page,
	} ) => {
		await page.goto( '/events/next-month-gala/' );
		const rsvp = page.locator( '.oe-rsvp' );
		await rsvp.getByLabel( 'Name' ).fill( 'Someone Else' );
		await rsvp.getByLabel( 'Email' ).fill( 'grace@example.com' );
		await rsvp.getByRole( 'radio', { name: 'Not going' } ).check();
		await humanPause( page );
		await rsvp.getByRole( 'button', { name: 'RSVP' } ).click();

		await expect( rsvp.locator( '.oe-rsvp__live' ) ).toHaveText(
			/already responded/
		);
	} );

	test( 'attendee list: filter by event and check people in', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-attendees'
		);
		await expect(
			page.getByRole( 'heading', { name: 'Attendees' } )
		).toBeVisible();
		const table = page.locator( '.dataviews-view-table' );
		await expect(
			table.getByText( 'Grace Hopper', { exact: true } )
		).toBeVisible();

		const row = table
			.locator( 'tr' )
			.filter( {
				has: page.getByText( 'Katherine Johnson', { exact: true } ),
			} );
		await row.getByRole( 'button', { name: 'Check in' } ).click();
		await expect( row ).toContainText( 'Yes' );
	} );
} );
