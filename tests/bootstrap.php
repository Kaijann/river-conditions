<?php
/**
 * PHPUnit bootstrap. Unit tests run without WordPress; WordPress functions
 * are stubbed per test with Brain Monkey.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

// The plugin's namespaced constants, read from the real plugin file so they never drift.
$rivcon_main = (string) file_get_contents( dirname( __DIR__ ) . '/river-conditions.php' );
preg_match( "/const VERSION\\s*=\\s*'([^']+)'/", $rivcon_main, $rivcon_version );
preg_match( "/const REPO_URL\\s*=\\s*'([^']+)'/", $rivcon_main, $rivcon_repo );
define( 'RiverConditions\\VERSION', $rivcon_version[1] );
define( 'RiverConditions\\REPO_URL', $rivcon_repo[1] );
define( 'RiverConditions\\PLUGIN_FILE', dirname( __DIR__ ) . '/river-conditions.php' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

// Minimal stand-in so the client's WP_Error branch can be tested without WordPress.
if ( ! class_exists( 'WP_Error' ) ) {
	require __DIR__ . '/stubs/class-wp-error.php';
}
