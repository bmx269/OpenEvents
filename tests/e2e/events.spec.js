const { test, expect } = require( '@playwright/test' );
const { rest, day } = require( './helpers' );

test.describe( 'Event pages', () => {
	test( 'single event shows details and calendar links', async ( {
		page,
	} ) => {
		await page.goto( '/events/jazz-night/' );
		const details = page.locator( '.oe-details' );

		await expect( details.locator( 'dt' ) ).toContainText( [
			'When',
			'Where',
			'Organizer',
			'Cost',
		] );
		await expect( details ).toContainText( 'The Commodore Ballroom' );
		await expect(
			details.getByRole( 'link', { name: 'Google Calendar' } )
		).toHaveAttribute( 'href', /calendar\.google\.com/ );

		const ics = await page.request.get( '/events/jazz-night/?ical=1' );
		expect( ics.headers()[ 'content-type' ] ).toContain( 'text/calendar' );
		expect( await ics.text() ).toMatch(
			/BEGIN:VEVENT[\s\S]+SUMMARY:Jazz Night/
		);
	} );

	test( 'existing post types: dated posts get details, others are untouched', async ( {
		page,
	} ) => {
		await page.goto( '/blog-post-book-launch/' );
		await expect( page.locator( '.oe-details' ) ).toBeVisible();

		await page.goto( '/plain-blog-post-not-an-event/' );
		await expect( page.locator( '.oe-details' ) ).toHaveCount( 0 );
		await expect(
			page.getByText( 'Nothing about this post should change.' )
		).toBeVisible();
	} );

	test( 'REST: create, move, unpublish, and trash keep listings correct', async ( {
		page,
	} ) => {
		const created = await rest( page, '/wp/v2/events', {
			method: 'POST',
			data: {
				title: 'E2E Event',
				status: 'publish',
				meta: {
					_oe_start: `${ day( 3 ) } 19:00:00`,
					_oe_end: `${ day( 3 ) } 20:00:00`,
				},
			},
		} );
		expect( created.status ).toBe( 201 );
		const id = created.body.id;
		const occurrences = async () =>
			( await rest( page, `/openevents/v1/occurrences?event=${ id }` ) )
				.body;

		const [ first ] = await occurrences();
		expect( first.title ).toBe( 'E2E Event' );

		await rest( page, `/wp/v2/events/${ id }`, {
			method: 'POST',
			data: {
				meta: {
					_oe_start: `${ day( 4 ) } 09:00:00`,
					_oe_end: `${ day( 4 ) } 10:00:00`,
				},
			},
		} );
		const [ moved ] = await occurrences();
		expect( moved.id ).toBe( first.id );
		expect( moved.start ).toContain( day( 4 ) );

		await rest( page, `/wp/v2/events/${ id }`, {
			method: 'POST',
			data: { status: 'draft' },
		} );
		expect( await occurrences() ).toEqual( [] );

		await rest( page, `/wp/v2/events/${ id }`, {
			method: 'POST',
			data: { status: 'publish' },
		} );
		expect( await occurrences() ).toHaveLength( 1 );

		await rest( page, `/wp/v2/events/${ id }`, { method: 'DELETE' } );
		expect( await occurrences() ).toEqual( [] );
	} );

	test( 'a category template applies to events in that category', async ( {
		page,
	} ) => {
		const created = await rest( page, '/wp/v2/templates', {
			method: 'POST',
			data: {
				slug: 'single-event-category-workshops',
				title: 'Single Event: Workshops',
				content:
					'<!-- wp:paragraph --><p>Workshop template in use</p><!-- /wp:paragraph --><!-- wp:post-title /--><!-- wp:openevents/event-details /-->',
			},
		} );
		expect( created.status ).toBe( 201 );

		await page.goto( '/events/pottery-basics/' );
		await expect(
			page.getByText( 'Workshop template in use' )
		).toBeVisible();
		await expect( page.locator( '.oe-details' ) ).toBeVisible();

		await page.goto( '/events/jazz-night/' );
		await expect(
			page.getByText( 'Workshop template in use' )
		).toHaveCount( 0 );

		await rest( page, `/wp/v2/templates/${ created.body.id }?force=true`, {
			method: 'DELETE',
		} );
	} );

	test( 'abilities are exposed to authenticated clients', async ( {
		page,
	} ) => {
		const list = await rest(
			page,
			'/wp-abilities/v1/abilities?category=openevents'
		);
		expect( list.body.map( ( a ) => a.name ) ).toEqual(
			expect.arrayContaining( [
				'openevents/list-events',
				'openevents/create-event',
			] )
		);
	} );
} );
