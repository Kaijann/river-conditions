<?php
/**
 * What the repository hands to the renderer.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Cache;

use RiverConditions\Domain\GaugeReading;

/**
 * A reading plus how we got it.
 */
final class GaugeResult {

	/** Fresh data (from USGS or the normal cache). */
	public const OK = 'ok';

	/** USGS failed; this is the last known good reading. Always shown as stale. */
	public const FALLBACK = 'fallback';

	/** USGS failed and there is nothing cached. */
	public const UNAVAILABLE = 'unavailable';

	/** The site ID is malformed or USGS does not know it. */
	public const INVALID = 'invalid';

	/**
	 * Constructor.
	 *
	 * @param string            $status  One of the class constants.
	 * @param string            $site_id Requested site ID.
	 * @param GaugeReading|null $reading Reading, for OK and FALLBACK.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $site_id,
		public readonly ?GaugeReading $reading = null,
	) {}
}
