/**
 * Calls the REST API as the logged-in admin. Uses the request context that
 * shares the browser's cookies, with a nonce from core's rest-nonce endpoint.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          path              REST path, e.g. "/wp/v2/events".
 * @param {Object}                          [init]            Request options.
 * @param {string}                          [init.method=GET] HTTP method.
 * @param {Object}                          [init.data]       JSON body.
 * @return {Promise<Object>} Status, headers, and parsed body.
 */
async function rest( page, path, { method = 'GET', data } = {} ) {
	const nonce = await (
		await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' )
	).text();
	const response = await page.request.fetch( `/wp-json${ path }`, {
		method,
		headers: { 'X-WP-Nonce': nonce },
		data,
	} );
	return {
		status: response.status(),
		headers: response.headers(),
		body: await response.json(),
	};
}

/**
 * "Y-m-d" for a day offset from today (local calendar).
 *
 * @param {number} offset Days from today.
 * @return {string} Date.
 */
function day( offset ) {
	const d = new Date();
	d.setDate( d.getDate() + offset );
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad(
		d.getDate()
	) }`;
}

module.exports = { rest, day };
