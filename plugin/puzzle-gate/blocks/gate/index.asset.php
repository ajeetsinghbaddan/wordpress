<?php
/**
 * Asset manifest for blocks/gate/index.js.
 *
 * When a block.json points `editorScript` at a file, WordPress looks for a
 * matching `.asset.php` next to it. A @wordpress/scripts build generates this
 * automatically; because this plugin has no build step, we write it by hand.
 *
 * `dependencies` become the script's dependency array, which is what guarantees
 * wp.blocks / wp.element / wp.components exist by the time index.js runs.
 *
 * @package PuzzleGate
 */

return array(
	'dependencies' => array(
		'wp-blocks',       // registerBlockType
		'wp-element',      // createElement, Fragment, hooks
		'wp-block-editor', // useBlockProps, InnerBlocks, InspectorControls
		'wp-components',   // PanelBody, TextControl, SelectControl…
		'wp-data',         // select(), for the duplicate-id check
		'wp-i18n',         // __()
	),
	'version'      => '1.0.0',
);
