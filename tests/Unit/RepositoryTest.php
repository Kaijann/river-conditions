<?php
/**
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use RiverConditions\Api\UsgsClient;
use RiverConditions\Cache\GaugeRepository;
use RiverConditions\Cache\GaugeResult;

final class RepositoryTest extends TestCase {

	private FakeUsgs $usgs;

	private GaugeRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->usgs = new FakeUsgs();
		$this->usgs->stub_wordpress();
		$this->usgs->healthy_site( time() );
		$this->repo = new GaugeRepository( new UsgsClient( '', $this->usgs->http() ), 900 );
	}

	public function test_rejects_malformed_ids_without_calling_usgs(): void {
		foreach ( array( '', '06037500', 'USGS-123', 'USGS-06037500; DROP', 'usgs-06037500' ) as $id ) {
			$this->assertSame( GaugeResult::INVALID, $this->repo->get( $id )->status, $id );
		}
		$this->assertSame( array(), $this->usgs->requests );
	}

	public function test_fetches_then_serves_from_cache(): void {
		$first = $this->repo->get( 'USGS-06037500' );
		$this->assertSame( GaugeResult::OK, $first->status );
		$this->assertNotNull( $first->reading );
		$this->assertSame( 538.0, $first->reading->flow?->value );
		$this->assertNotEmpty( $first->reading->flow_series );

		$requests = count( $this->usgs->requests );
		$second   = $this->repo->get( 'USGS-06037500' );
		$this->assertSame( GaugeResult::OK, $second->status );
		$this->assertSame( $requests, count( $this->usgs->requests ), 'Second call is served from cache.' );
	}

	public function test_cache_holds_normalized_arrays_not_raw_json(): void {
		$this->repo->get( 'USGS-06037500' );
		foreach ( $this->usgs->transients as $value ) {
			$this->assertIsArray( $value );
			$this->assertArrayNotHasKey( 'features', $value );
		}
	}

	public function test_serves_last_known_good_when_usgs_fails(): void {
		$this->repo->get( 'USGS-06037500' );

		// Normal cache expires, then USGS goes down.
		foreach ( array_keys( $this->usgs->transients ) as $key ) {
			if ( ! str_starts_with( $key, 'rivcon_lkg_' ) && ! str_starts_with( $key, 'rivcon_loc_' ) ) {
				unset( $this->usgs->transients[ $key ] );
			}
		}
		$this->usgs->responses['latest-continuous'] = 503;

		$result = $this->repo->get( 'USGS-06037500' );
		$this->assertSame( GaugeResult::FALLBACK, $result->status );
		$this->assertSame( 538.0, $result->reading?->flow?->value );
	}

	public function test_unavailable_when_usgs_fails_and_nothing_cached(): void {
		$this->usgs->responses['monitoring-locations'] = new \WP_Error( 'http_request_failed', 'timed out' );
		$this->assertSame( GaugeResult::UNAVAILABLE, $this->repo->get( 'USGS-06037500' )->status );
	}

	public function test_unknown_site_is_invalid_and_remembered(): void {
		$this->usgs->responses['monitoring-locations'] = array( 'features' => array() );
		$this->assertSame( GaugeResult::INVALID, $this->repo->get( 'USGS-99999999' )->status );
		$requests = count( $this->usgs->requests );
		$this->assertSame( GaugeResult::INVALID, $this->repo->get( 'USGS-99999999' )->status );
		$this->assertSame( $requests, count( $this->usgs->requests ) );
	}

	public function test_sparkline_failure_does_not_hide_the_reading(): void {
		$this->usgs->responses['continuous'] = 500;
		$result                              = $this->repo->get( 'USGS-06037500' );
		$this->assertSame( GaugeResult::OK, $result->status );
		$this->assertSame( array(), $result->reading?->flow_series );
	}

	public function test_lock_prevents_a_second_concurrent_fetch(): void {
		$this->usgs->transients[ 'rivcon_lock_' . md5( 'USGS-06037500' ) ] = 1;
		$result = $this->repo->get( 'USGS-06037500' );
		$this->assertSame( GaugeResult::UNAVAILABLE, $result->status );
		$this->assertSame( array(), $this->usgs->requests );
	}

	public function test_flush_invalidates_cached_readings(): void {
		global $wpdb;
		$wpdb          = \Mockery::mock(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double.
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'query' )->once();

		$this->repo->get( 'USGS-06037500' );
		$requests = count( $this->usgs->requests );

		GaugeRepository::flush();
		$this->repo->get( 'USGS-06037500' );
		$this->assertGreaterThan( $requests, count( $this->usgs->requests ) );
	}
}
