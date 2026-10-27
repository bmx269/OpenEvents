const { test, expect } = require( '@playwright/test' );
const { rest } = require( './helpers' );

test.describe( 'Admin', () => {
	test( 'setup wizard creates a calendar page and saves choices', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=openevents-setup' );

		await expect(
			page.getByText( 'Where should events live?' )
		).toBeVisible();
		await expect(
			page.getByRole( 'checkbox', { name: 'Posts' } )
		).toBeChecked();
		await page.getByRole( 'button', { name: 'Continue' } ).click();

		await expect(
			page.getByText( 'Where should the calendar appear?' )
		).toBeVisible();
		await page
			.getByRole( 'radio', { name: /Create a .Calendar. page/ } )
			.click();
		await page.getByRole( 'radio', { name: 'List' } ).click();
		await page.getByRole( 'button', { name: 'Continue' } ).click();

		await expect(
			page.getByText( 'Which features do you want?' )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Continue' } ).click();
		await expect( page.getByText( 'You’re all set' ) ).toBeVisible();

		const settings = ( await rest( page, '/wp/v2/settings' ) ).body;
		const options = settings.openevents_settings;
		expect( options.calendar_page ).toBeGreaterThan( 0 );
		expect( options.default_view ).toBe( 'list' );
		expect( settings.openevents_setup_complete ).toBe( true );

		const calendarPage = (
			await rest( page, `/wp/v2/pages/${ options.calendar_page }` )
		).body;
		await page.goto( calendarPage.link );
		await expect( page.locator( '.oe-calendar--list' ) ).toBeVisible();

		// Restore the month default for other tests.
		await rest( page, '/wp/v2/settings', {
			method: 'POST',
			data: {
				openevents_settings: { ...options, default_view: 'month' },
			},
		} );
	} );

	test( 'settings: turning off the iCal module removes its links', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&page=openevents-settings'
		);
		await page.getByRole( 'tab', { name: 'Modules' } ).click();
		const ical = page.getByRole( 'switch', { name: 'iCal export' } );
		await expect( ical ).toBeChecked();

		await ical.click();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await page.goto( '/events/' );
		await expect(
			page.getByRole( 'link', { name: 'Subscribe (iCal)' } )
		).toHaveCount( 0 );

		// Restore for other tests.
		await rest( page, '/wp/v2/settings', {
			method: 'POST',
			data: { openevents_modules: [ 'ical' ] },
		} );
		await page.goto( '/events/' );
		await expect(
			page.getByRole( 'link', { name: 'Subscribe (iCal)' } )
		).toBeVisible();
	} );

	test( 'admin list shows a sortable event date column', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=oe_event&orderby=oe_event_date&order=asc'
		);
		await expect( page.locator( 'th#oe_event_date' ) ).toBeVisible();
		const dates = await page
			.locator( 'td.column-oe_event_date' )
			.allTextContents();
		expect( dates.length ).toBeGreaterThan( 3 );
		expect( dates[ 0 ] ).not.toBe( '—' );
	} );

	test( 'editor: create an event from the sidebar panel', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php?post_type=oe_event' );
		await page.waitForFunction( () =>
			window.wp?.data?.dispatch( 'core/preferences' )
		);
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false )
		);
		// The guide is already open on this load; the preference applies from the next one.
		await page.reload();

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await canvas
			.getByRole( 'textbox', { name: 'Add title' } )
			.fill( 'Editor Event' );

		const panelToggle = page
			.getByRole( 'button', { name: 'Event', exact: true } )
			.last();
		if (
			( await panelToggle.getAttribute( 'aria-expanded' ) ) === 'false'
		) {
			await panelToggle.click();
		}
		await page.getByRole( 'button', { name: /^Starts:/ } ).click();
		const picker = page.locator( '.oe-editor-panel__picker' );
		// Fields commit on blur, so tab out of each one.
		for ( const [ name, value ] of [
			[ 'Year', '2031' ],
			[ 'Day', '15' ],
			[ 'Hours', '7' ],
			[ 'Minutes', '30' ],
		] ) {
			await picker.getByRole( 'spinbutton', { name } ).fill( value );
			await page.keyboard.press( 'Tab' );
		}
		// The site uses a 12-hour clock.
		await picker.getByText( 'AM', { exact: true } ).click();
		await page.keyboard.press( 'Escape' );
		// The label shows the stored wall-clock time, whatever the browser's timezone.
		await expect(
			page.getByRole( 'button', { name: /^Starts:/ } )
		).toHaveText( /15, 2031 7:30 am/i );

		await page.getByLabel( 'Cost' ).fill( '15' );
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

		const id =
			Number( new URL( page.url() ).searchParams.get( 'post' ) ) ||
			( await page.evaluate( () =>
				window.wp.data.select( 'core/editor' ).getCurrentPostId()
			) );
		const [ occurrence ] = (
			await rest( page, `/openevents/v1/occurrences?event=${ id }` )
		).body;
		expect( occurrence.title ).toBe( 'Editor Event' );
		expect( occurrence.start ).toMatch( /^2031-\d{2}-15T07:30:00/ );
		expect( occurrence.cost ).toBe( '15' );
	} );
} );
