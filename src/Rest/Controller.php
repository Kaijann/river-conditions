<?php
/**
 * REST API: river-conditions/v1.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Rest;

use RiverConditions\Api\UsgsClient;
use RiverConditions\Api\UsgsException;
use RiverConditions\Cache\GaugeRepository;
use RiverConditions\Render\Format;
use RiverConditions\Render\GaugeRenderer;

/**
 * Editor and settings endpoints. Nothing here returns the API key.
 */
final class Controller {

	public const NAMESPACE = 'river-conditions/v1';

	/** Known-good gauge used by the connection test. */
	public const TEST_SITE = 'USGS-06037500';

	/**
	 * Constructor.
	 *
	 * @param GaugeRepository $repository Repository.
	 * @param GaugeRenderer   $renderer   Renderer.
	 * @param UsgsClient      $client     Client (for the uncached connection test).
	 */
	public function __construct(
		private readonly GaugeRepository $repository,
		private readonly GaugeRenderer $renderer,
		private readonly UsgsClient $client,
	) {}

	/**
	 * Hook into rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/sites/(?P<id>USGS-\d{8,15})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_site' ),
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'pattern'  => '^USGS-\d{8,15}$',
						'required' => true,
					),
				),
			)
		);

		$bool = array(
			'type'    => 'boolean',
			'default' => true,
		);
		register_rest_route(
			self::NAMESPACE,
			'/render',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'render' ),
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'siteId'         => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title'          => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'layout'         => array(
						'type'    => 'string',
						'enum'    => array( 'card', 'compact' ),
						'default' => 'card',
					),
					'showFlow'       => $bool,
					'showGageHeight' => $bool,
					'showTemp'       => $bool,
					'showSparkline'  => $bool,
					'tempUnit'       => array(
						'type'    => 'string',
						'enum'    => array( '', 'F', 'C' ),
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * GET /sites/{id}: validate a site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_site( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		try {
			$location = $this->repository->location( $id );
		} catch ( UsgsException $e ) {
			return new \WP_Error( 'rivcon_unavailable', __( 'Could not reach USGS. Try again shortly.', 'river-conditions' ), array( 'status' => 502 ) );
		}
		if ( null === $location ) {
			return new \WP_Error( 'rivcon_not_found', __( 'No USGS gauge with that ID.', 'river-conditions' ), array( 'status' => 404 ) );
		}
		$location['name'] = Format::site_name( $location['name'], '' !== $location['state'] );
		return new \WP_REST_Response( $location );
	}

	/**
	 * POST /render: block HTML for the editor preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function render( \WP_REST_Request $request ): \WP_REST_Response {
		$attributes = array();
		foreach ( array_keys( GaugeRenderer::defaults() ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$attributes[ $key ] = $request->get_param( $key );
			}
		}
		return new \WP_REST_Response( array( 'html' => $this->renderer->render( $attributes, '', true ) ) );
	}

	/**
	 * POST /test: uncached request to USGS, with latency.
	 */
	public function test(): \WP_REST_Response {
		$start = microtime( true );
		try {
			$location = $this->client->location( self::TEST_SITE );
		} catch ( UsgsException $e ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $e->getMessage(),
				)
			);
		}
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		return new \WP_REST_Response(
			array(
				'ok'         => null !== $location,
				'latency_ms' => $ms,
				'message'    => null !== $location
					/* translators: %d: milliseconds. */
					? sprintf( __( 'Connected to USGS Water Data API in %d ms.', 'river-conditions' ), $ms )
					: __( 'Connected, but the test gauge was not found.', 'river-conditions' ),
			)
		);
	}
}
