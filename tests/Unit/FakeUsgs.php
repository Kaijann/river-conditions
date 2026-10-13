<?php
/**
 * In-memory stand-ins for USGS HTTP and WordPress transients.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * A fake wp_remote_get keyed by collection name, plus a transient store.
 */
final class FakeUsgs {

	/** @var array<string, array<mixed>|int|\WP_Error> Collection => body, HTTP status, or error. */
	public array $responses = array();

	/** @var list<string> Requested URLs. */
	public array $requests = array();

	/** @var array<string, mixed> Transients. */
	public array $transients = array();

	/** @var array<string, mixed> Options. */
	public array $options = array();

	public function http(): \Closure {
		return function ( string $url ) {
			$this->requests[] = $url;
			preg_match( '#/collections/([^/]+)/items#', $url, $m );
			$response = $this->responses[ $m[1] ?? '' ] ?? 404;
			if ( $response instanceof \WP_Error ) {
				return $response;
			}
			if ( is_int( $response ) ) {
				return array(
					'response' => array( 'code' => $response ),
					'body'     => '',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( $response ),
			);
		};
	}

	/**
	 * Stub the WordPress transient and option functions onto this store.
	 */
	public function stub_wordpress(): void {
		Functions\when( 'get_transient' )->alias( fn ( string $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $k, $v ): bool {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $k ): bool {
				unset( $this->transients[ $k ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn ( string $k, $d = false ) => $this->options[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias(
			function ( string $k, $v ): bool {
				$this->options[ $k ] = $v;
				return true;
			}
		);
	}

	/**
	 * Count requests to one collection.
	 */
	public function count( string $collection ): int {
		return count( array_filter( $this->requests, static fn ( string $u ): bool => str_contains( $u, "/collections/{$collection}/" ) ) );
	}

	/**
	 * A healthy gauge with flow, gage height and temperature.
	 */
	public function healthy_site( int $now ): void {
		$time                                    = gmdate( 'c', $now - 25 * 60 );
		$this->responses['monitoring-locations'] = array(
			'features' => array(
				array(
					'id'         => 'USGS-06037500',
					'properties' => array(
						'monitoring_location_name' => 'Madison River near West Yellowstone',
						'state_name'               => 'Montana',
					),
				),
			),
		);
		$this->responses['latest-continuous']    = array(
			'features' => array(
				self::feature( '00060', '538', 'ft^3/s', $time, 'Provisional' ),
				self::feature( '00065', '2.41', 'ft', $time, 'Provisional' ),
				self::feature( '00010', '9.2', 'degC', $time, 'Provisional' ),
			),
		);
		$features                                = array();
		for ( $t = $now - 7 * 86400; $t <= $now - 25 * 60; $t += 900 ) {
			// Falling over the last 6 hours: 600 -> 538.
			$value      = $t < $now - 6 * 3600 ? 600 : 538;
			$features[] = array(
				'properties' => array(
					'time'  => gmdate( 'c', $t ),
					'value' => (string) $value,
				),
			);
		}
		$this->responses['continuous'] = array( 'features' => $features );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function feature( string $code, string $value, string $unit, string $time, string $status ): array {
		return array(
			'properties' => array(
				'parameter_code'  => $code,
				'statistic_id'    => '00011',
				'value'           => $value,
				'unit_of_measure' => $unit,
				'time'            => $time,
				'approval_status' => $status,
			),
		);
	}
}
