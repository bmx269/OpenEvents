const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { rest } = require( './helpers' );

const humanPause = ( page ) => page.waitForTimeout( 2100 );

test.describe( 'Attendee questions', () => {
	test( 'ticket holders answer per ticket, and organisers see the answers', async ( {
		page,
	} ) => {
		await page.goto( '/events/puppet-show-registration/' );
		const form = page.locator( '.oe-tickets__form:not(.oe-waitlist)' );
		const holders = form.locator( '.oe-tickets__holders' );

		// No tickets chosen: no holder questions.
		await expect( holders ).toBeHidden();
		await form.getByLabel( 'Child', { exact: true } ).selectOption( '2' );
		await expect( holders ).toBeVisible();
		const groups = holders.locator( 'fieldset.oe-person:visible' );
		await expect( groups ).toHaveCount( 2 );

		const results = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '.oe-tickets' )
			.analyze();
		expect( results.violations.map( ( v ) => v.id ) ).toEqual( [] );

		await form
			.getByRole( 'textbox', { name: 'Name' } )
			.first()
			.fill( 'Parent Person' );
		await form
			.getByRole( 'textbox', { name: 'Email' } )
			.first()
			.fill( 'parent@example.com' );

		for ( const [ index, kid ] of [ 'Kid One', 'Kid Two' ].entries() ) {
			const group = groups.nth( index );
			await group.getByRole( 'textbox', { name: 'Name' } ).fill( kid );
			await group
				.getByRole( 'textbox', { name: 'Email' } )
				.fill( `kid${ index }@example.com` );
			await group
				.getByRole( 'radio', { name: index ? '6–8' : '3–5' } )
				.check();
			await group
				.getByRole( 'checkbox', { name: /I understand/ } )
				.check();
		}
		await groups
			.first()
			.getByRole( 'textbox', { name: /Phone number/ } )
			.fill( '604 555 0100' );

		await humanPause( page );
		await form.getByRole( 'button', { name: 'Get tickets' } ).click();
		await expect( page ).toHaveURL( /oe_order_token=/ );
		await expect( page.locator( '.oe-tickets__order code' ) ).toHaveCount(
			2
		);

		const id = await page
			.locator( '.oe-tickets__form input[name="event_id"]' )
			.first()
			.inputValue();
		const attendees = (
			await rest( page, `/openevents/v1/attendees?event=${ id }` )
		).body;
		const kidOne = attendees.find( ( a ) => a.name === 'Kid One' );
		expect( kidOne.email ).toBe( 'kid0@example.com' );
		expect(
			Object.fromEntries(
				kidOne.answers.map( ( a ) => [ a.label, a.value ] )
			)
		).toMatchObject( {
			'Age range': '3–5',
			'Phone number': '604 555 0100',
		} );
	} );

	test( 'a missing required answer is reported and nothing is booked', async ( {
		page,
	} ) => {
		await page.goto( '/events/puppet-show-registration/' );
		const form = page.locator( '.oe-tickets__form:not(.oe-waitlist)' );
		await form.getByLabel( 'Child', { exact: true } ).selectOption( '1' );
		const group = form.locator( 'fieldset.oe-person:visible' ).first();

		// The browser requires the answers once the group is in use.
		await expect(
			group.getByRole( 'checkbox', { name: /I understand/ } )
		).toHaveAttribute( 'required', '' );
		await expect( group.getByRole( 'radio' ).first() ).toHaveAttribute(
			'required',
			''
		);
	} );

	test( 'RSVP guests get their own questions as the party grows', async ( {
		page,
	} ) => {
		const day = new Date( Date.now() + 20 * 864e5 )
			.toISOString()
			.slice( 0, 10 );
		const created = await rest( page, '/wp/v2/events', {
			method: 'POST',
			data: {
				title: 'Book Club (questions)',
				status: 'publish',
				meta: {
					_oe_start: `${ day } 18:00:00`,
					_oe_end: `${ day } 19:00:00`,
					_oe_rsvp: {
						enabled: true,
						max_per_response: 3,
						attendee_details: 'optional',
						fields: [
							{
								type: 'select',
								label: 'Book format',
								required: true,
								options: [ 'Print', 'Large print', 'Audio' ],
							},
						],
					},
				},
			},
		} );
		expect( created.status ).toBe( 201 );

		await page.goto( created.body.link );
		const form = page.locator( '.oe-rsvp__form' );
		const groups = form.locator( 'fieldset.oe-person:visible' );
		await expect( groups ).toHaveCount( 1 );
		await form.getByLabel( 'People' ).selectOption( '2' );
		await expect( groups ).toHaveCount( 2 );
		await expect( groups.nth( 1 ).locator( 'legend' ) ).toHaveText(
			'Guest 2'
		);

		// Not going: no questions.
		await form.getByRole( 'radio', { name: 'Not going' } ).check();
		await expect( groups ).toHaveCount( 0 );
		await form.getByRole( 'radio', { name: 'Going', exact: true } ).check();

		await form
			.getByLabel( 'Name', { exact: true } )
			.first()
			.fill( 'Reader One' );
		await form
			.getByLabel( 'Email', { exact: true } )
			.first()
			.fill( 'reader@example.com' );
		await groups
			.nth( 0 )
			.getByLabel( 'Book format' )
			.selectOption( 'Audio' );
		await groups.nth( 1 ).getByLabel( /^Name/ ).fill( 'Reader Two' );
		await groups
			.nth( 1 )
			.getByLabel( 'Book format' )
			.selectOption( 'Large print' );
		await humanPause( page );
		await form.getByRole( 'button', { name: 'RSVP' } ).click();
		await expect( page.locator( '.oe-rsvp__live' ) ).toContainText(
			'party of 2'
		);

		const attendees = (
			await rest(
				page,
				`/openevents/v1/attendees?event=${ created.body.id }&orderby=id&order=asc`
			)
		).body;
		expect(
			attendees.map( ( a ) => [ a.name, a.answers[ 0 ]?.value ] )
		).toEqual( [
			[ 'Reader One', 'Audio' ],
			[ 'Reader Two', 'Large print' ],
		] );
	} );
} );
