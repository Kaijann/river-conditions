<?php
/**
 * [river_conditions] shortcode.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions;

use RiverConditions\Render\GaugeRenderer;

/**
 * Maps snake_case shortcode attributes onto the block's attributes and
 * calls the same renderer as the block.
 */
final class Shortcode {

	public const TAG = 'river_conditions';

	/** Shortcode attribute => block attribute. */
	private const MAP = array(
		'site'             => 'siteId',
		'title'            => 'title',
		'layout'           => 'layout',
		'show_flow'        => 'showFlow',
		'show_gage_height' => 'showGageHeight',
		'show_temp'        => 'showTemp',
		'show_sparkline'   => 'showSparkline',
		'temp_unit'        => 'tempUnit',
	);

	/**
	 * Constructor.
	 *
	 * @param GaugeRenderer $renderer Renderer.
	 */
	public function __construct( private readonly GaugeRenderer $renderer ) {}

	/**
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes ('' when none).
	 */
	public function render( array|string $atts ): string {
		$atts = shortcode_atts( array_fill_keys( array_keys( self::MAP ), null ), is_array( $atts ) ? $atts : array(), self::TAG );
		$html = $this->renderer->render( self::to_block_attributes( $atts ) );
		Plugin::enqueue_style_for( $html );
		return $html;
	}

	/**
	 * Translate shortcode attributes to block attributes, dropping unset ones.
	 *
	 * @param array<string, string|null> $atts Shortcode attributes.
	 * @return array<string, string>
	 */
	public static function to_block_attributes( array $atts ): array {
		$out = array();
		foreach ( self::MAP as $from => $to ) {
			if ( isset( $atts[ $from ] ) ) {
				$out[ $to ] = (string) $atts[ $from ];
			}
		}
		return $out;
	}

	/**
	 * Site IDs used by shortcodes in some content (for cache warming).
	 *
	 * @param string $content Post content.
	 * @return list<string>
	 */
	public static function site_ids_in( string $content ): array {
		if ( ! has_shortcode( $content, self::TAG ) ) {
			return array();
		}
		preg_match_all( '/' . get_shortcode_regex( array( self::TAG ) ) . '/', $content, $matches, PREG_SET_ORDER );
		$ids = array();
		foreach ( $matches as $match ) {
			$atts = shortcode_parse_atts( $match[3] );
			if ( isset( $atts['site'] ) ) {
				$ids[] = GaugeRenderer::normalize( array( 'siteId' => $atts['site'] ) )['siteId'];
			}
		}
		return $ids;
	}
}
