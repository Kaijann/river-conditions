<?php
/**
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use RiverConditions\Render\Sparkline;

final class SparklineTest extends \PHPUnit\Framework\TestCase {

	public function test_null_for_fewer_than_two_points(): void {
		$this->assertNull( Sparkline::build( array() ) );
		$this->assertNull( Sparkline::build( array( array( 0, 1.0 ) ) ) );
	}

	public function test_scales_into_the_viewbox(): void {
		$spark = Sparkline::build(
			array(
				array( 0, 100.0 ),
				array( 50, 200.0 ),
				array( 100, 150.0 ),
			)
		);
		$this->assertNotNull( $spark );
		// Min at the bottom pad (64 - 6), max at the top pad (6), x by time.
		$this->assertSame( 'M0,58 L150,6 L300,32', $spark['line'] );
		$this->assertSame( 'M0,58 L150,6 L300,32 L300,64 L0,64 Z', $spark['area'] );
		$this->assertSame( 300.0, $spark['last_x'] );
		$this->assertSame( 32.0, $spark['last_y'] );
		$this->assertSame( 100.0, $spark['min'] );
		$this->assertSame( 200.0, $spark['max'] );
	}

	public function test_flat_series_sits_in_the_middle(): void {
		$spark = Sparkline::build( array( array( 0, 5.0 ), array( 10, 5.0 ) ) );
		$this->assertNotNull( $spark );
		$this->assertSame( 'M0,32 L300,32', $spark['line'] );
	}

	public function test_time_gaps_stay_visible(): void {
		// Three points, the middle one is close to the end in time.
		$spark = Sparkline::build( array( array( 0, 1.0 ), array( 90, 2.0 ), array( 100, 1.0 ) ) );
		$this->assertNotNull( $spark );
		$this->assertStringContainsString( 'L270,', $spark['line'] );
	}

	public function test_downsample_keeps_first_and_last(): void {
		$series = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$series[] = array( $i * 300, (float) $i );
		}
		$out = Sparkline::downsample( $series, 96 );
		$this->assertCount( 96, $out );
		$this->assertSame( $series[0], $out[0] );
		$this->assertSame( $series[1999], $out[95] );
	}

	public function test_long_series_produce_small_markup(): void {
		$series = array();
		for ( $i = 0; $i < 2016; $i++ ) {
			$series[] = array( $i * 300, 400 + 50 * sin( $i / 100 ) );
		}
		$spark = Sparkline::build( $series );
		$this->assertNotNull( $spark );
		$this->assertSame( Sparkline::MAX_POINTS, substr_count( $spark['line'], ',' ) );
		$this->assertLessThan( 1600, strlen( $spark['line'] ) );
	}
}
