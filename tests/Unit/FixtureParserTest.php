<?php
/**
 * Runs the parser over real USGS responses saved by bin/fetch-fixtures.sh.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use RiverConditions\Api\UsgsParser;
use RiverConditions\Domain\Trend;
use RiverConditions\Render\Sparkline;

final class FixtureParserTest extends TestCase {

	/**
	 * @return array<mixed>
	 */
	private function require_fixture( string $name ): array {
		$data = self::fixture( $name );
		if ( null === $data ) {
			$this->markTestSkipped( "Fixture {$name}.json not captured yet. Run bin/fetch-fixtures.sh (or the Fixtures workflow)." );
		}
		return $data;
	}

	public function test_location_fixture(): void {
		$location = UsgsParser::location( $this->require_fixture( 'location-06037500' ) );
		$this->assertNotNull( $location );
		$this->assertSame( 'USGS-06037500', $location['id'] );
		$this->assertStringContainsStringIgnoringCase( 'Madison', $location['name'] );
		$this->assertSame( 'Montana', $location['state'] );
	}

	public function test_invalid_site_fixture_has_no_location(): void {
		$this->assertNull( UsgsParser::location( $this->require_fixture( 'location-invalid' ) ) );
	}

	public function test_latest_fixture_has_flow(): void {
		$latest = UsgsParser::latest( $this->require_fixture( 'latest-06037500' ) );
		$this->assertArrayHasKey( '00060', $latest );
		$this->assertGreaterThan( 0, $latest['00060']->value );
		$this->assertGreaterThan( 1_700_000_000, $latest['00060']->time );
	}

	public function test_latest_fixture_with_temperature(): void {
		$latest = UsgsParser::latest( $this->require_fixture( 'latest-with-temp' ) );
		$this->assertArrayHasKey( '00010', $latest );
		$this->assertGreaterThan( -5, $latest['00010']->value, 'Water temperature is in °C.' );
		$this->assertLessThan( 40, $latest['00010']->value, 'Water temperature is in °C.' );
	}

	public function test_series_fixture_feeds_trend_and_sparkline(): void {
		$series = UsgsParser::series( $this->require_fixture( 'continuous-06037500' ) );
		$this->assertGreaterThan( 100, count( $series ), 'Seven days of 15-minute data.' );
		$times  = array_column( $series, 0 );
		$sorted = $times;
		sort( $sorted );
		$this->assertSame( $sorted, $times );
		$this->assertContains( Trend::from_series( $series ), array( Trend::RISING, Trend::FALLING, Trend::STEADY ) );
		$this->assertNotNull( Sparkline::build( $series ) );
	}
}
