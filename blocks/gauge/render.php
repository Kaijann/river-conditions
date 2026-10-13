<?php
/**
 * Server render for river-conditions/gauge.
 *
 * WordPress provides $attributes (array), $content (string) and $block (WP_Block).
 *
 * @package RiverConditions
 */

declare(strict_types=1);

use RiverConditions\Plugin;
use RiverConditions\Render\GaugeRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block attributes, provided by WordPress.
 *
 * @var array<string, mixed> $attributes
 */
$rivcon_html = Plugin::renderer()->render(
	$attributes,
	get_block_wrapper_attributes( array( 'class' => GaugeRenderer::classes( $attributes ) ) )
);

Plugin::enqueue_style_for( $rivcon_html );

// GaugeRenderer escapes every value; get_block_wrapper_attributes() output is escaped by core.
echo $rivcon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
