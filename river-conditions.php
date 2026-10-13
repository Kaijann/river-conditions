<?php
/**
 * Plugin Name:       River Conditions
 * Plugin URI:        https://github.com/Kaijann/river-conditions
 * Description:       Live river flow, gage height and water temperature from the USGS Water Data API, as a block and a shortcode.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Kai Shade
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       river-conditions
 * Domain Path:       /languages
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;
const REPO_URL    = 'https://github.com/Kaijann/river-conditions';

/*
 * Tiny PSR-4 autoloader, so the shipped zip needs no vendor/ directory.
 * Composer is used for dev tooling only.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = __DIR__ . '/src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
