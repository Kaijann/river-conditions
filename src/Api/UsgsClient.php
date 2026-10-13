<?php
/**
 * HTTP client for the USGS Water Data OGC API.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Api;

use RiverConditions\Domain\GaugeReading;
use const RiverConditions\REPO_URL;
use const RiverConditions\VERSION;

/**
 * Builds queries and performs requests. The only WordPress functions used
 * are the wp_remote_* HTTP helpers, and those can be swapped out in tests by
 * passing an $http callable.
 */
class UsgsClient {

	public const BASE_URL = 'https://api.waterdata.usgs.gov/ogcapi/v1';
	public const TIMEOUT  = 8;

	/** Safety cap when following "next" links. */
	public const MAX_PAGES = 4;

	/**
	 * Performs a GET request: fn( string $url, array $args ): array|\WP_Error.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key Optional USGS API key. Never sent to the browser.
	 * @param callable|null $http    Request function, for tests. Defaults to wp_remote_get.
	 */
	public function __construct(
		private readonly string $api_key = '',
		?callable $http = null,
	) {
		$this->http = $http ?? 'wp_remote_get';
	}

	/**
	 * Fetch a monitoring location. Returns null when USGS has no such site.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 * @return array{id: string, name: string, state: string}|null
	 * @throws UsgsException On network or API errors.
	 */
	public function location( string $site_id ): ?array {
		$body = $this->get(
			'monitoring-locations',
			array(
				'id'           => $site_id,
				'skipGeometry' => 'true',
			)
		);
		return UsgsParser::location( $body );
	}

	/**
	 * Fetch the latest value of every parameter at a site.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 * @return array<string, \RiverConditions\Domain\Measurement>
	 * @throws UsgsException On network or API errors.
	 */
	public function latest( string $site_id ): array {
		$body = $this->get(
			'latest-continuous',
			array(
				// All parameters at once; a site reports a handful, and we pick ours in the parser.
				'monitoring_location_id' => $site_id,
				'skipGeometry'           => 'true',
				'limit'                  => '100',
			)
		);
		return UsgsParser::latest( $body );
	}

	/**
	 * Fetch the 7-day discharge series, following pagination.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 * @return list<array{0:int,1:float}>
	 * @throws UsgsException On network or API errors.
	 */
	public function flow_series( string $site_id ): array {
		$url      = self::url(
			'continuous',
			array(
				'monitoring_location_id' => $site_id,
				'parameter_code'         => GaugeReading::PARAM_FLOW,
				'datetime'               => 'P7D',
				'skipGeometry'           => 'true',
				'limit'                  => '1000',
			),
			$this->api_key
		);
		$features = array();
		for ( $page = 0; $page < self::MAX_PAGES && null !== $url; $page++ ) {
			$body     = $this->request( $url );
			$features = array_merge( $features, (array) ( $body['features'] ?? array() ) );
			$next     = UsgsParser::next_link( $body );
			$url      = null !== $next ? $this->with_key( $next ) : null;
		}
		return UsgsParser::series( array( 'features' => $features ) );
	}

	/**
	 * GET a collection's items.
	 *
	 * @param string                $collection Collection name.
	 * @param array<string, string> $params     Query parameters.
	 * @return array<mixed> Decoded JSON.
	 * @throws UsgsException On network or API errors.
	 */
	public function get( string $collection, array $params ): array {
		return $this->request( self::url( $collection, $params, $this->api_key ) );
	}

	/**
	 * Build an items URL. Pure, so it is unit tested.
	 *
	 * @param string                $collection Collection name.
	 * @param array<string, string> $params     Query parameters.
	 * @param string                $api_key    Optional API key.
	 */
	public static function url( string $collection, array $params, string $api_key = '' ): string {
		$params = array( 'f' => 'json' ) + $params;
		if ( '' !== $api_key ) {
			$params['api_key'] = $api_key;
		}
		return self::BASE_URL . '/collections/' . rawurlencode( $collection ) . '/items?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Add the API key to a URL from a "next" link, if it lacks one.
	 *
	 * @param string $url URL.
	 */
	private function with_key( string $url ): string {
		if ( '' === $this->api_key || str_contains( $url, 'api_key=' ) ) {
			return $url;
		}
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'api_key=' . rawurlencode( $this->api_key );
	}

	/**
	 * Perform the request and decode JSON.
	 *
	 * @param string $url Full URL.
	 * @return array<mixed>
	 * @throws UsgsException On network or API errors. Messages never include the URL, so the key cannot leak.
	 *
	 * Exception messages are not printed as HTML: the settings page shows them with textContent.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function request( string $url ): array {
		$response = ( $this->http )(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'RiverConditions/' . VERSION . ' (+' . REPO_URL . ')',
				'headers'    => array( 'Accept' => 'application/geo+json, application/json' ),
			)
		);

		if ( $response instanceof \WP_Error ) {
			$message = $response->get_error_message();
			if ( '' !== $this->api_key ) {
				$message = str_replace( $this->api_key, '***', $message );
			}
			throw new UsgsException( 'Network error: ' . $message, 0 );
		}
		if ( ! is_array( $response ) ) {
			throw new UsgsException( 'Unexpected HTTP response.', 0 );
		}

		$status = (int) ( $response['response']['code'] ?? 0 );
		if ( $status < 200 || $status >= 300 ) {
			throw new UsgsException( sprintf( 'USGS API returned HTTP %d.', $status ), $status );
		}

		$body = json_decode( (string) ( $response['body'] ?? '' ), true );
		if ( ! is_array( $body ) ) {
			throw new UsgsException( 'USGS API returned invalid JSON.', $status );
		}
		return $body;
	}
	// phpcs:enable
}
