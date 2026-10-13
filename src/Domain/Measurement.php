<?php
/**
 * One reading of one parameter.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Domain;

/**
 * A single value with its unit, timestamp and approval status.
 */
final class Measurement {

	/**
	 * Constructor.
	 *
	 * @param float  $value       Numeric value, in the unit USGS reports.
	 * @param string $unit        Unit of measure as reported by USGS (e.g. "ft^3/s").
	 * @param int    $time        Unix timestamp of the reading.
	 * @param bool   $provisional True when USGS marks the value Provisional.
	 */
	public function __construct(
		public readonly float $value,
		public readonly string $unit,
		public readonly int $time,
		public readonly bool $provisional,
	) {}

	/**
	 * Serialize for the cache.
	 *
	 * @return array{value: float, unit: string, time: int, provisional: bool}
	 */
	public function to_array(): array {
		return array(
			'value'       => $this->value,
			'unit'        => $this->unit,
			'time'        => $this->time,
			'provisional' => $this->provisional,
		);
	}

	/**
	 * Rebuild from the cache. Returns null on malformed input.
	 *
	 * @param mixed $data Cached array.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['value'], $data['time'] ) || ! is_numeric( $data['value'] ) ) {
			return null;
		}
		return new self(
			(float) $data['value'],
			(string) ( $data['unit'] ?? '' ),
			(int) $data['time'],
			(bool) ( $data['provisional'] ?? false ),
		);
	}
}
