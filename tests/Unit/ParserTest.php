<?php
/**
 * Parser behaviour on edge cases. Shapes follow the USGS OGC API docs;
 * FixtureParserTest runs the same parser over real saved responses.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use RiverConditions\Api\UsgsParser;

final class ParserTest extends TestCase {

	public function test_location_reads_name_and_state(): void {
		$body = array(
			'type'     => 'FeatureCollection',
			'features' => array(
				array(
					'type'       => 'Feature',
					'id'         => 'USGS-06037500',
					'properties' => array(
						'monitoring_location_name' => 'MADISON RIVER NEAR WEST YELLOWSTONE, MT',
						'state_name'               => 'Montana',
					),
				),
			),
		);
		$this->assertSame(
			array(
				'id'    => 'USGS-06037500',
				'name'  => 'MADISON RIVER NEAR WEST YELLOWSTONE, MT',
				'state' => 'Montana',
			),
			UsgsParser::location( $body )
		);
	}

	public function test_location_null_for_empty_collection(): void {
		$this->assertNull( UsgsParser::location( self::collection( array() ) ) );
		$this->assertNull( UsgsParser::location( array() ) );
	}

	public function test_latest_casts_string_values_and_flags_provisional(): void {
		$latest = UsgsParser::latest(
			self::collection(
				array(
					array(
						'parameter_code'  => '00060',
						'statistic_id'    => '00011',
						'value'           => '538',
						'unit_of_measure' => 'ft^3/s',
						'time'            => '2026-10-13T14:15:00+00:00',
						'approval_status' => 'Provisional',
					),
					array(
						'parameter_code'  => '00065',
						'statistic_id'    => '00011',
						'value'           => '2.41',
						'unit_of_measure' => 'ft',
						'time'            => '2026-10-13T14:15:00Z',
						'approval_status' => 'Approved',
					),
				)
			)
		);

		$this->assertSame( 538.0, $latest['00060']->value );
		$this->assertSame( 'ft^3/s', $latest['00060']->unit );
		$this->assertSame( 1791900900, $latest['00060']->time );
		$this->assertTrue( $latest['00060']->provisional );
		$this->assertSame( 2.41, $latest['00065']->value );
		$this->assertFalse( $latest['00065']->provisional );
		$this->assertArrayNotHasKey( '00010', $latest, 'Missing parameters are absent, not zero.' );
	}

	public function test_latest_skips_non_numeric_values(): void {
		$latest = UsgsParser::latest(
			self::collection(
				array(
					array(
						'parameter_code' => '00060',
						'value'          => 'Ice',
						'time'           => '2026-01-10T12:00:00Z',
					),
					array(
						'parameter_code' => '00065',
						'value'          => null,
						'time'           => '2026-01-10T12:00:00Z',
					),
					array(
						'parameter_code' => '00010',
						'value'          => '4.5',
						'time'           => 'not a time',
					),
				)
			)
		);
		$this->assertSame( array(), $latest );
	}

	public function test_latest_prefers_instantaneous_then_newest_series(): void {
		$latest = UsgsParser::latest(
			self::collection(
				array(
					array(
						'parameter_code' => '00010',
						'statistic_id'   => '00003',
						'value'          => '99',
						'time'           => '2026-10-13T15:00:00Z',
					),
					array(
						'parameter_code' => '00010',
						'statistic_id'   => '00011',
						'value'          => '9.1',
						'time'           => '2026-10-13T13:00:00Z',
					),
					array(
						'parameter_code' => '00010',
						'statistic_id'   => '00011',
						'value'          => '9.2',
						'time'           => '2026-10-13T14:00:00Z',
					),
				)
			)
		);
		$this->assertSame( 9.2, $latest['00010']->value );
	}

	public function test_series_sorts_dedupes_and_skips_bad_points(): void {
		$series = UsgsParser::series(
			self::collection(
				array(
					array(
						'time'  => '2026-10-13T14:30:00Z',
						'value' => '530',
					),
					array(
						'time'  => '2026-10-13T14:00:00Z',
						'value' => '540',
					),
					array(
						'time'  => '2026-10-13T14:00:00Z',
						'value' => '541',
					),
					array(
						'time'  => '2026-10-13T14:15:00Z',
						'value' => 'Eqp',
					),
				)
			)
		);
		$this->assertSame(
			array(
				array( 1791900000, 541.0 ),
				array( 1791901800, 530.0 ),
			),
			$series
		);
	}

	public function test_next_link(): void {
		$body = array(
			'links' => array(
				array(
					'rel'  => 'self',
					'href' => 'https://example.test/a',
				),
				array(
					'rel'  => 'next',
					'href' => 'https://example.test/b',
				),
			),
		);
		$this->assertSame( 'https://example.test/b', UsgsParser::next_link( $body ) );
		$this->assertNull( UsgsParser::next_link( array( 'links' => array() ) ) );
	}
}
