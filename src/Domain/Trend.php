<?php
/**
 * Flow trend calculation.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Domain;

/**
 * Pure: compares the latest flow with the value about six hours earlier.
 */
final class Trend {

	public const RISING  = 'rising';
	public const FALLING = 'falling';
	public const STEADY  = 'steady';

	/** Relative change that counts as rising or falling (5%). */
	public const THRESHOLD = 0.05;

	/** How far back to look, in seconds. */
	public const LOOKBACK = 6 * 3600;

	/**
	 * The comparison point must be within this many seconds of the target
	 * time, otherwise a gap in the data would produce a misleading trend.
	 */
	public const TOLERANCE = 2 * 3600;

	/**
	 * Calculate the trend of a series.
	 *
	 * @param list<array{0:int,1:float}> $series [time, value] pairs, any order.
	 * @return string|null One of the class constants, or null when there is not enough data.
	 */
	public static function from_series( array $series ): ?string {
		if ( count( $series ) < 2 ) {
			return null;
		}
		usort( $series, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );

		$latest = $series[ count( $series ) - 1 ];
		$target = $latest[0] - self::LOOKBACK;

		$earlier  = null;
		$best_gap = PHP_INT_MAX;
		foreach ( $series as $point ) {
			if ( $point[0] >= $latest[0] ) {
				continue;
			}
			$gap = abs( $point[0] - $target );
			if ( $gap < $best_gap ) {
				$best_gap = $gap;
				$earlier  = $point;
			}
		}

		if ( null === $earlier || $best_gap > self::TOLERANCE ) {
			return null;
		}

		return self::classify( $earlier[1], $latest[1] );
	}

	/**
	 * Classify a change from one value to another.
	 *
	 * @param float $before Earlier value.
	 * @param float $after  Later value.
	 */
	public static function classify( float $before, float $after ): string {
		if ( 0.0 === $before ) {
			if ( 0.0 === $after ) {
				return self::STEADY;
			}
			return $after > 0 ? self::RISING : self::FALLING;
		}
		$change = ( $after - $before ) / abs( $before );
		if ( $change > self::THRESHOLD ) {
			return self::RISING;
		}
		if ( $change < -self::THRESHOLD ) {
			return self::FALLING;
		}
		return self::STEADY;
	}
}
