/**
 * End-to-end tests against WordPress Playground (no Docker or MySQL needed).
 * The blueprint seeds sample events relative to the current month.
 */
const { defineConfig, devices } = require( '@playwright/test' );

const PORT = 9400;
const baseURL = `http://127.0.0.1:${ PORT }`;

module.exports = defineConfig( {
	testDir: './tests/e2e',
	// Tests share one WordPress instance and some change settings, so run serially.
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	reporter: process.env.CI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : 'list',
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		launchOptions: {
			// Lets local runs reuse an already-downloaded browser.
			executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined,
		},
	},
	projects: [
		{ name: 'setup', testMatch: /auth\.setup\.js/ },
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ], storageState: 'test-results/.auth/admin.json' },
			dependencies: [ 'setup' ],
		},
	],
	webServer: {
		// Mount at a fixed folder name: --auto-mount would use the checkout's
		// folder name (e.g. "OpenEvents" in CI), breaking the plugin path.
		command: `./node_modules/.bin/wp-playground-cli server --blueprint=blueprint.json --mount=.:/wordpress/wp-content/plugins/openevents --port=${ PORT }`,
		// A static file: dynamic pages may redirect through Playground's auto-login.
		url: `${ baseURL }/readme.html`,
		timeout: 300_000,
		reuseExistingServer: ! process.env.CI,
		stdout: 'ignore',
	},
} );
