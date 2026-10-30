const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'Week and day views', () => {
	test( 'week view navigates in place and opens a day', async ( {
		page,
	} ) => {
		await page.goto( '/events/?oe_view=week' );
		const calendar = page.locator( '.oe-calendar' );
		await expect( calendar.locator( '.oe-week__day' ) ).toHaveCount( 7 );
		await expect(
			calendar.getByRole( 'link', { name: 'Week', exact: true } )
		).toHaveAttribute( 'aria-current', 'page' );

		const { violations } = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '.oe-calendar' )
			.analyze();
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );

		const title = await calendar
			.locator( '.oe-calendar__title' )
			.textContent();
		const url = page.url();
		await calendar.getByRole( 'link', { name: 'Next week' } ).click();
		await expect(
			calendar.locator( '.oe-calendar__title' )
		).not.toHaveText( title );
		// Updated in place, and focus moves to the new heading.
		await expect( calendar.locator( '.oe-calendar__title' ) ).toBeFocused();
		expect( page.url() ).not.toBe( url );

		const firstDay = calendar.locator( '.oe-week__date a' ).first();
		const date = await firstDay
			.locator( 'time' )
			.getAttribute( 'datetime' );
		await firstDay.click();
		await expect( page.locator( '.oe-calendar--day' ) ).toBeVisible();
		await expect( page ).toHaveURL( new RegExp( `oe_date=${ date }` ) );
	} );

	test( 'week and day views work without JavaScript', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( '/events/?oe_view=week' );
		await page.getByRole( 'link', { name: 'Day', exact: true } ).click();
		await expect( page.locator( '.oe-calendar--day' ) ).toBeVisible();
		await page.getByRole( 'link', { name: 'Next day' } ).click();
		await expect( page ).toHaveURL(
			/oe_view=day.*oe_date=\d{4}-\d{2}-\d{2}/
		);
		await context.close();
	} );
} );
