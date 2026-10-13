<?php
/**
 * Number, unit and age formatting.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Render;

/**
 * Pure formatting helpers. No WordPress calls; translation happens in the
 * renderer, which passes in locale separators and wraps the results.
 */
final class Format {

	/**
	 * Discharge: integer with a thousands separator ("1,204").
	 *
	 * @param float  $cfs       Cubic feet per second.
	 * @param string $thousands Thousands separator.
	 */
	public static function flow( float $cfs, string $thousands = ',' ): string {
		return number_format( $cfs, 0, '.', $thousands );
	}

	/**
	 * Gage height: two decimals ("2.41").
	 *
	 * @param float  $feet    Height in feet.
	 * @param string $decimal Decimal point.
	 */
	public static function gage_height( float $feet, string $decimal = '.' ): string {
		return number_format( $feet, 2, $decimal, '' );
	}

	/**
	 * Water temperature in the requested unit, one decimal ("48.6").
	 *
	 * @param float  $celsius Temperature in °C, as USGS reports it.
	 * @param string $unit    "F" or "C".
	 * @param string $decimal Decimal point.
	 */
	public static function temperature( float $celsius, string $unit, string $decimal = '.' ): string {
		$value = 'C' === $unit ? $celsius : self::celsius_to_fahrenheit( $celsius );
		return number_format( $value, 1, $decimal, '' );
	}

	/**
	 * Convert °C to °F.
	 *
	 * @param float $celsius Temperature in °C.
	 */
	public static function celsius_to_fahrenheit( float $celsius ): float {
		return $celsius * 9 / 5 + 32;
	}

	/**
	 * Tidy a USGS site name for display.
	 *
	 * The v1 API returns mixed-case names ending in a state code ("Madison
	 * River near West Yellowstone, MT"); the code is dropped when the state is
	 * shown separately. Some older names are all upper case ("SAN MARCIAL
	 * ADDED"); those become title case with small words lower case.
	 *
	 * @param string $name       Name from USGS.
	 * @param bool   $drop_state Remove a trailing two-letter state code.
	 */
	public static function site_name( string $name, bool $drop_state = true ): string {
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
		if ( '' === $name ) {
			return $name;
		}

		// Mixed-case names (what the v1 API returns, e.g. "Madison River near
		// West Yellowstone, MT") only lose the trailing state code.
		if ( strtoupper( $name ) !== $name || strtolower( $name ) === $name ) {
			return $drop_state ? (string) preg_replace( '/,\s+[A-Z]{2}\.?$/', '', $name ) : $name;
		}

		$state_code = '';
		if ( 1 === preg_match( '/,?\s+([A-Z]{2})\.?$/', $name, $m ) ) {
			$state_code = $m[1];
			$name       = substr( $name, 0, -strlen( $m[0] ) );
		}

		$small = array( 'a', 'and', 'at', 'above', 'below', 'by', 'for', 'in', 'near', 'nr', 'of', 'on', 'the', 'to' );
		$words = explode( ' ', strtolower( $name ) );
		foreach ( $words as $i => $word ) {
			$bare = rtrim( $word, ',.' );
			if ( 0 !== $i && in_array( $bare, $small, true ) ) {
				continue;
			}
			// Keep road and route codes ("US", "SR", "I-90") and Roman numerals upper case.
			if ( 1 === preg_match( '/^(us|sr|hwy|i-\d+|[ivx]+|\d+[a-z]?)$/', $bare ) && 'i' !== $bare ) {
				$words[ $i ] = strtoupper( $word );
				continue;
			}
			$words[ $i ] = (string) preg_replace_callback(
				'/(^|[-\/(\'])([a-z])/',
				static fn ( array $m ): string => $m[1] . strtoupper( $m[2] ),
				$word
			);
		}
		$name = implode( ' ', $words );
		return ( '' !== $state_code && ! $drop_state ) ? $name . ' ' . $state_code : $name;
	}

	/**
	 * Split an age in seconds into a count and a unit, for "25 min ago".
	 *
	 * Under a minute counts as 0 minutes ("just now" in the renderer).
	 *
	 * @param int $seconds Age in seconds. Negative ages (clock skew) count as 0.
	 * @return array{0:int,1:string} [count, "min"|"hour"|"day"]
	 */
	public static function age( int $seconds ): array {
		$seconds = max( 0, $seconds );
		if ( $seconds < 3600 ) {
			return array( intdiv( $seconds, 60 ), 'min' );
		}
		if ( $seconds < 2 * 86400 ) {
			return array( intdiv( $seconds, 3600 ), 'hour' );
		}
		return array( intdiv( $seconds, 86400 ), 'day' );
	}
}
