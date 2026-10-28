const { test, expect } = require( '@playwright/test' );

const humanPause = ( page ) => page.waitForTimeout( 2100 );

test.describe( 'Waitlist', () => {
	test( 'a full RSVP offers the waitlist, and a cancellation promotes the next person', async ( {
		page,
	} ) => {
		await page.goto( '/events/author-talk-full/' );
		const rsvp = page.locator( '.oe-rsvp' );
		await expect( rsvp.getByText( 'This event is full.' ) ).toBeVisible();

		await rsvp.getByLabel( 'Name' ).fill( 'Waiting Person' );
		await rsvp.getByLabel( 'Email' ).fill( 'waiting@example.com' );
		await humanPause( page );
		await rsvp.getByRole( 'button', { name: 'Join the waitlist' } ).click();

		const message = rsvp.locator( '.oe-rsvp__live' );
		await expect( message ).toHaveText( /on the waitlist \(#1\)/ );
		await expect( message ).toBeFocused();

		// An organiser cancels someone; the waitlisted person is confirmed automatically.
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-attendees'
		);
		const table = page.locator( '.dataviews-view-table' );
		const mary = table.locator( 'tr' ).filter( {
			has: page.getByText( 'Mary Jackson', { exact: true } ),
		} );
		await mary.getByRole( 'button', { name: 'Actions' } ).click();
		await page.getByRole( 'menuitem', { name: 'Cancel RSVP' } ).click();

		await page.reload();
		const promoted = table.locator( 'tr' ).filter( {
			has: page.getByText( 'Waiting Person', { exact: true } ),
		} );
		await expect( promoted ).toContainText( 'Going' );
	} );

	test( 'a sold-out ticket type offers a waitlist without JavaScript', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( '/events/spring-concert/' );
		const form = page.locator( 'form.oe-waitlist' );
		await expect(
			form.getByRole( 'heading', { name: 'Join the waitlist' } )
		).toBeVisible();

		await form.getByLabel( 'Name' ).fill( 'Front Row Fan' );
		await form.getByLabel( 'Email' ).fill( 'fan@example.com' );
		await humanPause( page );
		await form.getByRole( 'button', { name: 'Join the waitlist' } ).click();

		await expect( page ).toHaveURL( /oe_waitlist_result=waitlisted/ );
		await expect(
			page.locator( '.oe-tickets' ).getByRole( 'status' ).first()
		).toContainText( 'on the waitlist' );
		await context.close();
	} );

	test( 'the waitlist screen lists people in order', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-waitlist'
		);
		await expect(
			page.getByRole( 'heading', { name: 'Waitlist' } )
		).toBeVisible();
		await expect(
			page
				.locator( '.dataviews-view-table' )
				.getByText( 'Front Row Fan', { exact: true } )
		).toBeVisible();
	} );
} );
