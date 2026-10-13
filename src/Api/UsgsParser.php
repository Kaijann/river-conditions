<?php
/**
 * USGS GeoJSON -> normalized arrays.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Api;

use RiverConditions\Domain\Measurement;

/**
 * Pure parser for USGS Water Data OGC API responses. No WordPress calls.
 *
 * Every method accepts the decoded JSON body (an array) and tolerates
 * missing or malformed fields: bad features are skipped, never fatal.
 */
final class UsgsParser {

	/** USGS statistic code for instantaneous values (preferred). */
	public const STAT_INSTANTANEOUS = '00011';

	/**
	 * Parse a monitoring-locations response.
	 *
	 * @param array<mixed> $body Decoded GeoJSON FeatureCollection.
	 * @return array{id: string, name: string, state: string}|null Null when the site does not exist.
	 */
	public static function location( array $body ): ?array {
		foreach ( self::properties( $body ) as $props ) {
			$id   = self::string( $props['id'] ?? $props['monitoring_location_id'] ?? null );
			$name = self::string( $props['monitoring_location_name'] ?? null );
			if ( '' === $name ) {
				continue;
			}
			return array(
				'id'    => $id,
				'name'  => $name,
				'state' => self::string( $props['state_name'] ?? null ),
			);
		}
		return null;
	}

	/**
	 * Parse a latest-continuous response into one measurement per parameter.
	 *
	 * A site can report several time series for one parameter (e.g. two
	 * temperature sensors, or a daily mean next to instantaneous values).
	 * We prefer instantaneous values, then the most recent reading.
	 *
	 * @param array<mixed> $body Decoded GeoJSON FeatureCollection.
	 * @return array<string, Measurement> Keyed by parameter code.
	 */
	public static function latest( array $body ): array {
		$best = array();
		$rank = array();
		foreach ( self::properties( $body ) as $props ) {
			$code        = self::string( $props['parameter_code'] ?? null );
			$measurement = self::measurement( $props );
			if ( '' === $code || null === $measurement ) {
				continue;
			}
			$score = array(
				self::STAT_INSTANTANEOUS === self::string( $props['statistic_id'] ?? null ) ? 1 : 0,
				$measurement->time,
			);
			if ( ! isset( $rank[ $code ] ) || $score > $rank[ $code ] ) {
				$rank[ $code ] = $score;
				$best[ $code ] = $measurement;
			}
		}
		return $best;
	}

	/**
	 * Parse a continuous response into a [time, value] series, oldest first.
	 *
	 * @param array<mixed> $body Decoded GeoJSON FeatureCollection.
	 * @return list<array{0:int,1:float}>
	 */
	public static function series( array $body ): array {
		$points = array();
		foreach ( self::properties( $body ) as $props ) {
			$time  = self::time( $props['time'] ?? null );
			$value = $props['value'] ?? null;
			if ( null === $time || ! is_numeric( $value ) ) {
				continue;
			}
			// Duplicate timestamps (overlapping series) collapse to one point.
			$points[ $time ] = (float) $value;
		}
		ksort( $points );
		$series = array();
		foreach ( $points as $time => $value ) {
			$series[] = array( (int) $time, $value );
		}
		return $series;
	}

	/**
	 * The URL of the next page, if the response has one.
	 *
	 * @param array<mixed> $body Decoded GeoJSON FeatureCollection.
	 */
	public static function next_link( array $body ): ?string {
		foreach ( (array) ( $body['links'] ?? array() ) as $link ) {
			if ( is_array( $link ) && 'next' === ( $link['rel'] ?? null ) && is_string( $link['href'] ?? null ) ) {
				return $link['href'];
			}
		}
		return null;
	}

	/**
	 * Build a Measurement from one feature's properties.
	 *
	 * @param array<mixed> $props Feature properties.
	 */
	private static function measurement( array $props ): ?Measurement {
		$value = $props['value'] ?? null;
		$time  = self::time( $props['time'] ?? null );
		// USGS sends value as a string; non-numeric values ("Ice", "Eqp", null) are skipped.
		if ( ! is_numeric( $value ) || null === $time ) {
			return null;
		}
		$status      = strtolower( self::string( $props['approval_status'] ?? $props['approvals_status'] ?? null ) );
		$provisional = ( 'provisional' === $status );
		return new Measurement(
			(float) $value,
			self::string( $props['unit_of_measure'] ?? null ),
			$time,
			$provisional,
		);
	}

	/**
	 * Yield the properties array of every feature.
	 *
	 * @param array<mixed> $body Decoded GeoJSON FeatureCollection.
	 * @return list<array<mixed>>
	 */
	private static function properties( array $body ): array {
		$out = array();
		foreach ( (array) ( $body['features'] ?? array() ) as $feature ) {
			if ( is_array( $feature ) && is_array( $feature['properties'] ?? null ) ) {
				$props = $feature['properties'];
				// The feature id is outside properties in GeoJSON.
				if ( ! isset( $props['id'] ) && isset( $feature['id'] ) ) {
					$props['id'] = $feature['id'];
				}
				$out[] = $props;
			}
		}
		return $out;
	}

	/**
	 * Parse an ISO 8601 time to a Unix timestamp.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function time( mixed $value ): ?int {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Coerce a scalar to a trimmed string.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function string( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
