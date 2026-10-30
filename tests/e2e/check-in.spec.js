const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const jsQR = require( 'jsqr' );
const { PNG } = require( 'pngjs' );
const { rest } = require( './helpers' );

const scan = ( page, selector ) =>
	new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.include( selector )
		.analyze();

test.describe( 'Passes and check-in', () => {
	test( 'a pass’s QR code opens check-in, and a second scan is caught', async ( {
		page,
	} ) => {
		// Organisers can open anyone's pass from the attendee list.
		const { body } = await rest(
			page,
			'/openevents/v1/attendees?search=mae@example.com'
		);
		const attendee = body[ 0 ];
		expect( attendee.pass_url ).toContain( 'oe_pass=' );

		await page.goto( attendee.pass_url );
		await expect( page.getByRole( 'heading', { level: 1 } ) ).toHaveText(
			'Weekly Storytime'
		);
		await expect( page.locator( '.oe-pass__code' ) ).toHaveText(
			attendee.code
		);
		const { violations } = await scan( page, '.oe-pass' );
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );

		// Read the QR code as a phone would.
		const image = PNG.sync.read(
			await page
				.getByRole( 'img', {
					name: `QR code for check-in code ${ attendee.code }`,
				} )
				.screenshot()
		);
		const decoded = jsQR(
			new Uint8ClampedArray( image.data ),
			image.width,
			image.height
		);
		expect( decoded?.data ).toContain(
			`page=openevents-check-in&code=${ attendee.code }`
		);

		// The link only looks them up; staff tap to check in.
		await page.goto( decoded.data );
		const status = page.getByRole( 'status' );
		await expect( status ).toContainText( 'Found' );
		await expect( status ).toContainText( attendee.name );
		await expect( status ).toContainText( 'not today' );
		await status.getByRole( 'button', { name: 'Check in' } ).click();
		await expect( status ).toContainText( 'Checked in.' );
		// Other specs may book this date too; the count is per date.
		await expect( status ).toContainText(
			/1 of \d+ checked in for this date/
		);

		// The next scan (a handheld scanner types into the focused field).
		const field = page.getByLabel( 'Check-in code' );
		await expect( field ).toBeFocused();
		await field.fill( attendee.code.toLowerCase() );
		await field.press( 'Enter' );
		await expect( status ).toContainText( 'Already checked in at' );

		await field.fill( 'NOTACODE' );
		await field.press( 'Enter' );
		await expect( status ).toContainText( 'No booking with that code' );

		const result = await scan( page, '.oe-check-in' );
		expect( result.violations.map( ( v ) => v.id ) ).toEqual( [] );
	} );
} );
