<?php
/**
 * Optional cache warming via WP-Cron.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions;

use RiverConditions\Blocks\GaugeBlock;
use RiverConditions\Cache\GaugeRepository;

/**
 * Tracks which sites published content uses (on save_post) and refreshes
 * their caches on a schedule, so visitors rarely wait on USGS.
 */
final class CacheWarmer {

	public const HOOK     = 'rivcon_warm_cache';
	public const SCHEDULE = 'rivcon_cache_ttl';
	public const OPTION   = 'rivcon_used_sites';

	/** Forget sites not seen in published content for this long. */
	public const FORGET_AFTER = 30 * DAY_IN_SECONDS;

	/** Upper bound per run, to keep cron fast and polite to USGS. */
	public const MAX_SITES = 25;

	/**
	 * Constructor.
	 *
	 * @param GaugeRepository $repository Repository.
	 */
	public function __construct( private readonly GaugeRepository $repository ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval -- interval is the cache TTL, 5–120 min.
		add_action( 'save_post', array( $this, 'track' ), 10, 2 );
		add_action( self::HOOK, array( $this, 'warm' ) );
	}

	/**
	 * Add a schedule matching the cache TTL.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => Settings::cache_ttl(),
			'display'  => __( 'River Conditions cache lifetime', 'river-conditions' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the event (activation).
	 */
	public static function schedule(): void {
		// On activation the plugin was loaded after plugins_loaded, so register() has
		// not run yet; without the schedule, wp_schedule_event() would silently fail.
		if ( ! has_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ) ) {
			add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval -- interval is the cache TTL, 5–120 min.
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Remove the event (deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Record sites used in a published post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function track( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$ids = array_merge( GaugeBlock::site_ids_in( $post->post_content ), Shortcode::site_ids_in( $post->post_content ) );
		$ids = array_filter( $ids, array( GaugeRepository::class, 'is_valid_id' ) );
		if ( ! $ids ) {
			return;
		}
		$used = self::used();
		foreach ( $ids as $id ) {
			$used[ $id ] = time();
		}
		update_option( self::OPTION, $used, false );
	}

	/**
	 * Cron callback: refresh recently used sites.
	 */
	public function warm(): void {
		$used   = self::used();
		$cutoff = time() - self::FORGET_AFTER;
		$used   = array_filter( $used, static fn ( int $seen ): bool => $seen >= $cutoff );
		arsort( $used );
		update_option( self::OPTION, $used, false );

		foreach ( array_slice( array_keys( $used ), 0, self::MAX_SITES ) as $site_id ) {
			$this->repository->refresh( (string) $site_id );
		}
	}

	/**
	 * Tracked sites: site ID => last seen.
	 *
	 * @return array<string, int>
	 */
	private static function used(): array {
		$used = get_option( self::OPTION, array() );
		if ( ! is_array( $used ) ) {
			return array();
		}
		$out = array();
		foreach ( $used as $id => $seen ) {
			$out[ (string) $id ] = (int) $seen;
		}
		return $out;
	}
}
