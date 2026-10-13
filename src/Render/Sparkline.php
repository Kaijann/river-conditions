<?php
/**
 * Series -> SVG path geometry.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Render;

/**
 * Pure: turns a [time, value] series into SVG path data for a sparkline.
 *
 * X is proportional to time (so gaps in the data stay visible), Y is scaled
 * between the series min and max. The series is downsampled so a 5-minute
 * gauge does not put 2,000 points into the page.
 */
final class Sparkline {

	public const WIDTH      = 300;
	public const HEIGHT     = 64;
	public const PAD        = 6;
	public const MAX_POINTS = 96;

	/**
	 * Build path data.
	 *
	 * @param list<array{0:int,1:float}> $series [time, value] pairs, oldest first.
	 * @return array{line: string, area: string, last_x: float, last_y: float, min: float, max: float}|null
	 *         Null when there are fewer than two points.
	 */
	public static function build( array $series ): ?array {
		$series = self::downsample( $series, self::MAX_POINTS );
		if ( count( $series ) < 2 ) {
			return null;
		}

		$values = array_column( $series, 1 );
		$min    = (float) min( $values );
		$max    = (float) max( $values );
		$t0     = $series[0][0];
		$span_t = max( 1, $series[ count( $series ) - 1 ][0] - $t0 );
		$span_v = $max - $min;
		$usable = self::HEIGHT - 2 * self::PAD;

		$coords = array();
		foreach ( $series as $point ) {
			$x = ( $point[0] - $t0 ) / $span_t * self::WIDTH;
			// A flat line sits in the middle rather than on an edge.
			$y        = $span_v > 0
				? self::PAD + ( 1 - ( $point[1] - $min ) / $span_v ) * $usable
				: self::HEIGHT / 2;
			$coords[] = array( round( $x, 1 ), round( $y, 1 ) );
		}

		$parts = array();
		foreach ( $coords as $i => $c ) {
			$parts[] = ( 0 === $i ? 'M' : 'L' ) . self::num( $c[0] ) . ',' . self::num( $c[1] );
		}
		$line = implode( ' ', $parts );
		$last = $coords[ count( $coords ) - 1 ];
		$area = $line . ' L' . self::num( $last[0] ) . ',' . self::HEIGHT . ' L' . self::num( $coords[0][0] ) . ',' . self::HEIGHT . ' Z';

		return array(
			'line'   => $line,
			'area'   => $area,
			'last_x' => $last[0],
			'last_y' => $last[1],
			'min'    => $min,
			'max'    => $max,
		);
	}

	/**
	 * Keep at most $max points, evenly spaced, always keeping the first
	 * and last so the line starts and ends on real readings.
	 *
	 * @param list<array{0:int,1:float}> $series Series.
	 * @param int                        $max    Maximum number of points (>= 2).
	 * @return list<array{0:int,1:float}>
	 */
	public static function downsample( array $series, int $max ): array {
		$count = count( $series );
		if ( $count <= $max ) {
			return $series;
		}
		$out  = array();
		$step = ( $count - 1 ) / ( $max - 1 );
		for ( $i = 0; $i < $max; $i++ ) {
			$out[] = $series[ (int) round( $i * $step ) ];
		}
		return $out;
	}

	/**
	 * Compact number for path data ("12" instead of "12.0").
	 *
	 * @param float $n Number.
	 */
	private static function num( float $n ): string {
		return rtrim( rtrim( number_format( $n, 1, '.', '' ), '0' ), '.' );
	}
}
