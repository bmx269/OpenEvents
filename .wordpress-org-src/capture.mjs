// Captures real screenshots from a running demo site.
//   node .wordpress-org-src/capture.mjs [baseURL] [outDir]
// Start the demo first (same blueprint as the README's Playground link):
//   ./node_modules/.bin/wp-playground-cli server --blueprint=.wordpress-org/blueprints/blueprint.json --port=9402
// Set PLAYWRIGHT_CHROMIUM_PATH to reuse an installed browser.
import { chromium } from 'playwright';

const base = process.argv[ 2 ] || 'http://127.0.0.1:9402';
const out = process.argv[ 3 ] || '.wordpress-org-src/captures';
const browser = await chromium.launch( { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined } );

async function context( viewport, scale = 2 ) {
	const ctx = await browser.newContext( { viewport, deviceScaleFactor: scale } );
	const page = await ctx.newPage();
	await page.goto( base + '/wp-login.php' );
	if ( await page.locator( '#user_login' ).isVisible() ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForLoadState( 'networkidle' );
	}
	return { ctx, page };
}

async function shot( name, path, { viewport = { width: 1280, height: 800 }, prepare, hideAdminBar = false, scale } = {} ) {
	const { ctx, page } = await context( viewport, scale );
	await page.goto( base + path, { waitUntil: 'networkidle' } );
	if ( hideAdminBar ) {
		await page.addStyleTag( { content: '#wpadminbar{display:none!important} html{margin-top:0!important}' } );
	}
	if ( prepare ) {
		await prepare( page );
	}
	await page.screenshot( { path: `${ out }/${ name }.png` } );
	await ctx.close();
	console.log( name );
}

const scrollTo = ( selector ) => async ( page ) => {
	await page.locator( selector ).first().evaluate( ( el ) => window.scrollTo( 0, el.getBoundingClientRect().top + window.scrollY - 24 ) );
};

await shot( 'month', '/events/', { hideAdminBar: true, prepare: scrollTo( '.oe-calendar' ) } );
await shot( 'list', '/events/?oe_view=list&oe_past=1', { hideAdminBar: true, prepare: scrollTo( '.oe-calendar' ) } );
await shot( 'phone', '/events/', { viewport: { width: 390, height: 844 }, scale: 3, hideAdminBar: true, prepare: scrollTo( '.oe-calendar' ) } );
await shot( 'single', '/events/jazz-night/', { hideAdminBar: true, prepare: scrollTo( 'h1' ) } );
await shot( 'editor', '/wp-admin/edit.php?post_type=oe_event', {
	prepare: async ( page ) => {
		await page.locator( 'a.row-title', { hasText: 'Jazz Night' } ).click();
		const guide = page.locator( '.components-guide' );
		await guide.waitFor( { timeout: 8000 } ).catch( () => {} );
		if ( await guide.isVisible() ) {
			await guide.getByRole( 'button', { name: 'Close' } ).click();
			await guide.waitFor( { state: 'hidden' } );
		}
		const toggle = page.getByRole( 'button', { name: 'Event', exact: true } ).last();
		if ( ( await toggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
			await toggle.click();
		}
		await page.locator( '.oe-editor-panel' ).first().scrollIntoViewIfNeeded();
		await page.locator( '.interface-complementary-area' ).evaluate( ( el ) => el.scrollTo( 0, 430 ) );
		await page.waitForTimeout( 500 );
	},
} );
await shot( 'setup', '/wp-admin/admin.php?page=openevents-setup', {
	prepare: ( page ) => page.waitForSelector( '#openevents-admin button' ),
} );
await shot( 'settings', '/wp-admin/edit.php?post_type=oe_event&page=openevents-settings', {
	prepare: async ( page ) => {
		await page.waitForSelector( '#openevents-admin button' );
		await page.getByRole( 'tab', { name: 'Modules' } ).click();
	},
} );
await shot( 'rsvp', '/events/intro-to-bookbinding-3-places/', { hideAdminBar: true, prepare: scrollTo( '.oe-rsvp' ) } );
await shot( 'tickets', '/events/spring-concert/', { hideAdminBar: true, prepare: scrollTo( '.oe-tickets' ) } );
await shot( 'waitlist', '/events/author-talk-full/', { hideAdminBar: true, prepare: scrollTo( '.oe-rsvp' ) } );

await browser.close();
