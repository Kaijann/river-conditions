<?php
/**
 * Base test case: sets up Brain Monkey and common WordPress stubs.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load a JSON fixture saved from the real USGS API.
	 *
	 * @return array<mixed>|null Null when the fixture has not been captured yet.
	 */
	protected static function fixture( string $name ): ?array {
		$path = dirname( __DIR__ ) . '/fixtures/' . $name . '.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Build a GeoJSON FeatureCollection from property arrays.
	 *
	 * @param list<array<string, mixed>> $props Feature properties.
	 * @return array<string, mixed>
	 */
	protected static function collection( array $props ): array {
		return array(
			'type'     => 'FeatureCollection',
			'features' => array_map(
				static fn ( array $p ): array => array(
					'type'       => 'Feature',
					'properties' => $p,
				),
				$props
			),
		);
	}
}
