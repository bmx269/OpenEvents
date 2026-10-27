// Renders a design page to PNG: node render.mjs <page.html> <out.png> <width> <height> <scale>
// Only file:// and the Inter font CDN are loaded. Set PLAYWRIGHT_CHROMIUM_PATH to reuse a browser.
import { chromium } from 'playwright';
import path from 'node:path';

const [ html, out, w, h, s ] = process.argv.slice( 2 );
const browser = await chromium.launch( { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined } );
const ctx = await browser.newContext( { viewport: { width: +w, height: +h }, deviceScaleFactor: +s } );
await ctx.route( /^(?!file:|https:\/\/cdn\.jsdelivr\.net\/)/, ( r ) => r.abort() );
const page = await ctx.newPage();
await page.goto( 'file://' + path.resolve( html ) );
await page.evaluate( () => document.fonts.ready );
await page.waitForLoadState( 'networkidle' );
await page.screenshot( { path: out } );
await browser.close();
