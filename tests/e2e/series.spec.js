const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const humanPause = ( page ) => page.waitForTimeout( 2100 );

test.describe( 'Series', () => {
	test( 'a series page lists its dates and sells passes', async ( {
		page,
	} ) => {
		await page.goto( '/series/autumn-film-season/' );
		const series = page.locator( '.oe-series' );
		await expect( series.locator( 'article.oe-event-card' ) ).toHaveCount(
			3
		);
		await expect( series ).toContainText( 'Film Night: Casablanca' );

		const { violations } = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '.oe-series' )
			.analyze();
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );

		const form = series.locator( '.oe-tickets__form:not(.oe-waitlist)' );
		await form
			.getByLabel( 'Season pass', { exact: true } )
			.selectOption( '1' );
		await form.getByLabel( 'Name', { exact: true } ).fill( 'Film Fan' );
		await form
			.getByLabel( 'Email', { exact: true } )
			.fill( 'fan@example.com' );
		await humanPause( page );
		await form.getByRole( 'button', { name: 'Get tickets' } ).click();
		await expect( page ).toHaveURL( /oe_order_token=/ );
		await expect( page.locator( '.oe-tickets__order code' ) ).toHaveCount(
			1
		);
	} );

	test( 'events link to their series', async ( { page } ) => {
		await page.goto( '/events/film-night-casablanca/' );
		const row = page.locator( '.oe-details__row--series' );
		await expect( row ).toContainText( 'Autumn Film Season' );
		await row.getByRole( 'link' ).click();
		await expect( page ).toHaveURL( /\/series\/autumn-film-season\/$/ );
	} );
} );
