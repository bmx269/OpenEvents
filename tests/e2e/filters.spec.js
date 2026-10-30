const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'More filters', () => {
	test( 'filter the list by an event attribute, in place', async ( {
		page,
	} ) => {
		await page.goto( '/events/?oe_view=list' );
		const calendar = page.locator( '.oe-calendar' );
		await calendar.getByText( 'More filters' ).click();
		await expect(
			calendar.getByRole( 'group', { name: 'Day of the week' } )
		).toBeVisible();

		const { violations } = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '.oe-calendar__filters' )
			.analyze();
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );

		await calendar
			.getByRole( 'group', { name: 'Audiences' } )
			.getByRole( 'checkbox', { name: 'Kids' } )
			.check();
		await calendar.getByRole( 'button', { name: 'Filter' } ).click();

		await expect( page ).toHaveURL( /oe_audiences/ );
		await expect(
			calendar.locator( '.oe-calendar__summary' )
		).toContainText( 'Kids' );
		await expect(
			calendar.getByText( 'Weekly Storytime' ).first()
		).toBeVisible();
		await expect( calendar.getByText( 'Spring Concert' ) ).toHaveCount( 0 );
	} );

	test( 'filters work without JavaScript', async ( { browser } ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( '/events/?oe_view=list' );
		const calendar = page.locator( '.oe-calendar' );
		await calendar.getByText( 'More filters' ).click();
		await calendar
			.getByRole( 'group', { name: 'Audiences' } )
			.getByRole( 'checkbox', { name: 'Adults' } )
			.check();
		await calendar.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page ).toHaveURL( /oe_audiences%5B%5D=adults/ );
		await expect(
			calendar.getByText( 'Spring Concert' ).first()
		).toBeVisible();
		await expect( calendar.getByText( 'Weekly Storytime' ) ).toHaveCount(
			0
		);
		await context.close();
	} );
} );
