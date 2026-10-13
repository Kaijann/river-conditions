<?php
/**
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use Brain\Monkey\Functions;
use RiverConditions\Cache\GaugeRepository;
use RiverConditions\Cache\GaugeResult;
use RiverConditions\Domain\GaugeReading;
use RiverConditions\Domain\Measurement;
use RiverConditions\Render\GaugeRenderer;
use RiverConditions\Shortcode;

final class ShortcodeTest extends TestCase {

	public function test_maps_snake_case_to_block_attributes(): void {
		$this->assertSame(
			array(
				'siteId'         => 'USGS-06043500',
				'layout'         => 'compact',
				'showGageHeight' => 'false',
				'tempUnit'       => 'C',
			),
			Shortcode::to_block_attributes(
				array(
					'site'             => 'USGS-06043500',
					'layout'           => 'compact',
					'show_gage_height' => 'false',
					'temp_unit'        => 'C',
					'title'            => null,
				)
			)
		);
	}

	public function test_renders_the_same_markup_as_the_block(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_unique_id' )->justReturn( 'rivcon-prov-1' );
		Functions\when( 'shortcode_atts' )->alias( static fn ( array $pairs, array $atts ): array => array_merge( $pairs, array_intersect_key( $atts, $pairs ) ) );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'river-conditions-gauge' );

		$now     = time();
		$reading = new GaugeReading( 'USGS-06043500', 'Gallatin River near Gallatin Gateway', 'Montana', new Measurement( 1204.0, 'ft^3/s', $now - 600, true ), null, null, array(), $now );
		$repo    = \Mockery::mock( GaugeRepository::class );
		$repo->shouldReceive( 'get' )->andReturn( new GaugeResult( GaugeResult::OK, 'USGS-06043500', $reading ) );
		$renderer = new GaugeRenderer(
			$repo,
			array(
				'temp_unit'        => 'F',
				'show_attribution' => true,
			)
		);

		$shortcode = ( new Shortcode( $renderer ) )->render(
			array(
				'site'   => 'USGS-06043500',
				'layout' => 'compact',
			)
		);
		$block     = $renderer->render(
			array(
				'siteId' => 'USGS-06043500',
				'layout' => 'compact',
			)
		);

		$this->assertSame( $block, $shortcode );
		$this->assertStringContainsString( '1,204', $shortcode );
	}
}
