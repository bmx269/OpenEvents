const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const humanPause = ( page ) => page.waitForTimeout( 2100 );

test.describe( 'Per-date places', () => {
	test( 'each date of a repeating event takes its own RSVPs', async ( {
		page,
	} ) => {
		await page.goto( '/events/weekly-storytime/' );
		const rsvp = page.locator( '.oe-rsvp' );
		await expect( rsvp.locator( '.oe-rsvp__date' ) ).toContainText(
			'For '
		);
		await expect( rsvp.locator( '.oe-rsvp__count' ) ).toHaveText(
			'0 going · 3 spots left'
		);

		// The second date already has one RSVP.
		await page
			.getByRole( 'list', { name: 'Other dates' } )
			.getByRole( 'link' )
			.first()
			.click();
		await expect( page ).toHaveURL( /\d{4}-\d{2}-\d{2}\/?$/ );
		const date = await rsvp.locator( '.oe-rsvp__date time' ).innerText();
		await expect( rsvp.locator( '.oe-rsvp__count' ) ).toHaveText(
			'1 going · 2 spots left'
		);

		const { violations } = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '.oe-rsvp' )
			.analyze();
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );

		await rsvp.getByLabel( 'Name' ).fill( 'Second Date Guest' );
		await rsvp.getByLabel( 'Email' ).fill( 'second-date@example.com' );
		await humanPause( page );
		await rsvp.getByRole( 'button', { name: 'RSVP' } ).click();
		await expect( rsvp.locator( '.oe-rsvp__live' ) ).toHaveText(
			/You’re going/
		);
		await expect( rsvp.locator( '.oe-rsvp__count' ) ).toHaveText(
			'2 going · 1 spot left'
		);

		// The first date is unchanged.
		await page.goto( '/events/weekly-storytime/' );
		await expect( rsvp.locator( '.oe-rsvp__count' ) ).toHaveText(
			'0 going · 3 spots left'
		);

		// Organisers see who's coming on which date.
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-attendees'
		);
		await expect(
			page
				.locator( '.dataviews-view-table tr' )
				.filter( { hasText: 'Second Date Guest' } )
		).toContainText( date );
	} );
} );
