<?php
/**
 * Every state from the design reference: ok, no temperature, stale,
 * fallback, unavailable, invalid, compact.
 *
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

final class RendererTest extends TestCase {

	private const NOW  = 1_791_900_900;
	private const SITE = 'USGS-06037500';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_option' )->justReturn( 'g:i a' );
		Functions\when( 'wp_date' )->alias( static fn ( string $format, int $ts ): string => gmdate( $format, $ts ) );
		Functions\when( 'wp_unique_id' )->alias( static fn ( string $prefix ): string => $prefix . '1' );
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function renderer( GaugeResult $result, array $settings = array() ): GaugeRenderer {
		$repo = \Mockery::mock( GaugeRepository::class );
		$repo->shouldReceive( 'get' )->andReturn( $result );
		return new GaugeRenderer(
			$repo,
			$settings + array(
				'temp_unit'        => 'F',
				'show_attribution' => true,
			),
			static fn (): int => self::NOW
		);
	}

	private static function reading( int $age = 25 * 60, bool $with_temp = true ): GaugeReading {
		$t      = self::NOW - $age;
		$series = array();
		for ( $s = $t - 7 * 86400; $s <= $t; $s += 900 ) {
			$series[] = array( $s, $s <= $t - 6 * 3600 ? 601.0 : 538.0 );
		}
		return new GaugeReading(
			self::SITE,
			'Madison River near West Yellowstone',
			'Montana',
			new Measurement( 538.0, 'ft^3/s', $t, true ),
			new Measurement( 2.41, 'ft', $t, true ),
			$with_temp ? new Measurement( 9.2, 'degC', $t, true ) : null,
			$series,
			$t,
		);
	}

	private function render_ok( array $attributes = array(), ?GaugeReading $reading = null, array $settings = array() ): string {
		$result = new GaugeResult( GaugeResult::OK, self::SITE, $reading ?? self::reading() );
		return $this->renderer( $result, $settings )->render( $attributes + array( 'siteId' => self::SITE ) );
	}

	public function test_ok_card_matches_the_design(): void {
		$html = $this->render_ok();

		$this->assertStringStartsWith( '<figure class="rivcon-gauge">', $html );
		$this->assertStringContainsString( '<figcaption class="rivcon-gauge__head">', $html );
		$this->assertStringContainsString( 'Madison River near West Yellowstone', $html );
		$this->assertStringContainsString( 'Montana · USGS-06037500', $html );
		$this->assertStringContainsString( '<span class="rivcon-gauge__tag">Live</span>', $html );
		$this->assertStringContainsString( '<span class="rivcon-gauge__value">538</span><span class="rivcon-gauge__unit">cfs</span>', $html );
		$this->assertStringContainsString( '<span aria-hidden="true">↓</span> Falling', $html );
		$this->assertStringContainsString( '<dt>Gage height</dt><dd>2.41 ft</dd>', $html );
		$this->assertStringContainsString( '<dt>Water temp</dt><dd>48.6 °F</dd>', $html );
		$this->assertStringContainsString( 'role="img" aria-label="Flow over the last 7 days, from 538 to 601 cfs, currently falling"', $html );
		$this->assertStringContainsString( 'Updated 25 min ago', $html );
		$this->assertStringContainsString( 'Provisional data, subject to revision by USGS.', $html );
		$this->assertStringContainsString( 'href="https://waterdata.usgs.gov/monitoring-location/USGS-06037500/"', $html );
		$this->assertStringContainsString( 'Source: USGS', $html );
		$this->assertStringEndsWith( '</figure>', $html );
	}

	public function test_missing_temperature_is_hidden_not_zero(): void {
		$html = $this->render_ok( array(), self::reading( 25 * 60, false ) );
		$this->assertStringNotContainsString( 'Water temp', $html );
		$this->assertStringNotContainsString( '°F', $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringContainsString( 'Gage height', $html );
	}

	public function test_toggles_hide_values(): void {
		$html = $this->render_ok(
			array(
				'showGageHeight' => false,
				'showSparkline'  => false,
			)
		);
		$this->assertStringNotContainsString( 'Gage height', $html );
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringContainsString( 'Water temp', $html );
	}

	public function test_celsius(): void {
		$this->assertStringContainsString( '<dd>9.2 °C</dd>', $this->render_ok( array( 'tempUnit' => 'C' ) ) );
		$this->assertStringContainsString( '<dd>9.2 °C</dd>', $this->render_ok( array(), null, array( 'temp_unit' => 'C' ) ) );
	}

	public function test_stale_after_three_hours(): void {
		$html = $this->render_ok( array(), self::reading( 7 * 3600 ) );

		$this->assertStringContainsString( 'class="rivcon-gauge__alert" role="status">This gauge hasn’t reported since 7:15 am. Showing the last reading.', $html );
		$this->assertStringNotContainsString( 'rivcon-gauge__tag', $html, 'No Live tag when stale.' );
		$this->assertStringNotContainsString( 'rivcon-gauge__trend', $html, 'No trend when stale.' );
		$this->assertStringNotContainsString( '<svg', $html, 'No sparkline when stale.' );
		$this->assertStringContainsString( 'Last reading 7 hrs ago', $html );
	}

	public function test_fallback_with_recent_data_says_live_data_is_unavailable(): void {
		$result = new GaugeResult( GaugeResult::FALLBACK, self::SITE, self::reading( 40 * 60 ) );
		$html   = $this->renderer( $result )->render( array( 'siteId' => self::SITE ) );
		$this->assertStringContainsString( 'Live conditions are temporarily unavailable. Showing the reading from', $html );
		$this->assertStringNotContainsString( 'rivcon-gauge__tag', $html );
	}

	public function test_unavailable(): void {
		$result = new GaugeResult( GaugeResult::UNAVAILABLE, self::SITE );
		$html   = $this->renderer( $result )->render( array( 'siteId' => self::SITE ) );
		$this->assertStringContainsString( 'Live conditions are temporarily unavailable.', $html );
		$this->assertStringContainsString( 'Check the gauge directly', $html );
		$this->assertStringContainsString( 'View on USGS', $html );
	}

	public function test_unavailable_still_links_to_usgs_when_attribution_is_off(): void {
		$result = new GaugeResult( GaugeResult::UNAVAILABLE, self::SITE );
		$html   = $this->renderer( $result, array( 'show_attribution' => false ) )->render( array( 'siteId' => self::SITE ) );
		$this->assertStringContainsString( 'View on USGS', $html );
	}

	public function test_attribution_off_hides_source_link(): void {
		$this->assertStringNotContainsString( 'Source: USGS', $this->render_ok( array(), null, array( 'show_attribution' => false ) ) );
	}

	public function test_invalid_site_renders_only_a_comment_publicly(): void {
		$result = new GaugeResult( GaugeResult::INVALID, 'USGS-99999999' );
		$html   = $this->renderer( $result )->render( array( 'siteId' => 'USGS-99999999' ) );
		$this->assertSame( '<!-- River Conditions: no USGS gauge for USGS-99999999 -->', $html );
	}

	public function test_invalid_site_comment_cannot_break_out(): void {
		$result = new GaugeResult( GaugeResult::INVALID, 'x' );
		$html   = $this->renderer( $result )->render( array( 'siteId' => '--><script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 1, substr_count( $html, '-->' ) );
	}

	public function test_invalid_site_shows_notice_in_editor(): void {
		$result = new GaugeResult( GaugeResult::INVALID, 'USGS-99999999' );
		$html   = $this->renderer( $result )->render( array( 'siteId' => 'USGS-99999999' ), '', true );
		$this->assertStringContainsString( 'No USGS gauge found for “USGS-99999999”', $html );
	}

	public function test_compact_layout(): void {
		$html = $this->render_ok( array( 'layout' => 'compact' ) );
		$this->assertStringStartsWith( '<figure class="rivcon-gauge rivcon-gauge--compact">', $html );
		$this->assertStringContainsString( '<figcaption class="rivcon-gauge__name">', $html );
		$this->assertStringContainsString( '<span class="rivcon-gauge__meta">2.41 ft · 48.6 °F · 25 min ago</span>', $html );
		$this->assertStringContainsString( 'class="rivcon-gauge__src"', $html );
		$this->assertStringNotContainsString( '<svg', $html, 'Sparkline is card-only.' );
	}

	public function test_title_override_is_escaped(): void {
		$html = $this->render_ok( array( 'title' => 'Tom & Jerry <b>Run</b>' ) );
		$this->assertStringContainsString( 'Tom &amp; Jerry Run', $html );
		$this->assertStringNotContainsString( 'Madison River near West Yellowstone', $html );
	}

	public function test_wrapper_attributes_from_the_block_are_used(): void {
		$result = new GaugeResult( GaugeResult::OK, self::SITE, self::reading() );
		$html   = $this->renderer( $result )->render( array( 'siteId' => self::SITE ), 'class="wp-block-river-conditions-gauge rivcon-gauge alignwide"' );
		$this->assertStringStartsWith( '<figure class="wp-block-river-conditions-gauge rivcon-gauge alignwide">', $html );
	}

	public function test_bare_site_number_is_accepted(): void {
		$this->assertSame( 'USGS-06037500', GaugeRenderer::normalize( array( 'siteId' => ' 06037500 ' ) )['siteId'] );
	}

	public function test_shortcode_style_booleans(): void {
		$a = GaugeRenderer::normalize(
			array(
				'showFlow'       => 'false',
				'showTemp'       => '0',
				'showGageHeight' => 'yes',
				'layout'         => 'bogus',
				'tempUnit'       => 'k',
			)
		);
		$this->assertFalse( $a['showFlow'] );
		$this->assertFalse( $a['showTemp'] );
		$this->assertTrue( $a['showGageHeight'] );
		$this->assertSame( 'card', $a['layout'] );
		$this->assertSame( '', $a['tempUnit'] );
	}
}
