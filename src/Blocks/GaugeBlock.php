<?php
/**
 * The river-conditions/gauge block.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Blocks;

use RiverConditions\Render\GaugeRenderer;
use const RiverConditions\PLUGIN_FILE;

/**
 * Registers the block from its compiled block.json in build/.
 */
final class GaugeBlock {

	public const NAME = 'river-conditions/gauge';

	/**
	 * Register the block type.
	 */
	public function register(): void {
		$dir = dirname( PLUGIN_FILE ) . '/build/gauge';
		if ( ! file_exists( $dir . '/block.json' ) ) {
			// Not built yet (fresh git clone): run `npm run build`.
			return;
		}
		register_block_type( $dir );
	}

	/**
	 * Site IDs used by gauge blocks in some content, including nested blocks.
	 *
	 * @param string $content Post content.
	 * @return list<string>
	 */
	public static function site_ids_in( string $content ): array {
		if ( ! has_block( self::NAME, $content ) ) {
			return array();
		}
		return self::collect( parse_blocks( $content ) );
	}

	/**
	 * Walk parsed blocks.
	 *
	 * @param array<array<string, mixed>> $blocks Parsed blocks.
	 * @return list<string>
	 */
	private static function collect( array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			if ( self::NAME === ( $block['blockName'] ?? null ) && is_array( $block['attrs'] ?? null ) ) {
				$ids[] = GaugeRenderer::normalize( $block['attrs'] )['siteId'];
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, self::collect( $block['innerBlocks'] ) );
			}
		}
		return $ids;
	}
}
