<?php
/**
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RiverConditions\Domain\Trend;

final class TrendTest extends \PHPUnit\Framework\TestCase {

	private const NOW = 1_760_000_000;

	/**
	 * @return array<string, array{float, float, string}>
	 */
	public static function changes(): array {
		return array(
			'up 10%'          => array( 100.0, 110.0, Trend::RISING ),
			'up exactly 5%'   => array( 100.0, 105.0, Trend::STEADY ),
			'up 5.1%'         => array( 100.0, 105.1, Trend::RISING ),
			'down 10%'        => array( 100.0, 90.0, Trend::FALLING ),
			'down exactly 5%' => array( 100.0, 95.0, Trend::STEADY ),
			'flat'            => array( 538.0, 538.0, Trend::STEADY ),
			'from zero up'    => array( 0.0, 3.0, Trend::RISING ),
			'zero to zero'    => array( 0.0, 0.0, Trend::STEADY ),
		);
	}

	#[DataProvider( 'changes' )]
	public function test_classify( float $before, float $after, string $expected ): void {
		$this->assertSame( $expected, Trend::classify( $before, $after ) );
	}

	public function test_compares_with_point_nearest_six_hours_earlier(): void {
		$series = array();
		// 15-minute data for 12 hours: 400 until 6h ago, then 500.
		for ( $t = self::NOW - 12 * 3600; $t <= self::NOW; $t += 900 ) {
			$series[] = array( $t, $t <= self::NOW - 6 * 3600 ? 400.0 : 500.0 );
		}
		$this->assertSame( Trend::RISING, Trend::from_series( $series ) );
	}

	public function test_ignores_spikes_between_the_two_points(): void {
		$series = array(
			array( self::NOW - 6 * 3600, 500.0 ),
			array( self::NOW - 3 * 3600, 900.0 ),
			array( self::NOW, 510.0 ),
		);
		$this->assertSame( Trend::STEADY, Trend::from_series( $series ) );
	}

	public function test_unsorted_input_is_handled(): void {
		$series = array(
			array( self::NOW, 450.0 ),
			array( self::NOW - 6 * 3600, 538.0 ),
		);
		$this->assertSame( Trend::FALLING, Trend::from_series( $series ) );
	}

	public function test_null_when_too_few_points(): void {
		$this->assertNull( Trend::from_series( array() ) );
		$this->assertNull( Trend::from_series( array( array( self::NOW, 1.0 ) ) ) );
	}

	public function test_null_when_no_point_near_six_hours_ago(): void {
		// Only a 30-minute history: comparing would not be a 6h trend.
		$series = array(
			array( self::NOW - 1800, 100.0 ),
			array( self::NOW, 200.0 ),
		);
		$this->assertNull( Trend::from_series( $series ) );
	}
}
