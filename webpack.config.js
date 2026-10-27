/**
 * Extends the @wordpress/scripts config with the non-block entries (editor
 * sidebar, block bindings source, admin app), which are not discovered from
 * block.json files.
 */
const [ scriptConfig, moduleConfig ] = require( '@wordpress/scripts/config/webpack.config' );

module.exports = [
	{
		...scriptConfig,
		entry: () => ( {
			...( typeof scriptConfig.entry === 'function' ? scriptConfig.entry() : scriptConfig.entry ),
			'editor/index': './src/editor/index.js',
			'editor/bindings': './src/editor/bindings.js',
			'admin/index': './src/admin/index.js',
		} ),
	},
	moduleConfig,
];
