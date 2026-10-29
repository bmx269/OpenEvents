const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { rest, newPost } = require( './helpers' );

test.describe( 'Recurring events', () => {
	test( 'editor: repeat weekly, preview the dates, and publish', async ( {
		page,
	} ) => {
		await newPost( page, 'oe_event' );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await canvas
			.getByRole( 'textbox', { name: 'Add title' } )
			.fill( 'Weekly Workshop' );

		const eventToggle = page
			.getByRole( 'button', { name: 'Event', exact: true } )
			.last();
		if (
			( await eventToggle.getAttribute( 'aria-expanded' ) ) === 'false'
		) {
			await eventToggle.click();
		}
		// The 15th of this month in 2031, 7:30 am.
		await page.getByRole( 'button', { name: /^Starts:/ } ).click();
		const picker = page.locator( '.oe-editor-panel__picker' );
		for ( const [ name, value ] of [
			[ 'Year', '2031' ],
			[ 'Day', '15' ],
			[ 'Hours', '7' ],
			[ 'Minutes', '30' ],
		] ) {
			await picker.getByRole( 'spinbutton', { name } ).fill( value );
			await page.keyboard.press( 'Tab' );
		}
		await picker.getByText( 'AM', { exact: true } ).click();
		await page.keyboard.press( 'Escape' );

		const repeatToggle = page.getByRole( 'button', {
			name: 'Repeat',
			exact: true,
		} );
		if (
			( await repeatToggle.getAttribute( 'aria-expanded' ) ) === 'false'
		) {
			await repeatToggle.click();
		}
		await page.getByLabel( 'Repeats' ).selectOption( 'WEEKLY' );
		// The start's weekday is preselected.
		await expect(
			page.locator( '.oe-editor-panel__days [aria-pressed="true"]' )
		).toHaveCount( 1 );
		await page
			.getByRole( 'radio', { name: 'After a number of dates' } )
			.check();
		await page
			.getByRole( 'spinbutton', { name: 'Number of dates' } )
			.fill( '3' );

		const preview = page.locator( '.oe-editor-panel__preview' );
		await expect( preview ).toContainText(
			/Every week on \w+day, 3 times/
		);
		await expect(
			preview.getByRole( 'list', { name: 'Next dates' } ).locator( 'li' )
		).toHaveCount( 3 );

		await page
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await page
			.getByRole( 'region', { name: 'Editor publish' } )
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await expect(
			page.getByText( /Event published/ ).first()
		).toBeVisible();

		const id = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPostId()
		);
		const occurrences = (
			await rest( page, `/openevents/v1/occurrences?event=${ id }` )
		).body;
		expect( occurrences ).toHaveLength( 3 );
		const starts = occurrences.map( ( o ) => new Date( o.start_utc ) );
		expect( starts[ 0 ].toISOString() ).toMatch( /^2031-\d{2}-15T/ );
		expect( ( starts[ 1 ] - starts[ 0 ] ) / 864e5 ).toBe( 7 );
		expect( ( starts[ 2 ] - starts[ 1 ] ) / 864e5 ).toBe( 7 );
		const secondDate = occurrences[ 1 ].start.slice( 0, 10 );
		expect( occurrences[ 1 ].link ).toContain(
			`oe_occurrence=${ secondDate }`
		);

		// Each date's link opens the page for that date.
		await page.goto( occurrences[ 1 ].link );
		const details = page.locator( '.oe-details' );
		await expect(
			details.locator( '.oe-details__row--date time' )
		).toHaveAttribute( 'datetime', new RegExp( `^${ secondDate }T07:30` ) );
		await expect(
			details.locator( '.oe-details__row--repeats' )
		).toContainText( /Every week on \w+day, 3 times/ );
		await expect(
			details.getByRole( 'list', { name: 'Other dates' } ).locator( 'li' )
		).toHaveCount( 2 );
	} );

	test( 'a repeating sample event fills the calendar and its page is accessible', async ( {
		page,
	} ) => {
		await page.goto( '/events/' );
		const storytime = page
			.locator( '.oe-month' )
			.getByText( 'Weekly Storytime' );
		expect( await storytime.count() ).toBeGreaterThanOrEqual( 4 );

		await page
			.locator( '.oe-month a', { hasText: 'Weekly Storytime' } )
			.nth( 1 )
			.click();
		await expect( page ).toHaveURL( /oe_occurrence=\d{4}-\d{2}-\d{2}/ );
		const details = page.locator( '.oe-details' );
		await expect(
			details.locator( '.oe-details__row--repeats' )
		).toContainText( 'Every week on' );

		const { violations } = await new AxeBuilder( { page } )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.include( '.oe-details' )
			.analyze();
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );
	} );
} );
