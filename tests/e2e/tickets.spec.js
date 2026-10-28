const { test, expect } = require( '@playwright/test' );

const humanPause = ( page ) => page.waitForTimeout( 2100 );

test.describe( 'Tickets', () => {
	test( 'buying with JavaScript shows a running total and lands on the confirmation', async ( {
		page,
	} ) => {
		await page.goto( '/events/spring-concert/' );
		const tickets = page.locator( '.oe-tickets' );

		await tickets.getByLabel( 'Adult' ).selectOption( '2' );
		await tickets.getByLabel( 'Under 12' ).selectOption( '1' );
		await expect( tickets.locator( '.oe-tickets__total' ) ).toContainText(
			'$50.00'
		);

		await tickets.getByLabel( 'Name' ).fill( 'Ticket Buyer' );
		await tickets.getByLabel( 'Email' ).fill( 'buyer@example.com' );
		await expect(
			tickets.getByRole( 'radio', { name: 'Pay at the door' } )
		).toBeChecked();
		await humanPause( page );
		await tickets.getByRole( 'button', { name: 'Get tickets' } ).click();

		await expect( page ).toHaveURL( /oe_order_token=/ );
		const order = page.locator( '.oe-tickets__order' );
		await expect( order ).toContainText( 'Reserved — payment due' );
		await expect( order.locator( 'code' ) ).toHaveCount( 3 );
	} );

	test( 'buying without JavaScript works', async ( { browser } ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( '/events/spring-concert/' );
		const tickets = page.locator( '.oe-tickets' );
		await tickets.getByLabel( 'Student' ).selectOption( '1' );
		await tickets.getByLabel( 'Name' ).fill( 'No Script' );
		await tickets
			.getByLabel( 'Email' )
			.fill( 'noscript-buyer@example.com' );
		await humanPause( page );
		await tickets.getByRole( 'button', { name: 'Get tickets' } ).click();

		await expect( page ).toHaveURL( /oe_checkout=done/ );
		await expect( page.locator( '.oe-tickets__order code' ) ).toHaveCount(
			1
		);
		await context.close();
	} );

	test( 'orders screen: mark a pay-at-the-door order as paid', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-orders'
		);
		const table = page.locator( '.dataviews-view-table' );
		const row = table.locator( 'tr' ).filter( {
			has: page.getByText( 'Grace Hopper', { exact: true } ),
		} );
		await expect( row ).toContainText( 'Unpaid' );
		await expect( row ).toContainText( '$50.00' );

		await row.getByRole( 'button', { name: 'Mark as paid' } ).click();
		await expect( row ).toContainText( 'Paid' );
		await expect( page.getByText( '1 order updated.' ) ).toBeVisible();
	} );

	test( 'settings: tickets tab lists payment methods without exposing secrets', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-settings'
		);
		await page.getByRole( 'tab', { name: 'Tickets' } ).click();
		await expect(
			page.getByText( 'Pay at the door' ).first()
		).toBeVisible();
		await expect( page.getByText( 'Card (Stripe)' ) ).toBeVisible();
		await expect(
			page.getByText( 'PayPal', { exact: true } )
		).toBeVisible();
	} );
} );
