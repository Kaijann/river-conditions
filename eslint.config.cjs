/**
 * ESLint: the @wordpress/scripts defaults, plus one setting.
 *
 * @wordpress/* packages are provided by WordPress at runtime (webpack maps
 * them to wp.* globals), so they are not npm dependencies of this plugin.
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaults,
	{
		settings: {
			'import/core-modules': [
				'@wordpress/api-fetch',
				'@wordpress/block-editor',
				'@wordpress/blocks',
				'@wordpress/components',
				'@wordpress/compose',
				'@wordpress/element',
				'@wordpress/i18n',
			],
		},
	},
	{
		// Plain browser script loaded with wp.* globals (no build step).
		files: [ 'assets/**/*.js' ],
		languageOptions: { globals: { window: 'readonly', document: 'readonly' } },
	},
];
