const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { rest } = require( './helpers' );

test.describe( 'Calendar block', () => {
	test( 'month grid shows sample events with category colours and legend', async ( {
		page,
	} ) => {
		await page.goto( '/events/?oe_view=month' );
		const calendar = page.locator( '.oe-calendar' );

		await expect( calendar.locator( '.oe-calendar__title' ) ).toHaveText(
			/\w+ \d{4}/
		);
		await expect(
			calendar.locator( '.oe-month__event-title', {
				hasText: 'Jazz Night',
			} )
		).toBeVisible();
		// The three-day festival appears on each of its days.
		await expect(
			calendar.locator( '.oe-month__event-title', {
				hasText: 'Harvest Festival',
			} )
		).toHaveCount( 3 );
		// Hidden events never show in listings.
		await expect( calendar.getByText( 'Hidden Rehearsal' ) ).toHaveCount(
			0
		);
		// An ordinary Post that was given a date is on the calendar.
		await expect(
			calendar.getByText( 'Blog post: Book Launch' )
		).toBeVisible();
		await expect( page.locator( '.oe-legend li' ) ).toHaveCount( 3 );
		await expect( page.locator( '.oe-month__more' ) ).toHaveText(
			'+1 more'
		);
	} );

	test( 'navigation updates in place without a page reload and moves focus', async ( {
		page,
	} ) => {
		await page.goto( '/events/?oe_view=month' );
		const title = page.locator( '.oe-calendar__title' );
		const before = await title.textContent();
		await page.evaluate( () => ( window.__noReload = true ) );

		await page.getByRole( 'link', { name: 'Next month' } ).click();

		await expect( title ).not.toHaveText( before );
		await expect( page ).toHaveURL( /oe_month=\d{4}-\d{2}/ );
		expect( await page.evaluate( () => window.__noReload ) ).toBe( true );
		await expect( title ).toBeFocused();
		await expect( page.getByText( 'Next Month Gala' ) ).toBeVisible();
	} );

	test( 'navigation works with JavaScript disabled', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: 'test-results/.auth/admin.json',
		} );
		const page = await context.newPage();
		await page.goto( '/events/?oe_view=month' );

		await page.getByRole( 'link', { name: 'Next month' } ).click();
		await expect( page.getByText( 'Next Month Gala' ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'List', exact: true } ).click();
		await expect( page.locator( '.oe-calendar--list' ) ).toBeVisible();
		await context.close();
	} );

	test( 'navigation pages are noindexed; the base calendar is not', async ( {
		page,
	} ) => {
		await page.goto( '/events/' );
		await expect(
			page.locator( 'meta[name="robots"][content*="noindex"]' )
		).toHaveCount( 0 );

		await page.goto( '/events/?oe_month=2030-01' );
		await expect( page.locator( 'meta[name="robots"]' ) ).toHaveAttribute(
			'content',
			/noindex/
		);
		await expect( page.locator( 'meta[name="robots"]' ) ).toHaveAttribute(
			'content',
			/follow/
		);
	} );

	test( 'list view groups by month and links to past events', async ( {
		page,
	} ) => {
		await page.goto( '/events/?oe_view=list' );
		await expect( page.locator( '.oe-list__group' ).first() ).toBeVisible();
		await page.getByRole( 'link', { name: 'Past', exact: true } ).click();
		await expect( page.locator( '.oe-calendar__title' ) ).toHaveText(
			'Past events'
		);
		await expect(
			page.locator( '.oe-event-card__title' ).first()
		).toBeVisible();
	} );

	test( 'filter by category and search update the calendar in place', async ( {
		page,
	} ) => {
		await page.goto( '/events/?oe_view=month' );
		await page.evaluate( () => ( window.__noReload = true ) );
		const calendar = page.locator( '.oe-calendar' );

		await calendar
			.getByLabel( 'Category', { exact: true } )
			.selectOption( { label: 'Workshops' } );
		await calendar.getByRole( 'button', { name: 'Filter' } ).click();

		await expect( page ).toHaveURL( /oe_cat=workshops/ );
		await expect( calendar.locator( '.oe-calendar__summary' ) ).toHaveText(
			'Showing Workshops events'
		);
		await expect( calendar.getByText( 'Jazz Night' ) ).toHaveCount( 0 );
		await expect(
			calendar.locator( '.oe-month__event-title', {
				hasText: 'Pottery Basics',
			} )
		).toBeVisible();
		await expect( calendar.locator( '.oe-calendar__title' ) ).toBeFocused();
		expect( await page.evaluate( () => window.__noReload ) ).toBe( true );

		await calendar.getByLabel( 'Search events' ).fill( 'online' );
		await calendar.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( calendar.locator( '.oe-calendar__summary' ) ).toHaveText(
			'Showing Workshops events matching “online”'
		);
		await expect( calendar.getByText( 'Pottery Basics' ) ).toHaveCount( 0 );

		await calendar.getByRole( 'link', { name: 'Clear filters' } ).click();
		await expect( calendar.locator( '.oe-calendar__summary' ) ).toHaveCount(
			0
		);
		await expect(
			calendar.locator( '.oe-month__event-title', {
				hasText: 'Jazz Night',
			} )
		).toBeVisible();
	} );

	test( 'filters work without JavaScript', async ( { browser } ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( '/events/?oe_view=list&oe_past=1' );
		await page.getByLabel( 'Search events' ).fill( 'choir' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();

		await expect( page ).toHaveURL( /oe_s=choir/ );
		await expect( page.locator( '.oe-event-card__title' ) ).toHaveText( [
			'Choir Practice',
		] );
		await context.close();
	} );

	test( 'popup mode: an accessible summary dialog instead of navigating', async ( {
		page,
	} ) => {
		const { openevents_settings: original } = (
			await rest( page, '/wp/v2/settings' )
		).body;
		await rest( page, '/wp/v2/settings', {
			method: 'POST',
			data: {
				openevents_settings: { ...original, click_action: 'popup' },
			},
		} );

		await page.goto( '/events/?oe_view=month' );
		const link = page.locator( '.oe-month__event', {
			hasText: 'Jazz Night',
		} );
		await link.click();

		const dialog = page.getByRole( 'dialog', { name: 'Jazz Night' } );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( 'The Commodore Ballroom' );
		await expect(
			dialog.getByRole( 'link', { name: 'View event' } )
		).toHaveAttribute( 'href', /\/events\/jazz-night\/$/ );
		// Focus moved into the dialog.
		await expect( dialog.locator( ':focus' ) ).toHaveCount( 1 );
		await expect( page ).toHaveURL( /\/events\/\?oe_view=month$/ );

		// No violations with the dialog open.
		const { violations } = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] )
			.include( '.oe-popup' )
			.analyze();
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );

		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( link ).toBeFocused();

		await link.click();
		await expect( dialog ).toBeVisible();
		await page.mouse.click( 5, 5 ); // Backdrop.
		await expect( dialog ).toBeHidden();

		// Modifier clicks still open the page.
		const [ popup ] = await Promise.all( [
			page.context().waitForEvent( 'page' ),
			link.click( {
				modifiers: [
					process.platform === 'darwin' ? 'Meta' : 'Control',
				],
			} ),
		] );
		await expect( popup ).toHaveURL( /jazz-night/ );
		await popup.close();

		await rest( page, '/wp/v2/settings', {
			method: 'POST',
			data: { openevents_settings: original },
		} );
	} );

	test.describe( 'on a phone', () => {
		test.use( { viewport: { width: 390, height: 844 } } );

		test( 'month grid becomes an agenda of days with events', async ( {
			page,
		} ) => {
			await page.goto( '/events/?oe_view=month' );
			await expect( page.locator( '.oe-month thead' ) ).toBeHidden();
			await expect(
				page.locator( '.oe-month__day.is-empty' ).first()
			).toBeHidden();
			await expect(
				page.locator( '.oe-month__day:not(.is-empty)' ).first()
			).toBeVisible();
			// Nothing overflows horizontally.
			const overflow = await page.evaluate(
				() => document.documentElement.scrollWidth - window.innerWidth
			);
			expect( overflow ).toBeLessThanOrEqual( 0 );
		} );
	} );
} );
