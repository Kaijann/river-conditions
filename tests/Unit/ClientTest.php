<?php
/**
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Tests\Unit;

use RiverConditions\Api\UsgsClient;
use RiverConditions\Api\UsgsException;

final class ClientTest extends TestCase {

	public function test_url_adds_format_and_omits_empty_key(): void {
		$url = UsgsClient::url( 'monitoring-locations', array( 'id' => 'USGS-06037500' ) );
		$this->assertSame( 'https://api.waterdata.usgs.gov/ogcapi/v1/collections/monitoring-locations/items?f=json&id=USGS-06037500', $url );
	}

	public function test_url_appends_key_when_set(): void {
		$url = UsgsClient::url( 'continuous', array( 'limit' => '10' ), 'abc123' );
		$this->assertStringEndsWith( '&limit=10&api_key=abc123', $url );
	}

	public function test_series_uses_an_explicit_seven_day_interval(): void {
		// The live API answers HTTP 400 "Unknown string format: P7D" to durations.
		$this->assertSame( '2026-10-06T21:00:00Z/2026-10-13T21:00:00Z', UsgsClient::interval( 1791925200, 7 * 86400 ) );

		$urls   = array();
		$client = new UsgsClient(
			'',
			function ( string $url ) use ( &$urls ) {
				$urls[] = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"features":[]}',
				);
			}
		);
		$client->flow_series( 'USGS-06037500', 1791925200 );
		$this->assertStringContainsString( 'datetime=2026-10-06T21%3A00%3A00Z%2F2026-10-13T21%3A00%3A00Z', $urls[0] );
		$this->assertStringNotContainsString( 'P7D', $urls[0] );
	}

	public function test_sends_user_agent_and_timeout(): void {
		$seen   = array();
		$client = new UsgsClient(
			'',
			function ( string $url, array $args ) use ( &$seen ) {
				$seen = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"features":[]}',
				);
			}
		);
		$client->location( 'USGS-06037500' );
		$this->assertSame( 8, $seen['timeout'] );
		$this->assertStringStartsWith( 'RiverConditions/1.0.0 (+https://github.com/', $seen['user-agent'] );
	}

	public function test_follows_next_links_and_keeps_the_key(): void {
		$urls   = array();
		$client = new UsgsClient(
			'secret',
			function ( string $url ) use ( &$urls ) {
				$urls[] = $url;
				$page   = count( $urls );
				$body   = array(
					'features' => array(
						array(
							'properties' => array(
								'time'  => gmdate( 'c', 1_760_000_000 + $page * 900 ),
								'value' => (string) ( 100 + $page ),
							),
						),
					),
					'links'    => $page < 2 ? array(
						array(
							'rel'  => 'next',
							'href' => 'https://api.waterdata.usgs.gov/ogcapi/v1/collections/continuous/items?f=json&offset=1000',
						),
					) : array(),
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) json_encode( $body ),
				);
			}
		);

		$series = $client->flow_series( 'USGS-06037500' );

		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'api_key=secret', $urls[1] );
		$this->assertSame( array( 101.0, 102.0 ), array_column( $series, 1 ) );
	}

	public function test_http_error_status_throws(): void {
		$client = new UsgsClient(
			'',
			static fn () => array(
				'response' => array( 'code' => 503 ),
				'body'     => '',
			)
		);
		$this->expectException( UsgsException::class );
		$this->expectExceptionCode( 503 );
		$client->latest( 'USGS-06037500' );
	}

	public function test_network_error_message_never_contains_the_key(): void {
		$client = new UsgsClient( 'topsecret', static fn () => new \WP_Error( 'http_request_failed', 'Timeout for ...?api_key=topsecret' ) );
		try {
			$client->latest( 'USGS-06037500' );
			$this->fail( 'Expected an exception.' );
		} catch ( UsgsException $e ) {
			$this->assertStringNotContainsString( 'topsecret', $e->getMessage() );
		}
	}

	public function test_invalid_json_throws(): void {
		$client = new UsgsClient(
			'',
			static fn () => array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html>',
			)
		);
		$this->expectException( UsgsException::class );
		$client->location( 'USGS-06037500' );
	}
}
