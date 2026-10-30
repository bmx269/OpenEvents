const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const scan = ( page, selector ) =>
	new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.include( selector )
		.analyze();

test.describe( 'Event status', () => {
	test( 'a postponed event says so, with the note', async ( { page } ) => {
		await page.goto( '/events/online-talk-toronto-time/' );
		const details = page.locator( '.oe-details' );
		await expect( details.locator( '.oe-status' ) ).toHaveText(
			'Postponed'
		);
		await expect( details ).toContainText(
			'Our speaker is unwell. We’ll announce the new date soon.'
		);
		const { violations } = await scan( page, '.oe-details' );
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );
	} );

	test( 'one cancelled date of a repeating event stops taking RSVPs', async ( {
		page,
	} ) => {
		await page.goto( '/events/weekly-storytime/' );
		// The third upcoming date is cancelled in the sample data.
		await page
			.getByRole( 'list', { name: 'Other dates' } )
			.getByRole( 'link' )
			.nth( 1 )
			.click();
		const details = page.locator( '.oe-details' );
		await expect( details.locator( '.oe-status' ) ).toHaveText(
			'Cancelled'
		);
		await expect( details ).toContainText(
			'Our storyteller is away this week.'
		);
		await expect( page.locator( '.oe-rsvp__closed' ) ).toHaveText(
			'This event has been cancelled.'
		);
		await expect( page.locator( '.oe-rsvp form' ) ).toHaveCount( 0 );

		// The list view labels it, and the other dates aren't (Kids only, to fit one page).
		await page.goto( '/events/?oe_view=list&oe_audiences%5B%5D=kids' );
		const cards = page.locator( '.oe-event-card', {
			hasText: 'Weekly Storytime',
		} );
		await expect( cards.filter( { hasText: 'Cancelled' } ) ).toHaveCount(
			1
		);
		const { violations } = await scan( page, '.oe-calendar' );
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );
	} );
} );
