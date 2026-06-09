<?php
/**
 * Dependency manifest for index.js.
 *
 * Normally a build tool (@wordpress/scripts) generates this file automatically.
 * Because this plugin ships build-free, we declare the dependencies by hand so
 * WordPress loads the required wp.* packages (and in the right order) before
 * our editor script runs.
 *
 * @package VersatileGallery
 */

return array(
	'dependencies' => array(
		'wp-blocks',       // registerBlockType
		'wp-element',      // createElement / React wrapper
		'wp-block-editor', // InspectorControls, MediaUpload, useBlockProps
		'wp-components',   // PanelBody, RangeControl, ToggleControl, etc.
		'wp-data',         // useSelect for resolving media URLs in the preview
		'wp-i18n',         // __() translation function
	),
	'version'      => '1.0.0',
);
