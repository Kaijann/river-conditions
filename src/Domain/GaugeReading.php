<?php
/**
 * Normalized snapshot of one gauge.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Domain;

/**
 * Everything the renderer needs for one site. Immutable.
 *
 * This is also the shape stored in the cache (via to_array), so the cache
 * never holds raw USGS JSON.
 */
final class GaugeReading {

	public const PARAM_FLOW        = '00060';
	public const PARAM_GAGE_HEIGHT = '00065';
	public const PARAM_WATER_TEMP  = '00010';

	/**
	 * Constructor.
	 *
	 * @param string                     $site_id     USGS-prefixed site ID.
	 * @param string                     $name        Site name from USGS.
	 * @param string                     $state       State name, may be empty.
	 * @param Measurement|null           $flow        Latest discharge (ft³/s).
	 * @param Measurement|null           $gage_height Latest gage height (ft).
	 * @param Measurement|null           $water_temp  Latest water temperature (°C).
	 * @param list<array{0:int,1:float}> $flow_series 7-day discharge series, [time, value], oldest first.
	 * @param int                        $fetched_at  When we fetched this from USGS.
	 */
	public function __construct(
		public readonly string $site_id,
		public readonly string $name,
		public readonly string $state,
		public readonly ?Measurement $flow,
		public readonly ?Measurement $gage_height,
		public readonly ?Measurement $water_temp,
		public readonly array $flow_series,
		public readonly int $fetched_at,
	) {}

	/**
	 * Time of the newest reading we use for freshness: flow if present,
	 * otherwise the newest of any parameter. Null when nothing was reported.
	 */
	public function latest_time(): ?int {
		if ( null !== $this->flow ) {
			return $this->flow->time;
		}
		$times = array_filter(
			array(
				$this->gage_height?->time,
				$this->water_temp?->time,
			),
			static fn ( ?int $t ): bool => null !== $t
		);
		return $times ? max( $times ) : null;
	}

	/**
	 * True when any displayed value is provisional.
	 */
	public function is_provisional(): bool {
		foreach ( array( $this->flow, $this->gage_height, $this->water_temp ) as $m ) {
			if ( null !== $m && $m->provisional ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Serialize for the cache.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'site_id'     => $this->site_id,
			'name'        => $this->name,
			'state'       => $this->state,
			'flow'        => $this->flow?->to_array(),
			'gage_height' => $this->gage_height?->to_array(),
			'water_temp'  => $this->water_temp?->to_array(),
			'flow_series' => $this->flow_series,
			'fetched_at'  => $this->fetched_at,
		);
	}

	/**
	 * Rebuild from the cache. Returns null if the array is not a reading.
	 *
	 * @param mixed $data Cached array.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || empty( $data['site_id'] ) || ! is_string( $data['site_id'] ) ) {
			return null;
		}
		$series = array();
		foreach ( (array) ( $data['flow_series'] ?? array() ) as $point ) {
			if ( is_array( $point ) && isset( $point[0], $point[1] ) ) {
				$series[] = array( (int) $point[0], (float) $point[1] );
			}
		}
		return new self(
			$data['site_id'],
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['state'] ?? '' ),
			Measurement::from_array( $data['flow'] ?? null ),
			Measurement::from_array( $data['gage_height'] ?? null ),
			Measurement::from_array( $data['water_temp'] ?? null ),
			$series,
			(int) ( $data['fetched_at'] ?? 0 ),
		);
	}
}
