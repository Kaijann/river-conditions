<?php
/**
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RiverConditions\Render\Format;

final class FormatTest extends \PHPUnit\Framework\TestCase {

	public function test_flow_is_integer_with_thousands_separator(): void {
		$this->assertSame( '538', Format::flow( 538.0 ) );
		$this->assertSame( '1,204', Format::flow( 1204.4 ) );
		$this->assertSame( '1,205', Format::flow( 1204.5 ) );
		$this->assertSame( '12.400', Format::flow( 12400.0, '.' ) );
	}

	public function test_gage_height_has_two_decimals(): void {
		$this->assertSame( '2.41', Format::gage_height( 2.409 ) );
		$this->assertSame( '3.00', Format::gage_height( 3.0 ) );
		$this->assertSame( '3,07', Format::gage_height( 3.07, ',' ) );
	}

	public function test_temperature_converts_to_fahrenheit_by_default_unit(): void {
		$this->assertSame( '48.6', Format::temperature( 9.2, 'F' ) );
		$this->assertSame( '32.0', Format::temperature( 0.0, 'F' ) );
		$this->assertSame( '9.2', Format::temperature( 9.2, 'C' ) );
	}

	public function test_celsius_to_fahrenheit(): void {
		$this->assertEqualsWithDelta( 212.0, Format::celsius_to_fahrenheit( 100.0 ), 0.0001 );
		$this->assertEqualsWithDelta( -40.0, Format::celsius_to_fahrenheit( -40.0 ), 0.0001 );
	}

	/**
	 * @return array<string, array{int, array{0:int,1:string}}>
	 */
	public static function ages(): array {
		return array(
			'just now'   => array( 30, array( 0, 'min' ) ),
			'25 min'     => array( 25 * 60 + 10, array( 25, 'min' ) ),
			'59 min'     => array( 3599, array( 59, 'min' ) ),
			'1 hour'     => array( 3600, array( 1, 'hour' ) ),
			'7 hours'    => array( 7 * 3600 + 100, array( 7, 'hour' ) ),
			'47 hours'   => array( 47 * 3600, array( 47, 'hour' ) ),
			'2 days'     => array( 2 * 86400, array( 2, 'day' ) ),
			'clock skew' => array( -120, array( 0, 'min' ) ),
		);
	}

	/**
	 * @param array{0:int,1:string} $expected
	 */
	#[DataProvider( 'ages' )]
	public function test_age( int $seconds, array $expected ): void {
		$this->assertSame( $expected, Format::age( $seconds ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function names(): array {
		return array(
			'upper with state'   => array( 'MADISON RIVER NEAR WEST YELLOWSTONE MT', 'Madison River near West Yellowstone' ),
			'comma state'        => array( 'GALLATIN RIVER NEAR GALLATIN GATEWAY, MT', 'Gallatin River near Gallatin Gateway' ),
			'abbreviation'       => array( 'YELLOWSTONE R NR CORWIN SPRINGS MT', 'Yellowstone R nr Corwin Springs' ),
			'route code'         => array( 'STILLWATER R AT ST RT 185 OH', 'Stillwater R at St Rt 185' ),
			'hyphen'             => array( 'WILKES-BARRE CREEK AT THE MOUTH PA', 'Wilkes-Barre Creek at the Mouth' ),
			'v1 mixed case'      => array( 'Madison River near West Yellowstone, MT', 'Madison River near West Yellowstone' ),
			'mixed, no state'    => array( 'Clear Creek above Lake', 'Clear Creek above Lake' ),
			'real all-caps name' => array( 'SAN MARCIAL ADDED', 'San Marcial Added' ),
		);
	}

	#[DataProvider( 'names' )]
	public function test_site_name( string $raw, string $expected ): void {
		$this->assertSame( $expected, Format::site_name( $raw ) );
	}

	public function test_site_name_keeps_state_when_asked(): void {
		$this->assertSame( 'Madison River near West Yellowstone MT', Format::site_name( 'MADISON RIVER NEAR WEST YELLOWSTONE MT', false ) );
	}
}
