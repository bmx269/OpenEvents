<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library (see bin/install-wp-tests.sh)
 * and the plugin as a must-use plugin.
 *
 * @package OpenEvents
 */

$openevents_root      = dirname( __DIR__, 2 );
$openevents_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: $openevents_root . '/.wp-tests/wordpress-develop/tests/phpunit';

if ( ! file_exists( $openevents_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Run: composer test:install\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}
if ( ! file_exists( $openevents_root . '/build/blocks-manifest.php' ) ) {
	fwrite( STDERR, "Plugin assets not built. Run: npm run build\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $openevents_root . '/vendor/yoast/phpunit-polyfills' );

require_once $openevents_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $openevents_root ): void {
		require $openevents_root . '/openevents.php';
	}
);

require $openevents_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
