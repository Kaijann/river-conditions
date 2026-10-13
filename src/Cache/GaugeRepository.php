<?php
/**
 * Cached access to gauge readings.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Cache;

use RiverConditions\Api\UsgsClient;
use RiverConditions\Api\UsgsException;
use RiverConditions\Domain\GaugeReading;

/**
 * Reads gauges through two transients per site:
 *
 * - rivcon_{hash}      the normal cache, TTL from settings;
 * - rivcon_lkg_{hash}  the last known good reading, kept 48 hours, served
 *                      (as stale) when USGS is down.
 *
 * A short lock transient stops concurrent page views from all fetching the
 * same site at once. The hash includes a "generation" number so Clear cache
 * works even with a persistent object cache, where transients are not rows in
 * wp_options and cannot be deleted by prefix.
 */
class GaugeRepository {

	public const LKG_TTL    = 2 * DAY_IN_SECONDS;
	public const LOCK_TTL   = 30;
	public const LOC_TTL    = DAY_IN_SECONDS;
	public const GEN_OPTION = 'rivcon_cache_generation';

	/** Site IDs: "USGS-" plus 8 to 15 digits. */
	public const SITE_PATTERN = '/^USGS-\d{8,15}$/';

	/**
	 * Constructor.
	 *
	 * @param UsgsClient $client USGS client.
	 * @param int        $ttl    Normal cache lifetime in seconds.
	 */
	public function __construct(
		private readonly UsgsClient $client,
		private readonly int $ttl,
	) {}

	/**
	 * Whether a site ID is well formed.
	 *
	 * @param string $site_id Site ID.
	 */
	public static function is_valid_id( string $site_id ): bool {
		return 1 === preg_match( self::SITE_PATTERN, $site_id );
	}

	/**
	 * Get a gauge, from cache when possible.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 */
	public function get( string $site_id ): GaugeResult {
		if ( ! self::is_valid_id( $site_id ) ) {
			return new GaugeResult( GaugeResult::INVALID, $site_id );
		}

		$cached = get_transient( $this->key( $site_id ) );
		if ( is_array( $cached ) ) {
			if ( ! empty( $cached['invalid'] ) ) {
				return new GaugeResult( GaugeResult::INVALID, $site_id );
			}
			$reading = GaugeReading::from_array( $cached );
			if ( null !== $reading ) {
				return new GaugeResult( GaugeResult::OK, $site_id, $reading );
			}
		}

		// Another request is fetching this site right now. Serve the last known
		// good reading as-is (its age still decides whether it shows as stale).
		if ( false !== get_transient( $this->lock_key( $site_id ) ) ) {
			$reading = GaugeReading::from_array( get_transient( $this->lkg_key( $site_id ) ) );
			return null === $reading
				? new GaugeResult( GaugeResult::UNAVAILABLE, $site_id )
				: new GaugeResult( GaugeResult::OK, $site_id, $reading );
		}

		return $this->refresh( $site_id );
	}

	/**
	 * Fetch from USGS now and update both caches. Used on cache misses and by
	 * the cache-warming cron.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 */
	public function refresh( string $site_id ): GaugeResult {
		if ( ! self::is_valid_id( $site_id ) ) {
			return new GaugeResult( GaugeResult::INVALID, $site_id );
		}

		set_transient( $this->lock_key( $site_id ), 1, self::LOCK_TTL );
		try {
			$reading = $this->fetch( $site_id );
		} catch ( UsgsException $e ) {
			return $this->fallback( $site_id );
		} finally {
			delete_transient( $this->lock_key( $site_id ) );
		}

		if ( null === $reading ) {
			// Unknown site: remember that briefly so we don't ask again on every view.
			set_transient( $this->key( $site_id ), array( 'invalid' => true ), $this->ttl );
			return new GaugeResult( GaugeResult::INVALID, $site_id );
		}

		$data = $reading->to_array();
		set_transient( $this->key( $site_id ), $data, $this->ttl );
		set_transient( $this->lkg_key( $site_id ), $data, self::LKG_TTL );
		return new GaugeResult( GaugeResult::OK, $site_id, $reading );
	}

	/**
	 * Look up a site's name and state (for editor validation). Cached a day.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 * @return array{id: string, name: string, state: string}|null Null when USGS has no such site.
	 * @throws UsgsException When USGS cannot be reached.
	 */
	public function location( string $site_id ): ?array {
		$key    = 'rivcon_loc_' . md5( $this->generation() . '|' . $site_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['name'] ) ) {
			return array(
				'id'    => (string) ( $cached['id'] ?? $site_id ),
				'name'  => (string) $cached['name'],
				'state' => (string) ( $cached['state'] ?? '' ),
			);
		}
		$location = $this->client->location( $site_id );
		if ( null !== $location ) {
			set_transient( $key, $location, self::LOC_TTL );
		}
		return $location;
	}

	/**
	 * Invalidate every cached reading by bumping the cache generation, and
	 * delete database-stored transients so they don't linger.
	 */
	public static function flush(): void {
		update_option( self::GEN_OPTION, (int) get_option( self::GEN_OPTION, 0 ) + 1, false );
		self::delete_stored_transients();
	}

	/**
	 * Delete all rivcon_ transients stored in wp_options.
	 */
	public static function delete_stored_transients(): void {
		global $wpdb;
		// No WP API deletes transients by prefix; this is the standard pattern.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_rivcon_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_rivcon_' ) . '%'
			)
		);
	}

	/**
	 * Fetch and assemble a reading. Null when the site does not exist.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 * @throws UsgsException On network or API errors.
	 */
	private function fetch( string $site_id ): ?GaugeReading {
		$location = $this->location( $site_id );
		if ( null === $location ) {
			return null;
		}

		$latest = $this->client->latest( $site_id );

		// The sparkline and trend are nice to have; a failure here must not hide the reading.
		$series = array();
		if ( isset( $latest[ GaugeReading::PARAM_FLOW ] ) ) {
			try {
				$series = $this->client->flow_series( $site_id );
			} catch ( UsgsException $e ) {
				$series = array();
			}
		}

		return new GaugeReading(
			$site_id,
			$location['name'],
			$location['state'],
			$latest[ GaugeReading::PARAM_FLOW ] ?? null,
			$latest[ GaugeReading::PARAM_GAGE_HEIGHT ] ?? null,
			$latest[ GaugeReading::PARAM_WATER_TEMP ] ?? null,
			$series,
			time(),
		);
	}

	/**
	 * Serve the last known good reading, or "unavailable".
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 */
	private function fallback( string $site_id ): GaugeResult {
		$reading = GaugeReading::from_array( get_transient( $this->lkg_key( $site_id ) ) );
		if ( null === $reading ) {
			return new GaugeResult( GaugeResult::UNAVAILABLE, $site_id );
		}
		return new GaugeResult( GaugeResult::FALLBACK, $site_id, $reading );
	}

	/**
	 * Normal cache key.
	 *
	 * @param string $site_id Site ID.
	 */
	private function key( string $site_id ): string {
		return 'rivcon_' . md5( $this->generation() . '|' . $site_id );
	}

	/**
	 * Last-known-good key.
	 *
	 * @param string $site_id Site ID.
	 */
	private function lkg_key( string $site_id ): string {
		return 'rivcon_lkg_' . md5( $this->generation() . '|' . $site_id );
	}

	/**
	 * Lock key. Not generational: a flush should not allow a second fetch.
	 *
	 * @param string $site_id Site ID.
	 */
	private function lock_key( string $site_id ): string {
		return 'rivcon_lock_' . md5( $site_id );
	}

	/**
	 * Current cache generation.
	 */
	private function generation(): int {
		return (int) get_option( self::GEN_OPTION, 0 );
	}
}
