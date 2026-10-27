/**
 * Automated WCAG 2.2 AA checks (axe-core) on everything OpenEvents renders.
 * Scans are limited to the plugin's own markup so theme or wp-admin issues
 * outside it don't mask or cause failures.
 */
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

async function scan( page, include ) {
	const builder = new AxeBuilder( { page } ).withTags( TAGS );
	for ( const selector of include ) {
		builder.include( selector );
	}
	const { violations } = await builder.analyze();
	const summary = violations.map(
		( v ) =>
			`${ v.id } (${ v.impact }): ${ v.help } — ${ v.nodes
				.map( ( n ) => n.target.join( ' ' ) )
				.slice( 0, 3 )
				.join( ', ' ) }`
	);
	expect( summary, summary.join( '\n' ) ).toEqual( [] );
}

const PAGES = [
	[ 'month view', '/events/?oe_view=month', [ '.oe-calendar' ] ],
	[ 'list view', '/events/?oe_view=list', [ '.oe-calendar' ] ],
	[ 'past events', '/events/?oe_view=list&oe_past=1', [ '.oe-calendar' ] ],
	[ 'event details', '/events/jazz-night/', [ '.oe-details' ] ],
	[ 'RSVP form', '/events/next-month-gala/', [ '.oe-details' ] ],
	[ 'existing post as event', '/blog-post-book-launch/', [ '.oe-details' ] ],
];

test.describe( 'Accessibility (WCAG 2.2 AA)', () => {
	for ( const [ name, url, include ] of PAGES ) {
		test( `front end: ${ name }`, async ( { page } ) => {
			await page.goto( url );
			await scan( page, include );
		} );
	}

	test.describe( 'on a phone', () => {
		test.use( { viewport: { width: 390, height: 844 } } );
		test( 'front end: month view as agenda', async ( { page } ) => {
			await page.goto( '/events/?oe_view=month' );
			await scan( page, [ '.oe-calendar' ] );
		} );
	} );

	test( 'admin: settings', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-settings'
		);
		await page.waitForSelector( '#openevents-admin button' );
		await scan( page, [ '#openevents-admin' ] );
	} );

	test( 'admin: setup wizard', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=openevents-setup' );
		await page.waitForSelector( '#openevents-admin button' );
		await scan( page, [ '#openevents-admin' ] );
	} );

	test( 'admin: attendees', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-attendees'
		);
		await page.waitForSelector( '.dataviews-view-table' );
		await scan( page, [ '#openevents-attendees' ] );
	} );
} );
