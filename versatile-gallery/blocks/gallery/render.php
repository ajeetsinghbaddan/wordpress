<?php
/**
 * Server render for the Versatile Gallery block.
 *
 * WordPress includes this file whenever the block appears on the frontend, and
 * makes these variables available in scope:
 *
 * @var array    $attributes Block attributes, already typed per block.json.
 * @var string   $content    Inner-block HTML (unused by this block).
 * @var WP_Block $block      The block instance.
 *
 * Whatever this file echoes becomes the block's frontend HTML.
 *
 * @package VersatileGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delegate to the shared renderer. It re-sanitizes the attributes (defense in
// depth) and returns fully-escaped HTML, so echoing it directly is safe.
echo VGAL_Renderer::render( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns fully-escaped HTML.
