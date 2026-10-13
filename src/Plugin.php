<?php
/**
 * Wires everything together.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions;

use RiverConditions\Api\UsgsClient;
use RiverConditions\Blocks\GaugeBlock;
use RiverConditions\Cache\GaugeRepository;
use RiverConditions\Render\GaugeRenderer;
use RiverConditions\Rest\Controller;

/**
 * Bootstraps the plugin. Holds the shared renderer so render.php can reach it.
 */
final class Plugin {

	public const DB_VERSION        = 1;
	public const DB_VERSION_OPTION = 'rivcon_db_version';
	public const STYLE_HANDLE      = 'river-conditions-gauge';

	/**
	 * The shared renderer.
	 *
	 * @var GaugeRenderer|null
	 */
	private static ?GaugeRenderer $renderer = null;

	/**
	 * Hook everything up (plugins_loaded).
	 */
	public static function boot(): void {
		$settings   = Settings::get();
		$client     = new UsgsClient( $settings['api_key'] );
		$repository = new GaugeRepository( $client, $settings['cache_minutes'] * MINUTE_IN_SECONDS );

		self::$renderer = new GaugeRenderer( $repository, $settings );

		( new Settings() )->register();
		( new Shortcode( self::$renderer ) )->register();
		( new Controller( $repository, self::$renderer, $client ) )->register();
		( new CacheWarmer( $repository ) )->register();

		add_action( 'init', array( self::class, 'init' ) );
	}

	/**
	 * Register the stylesheet and block, and run upgrades (init).
	 */
	public static function init(): void {
		load_plugin_textdomain( 'river-conditions', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

		$css = dirname( PLUGIN_FILE ) . '/build/gauge/style-index.css';
		wp_register_style(
			self::STYLE_HANDLE,
			plugins_url( 'build/gauge/style-index.css', PLUGIN_FILE ),
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : VERSION
		);
		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );

		( new GaugeBlock() )->register();
		self::maybe_upgrade();
	}

	/**
	 * The shared renderer (used by blocks/gauge/render.php).
	 *
	 * @throws \LogicException If the plugin failed to boot.
	 */
	public static function renderer(): GaugeRenderer {
		if ( null === self::$renderer ) {
			self::boot();
		}
		return self::$renderer ?? throw new \LogicException( 'River Conditions did not boot.' );
	}

	/**
	 * Enqueue the front-end stylesheet if some rendered output contains a
	 * gauge. Pages without one (or with only an invalid-site comment) load no
	 * plugin CSS. Late enqueues print in the footer.
	 *
	 * @param string $html Rendered gauge HTML.
	 */
	public static function enqueue_style_for( string $html ): void {
		if ( str_contains( $html, 'rivcon-gauge' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}

	/**
	 * Activation.
	 */
	public static function activate(): void {
		add_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		CacheWarmer::schedule();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate(): void {
		CacheWarmer::unschedule();
	}

	/**
	 * Placeholder for future migrations: compare the stored schema version
	 * with DB_VERSION and migrate options step by step.
	 */
	private static function maybe_upgrade(): void {
		$stored = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $stored >= self::DB_VERSION ) {
			return;
		}
		// v1 has nothing to migrate.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
