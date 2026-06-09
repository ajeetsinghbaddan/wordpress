<?php
/**
 * Dependency manifest for blocks/testimonials/index.js.
 *
 * When register_block_type() loads the editorScript, it looks for a matching
 * "<name>.asset.php" file to learn which WordPress script packages the editor
 * script needs and what version to use. Normally the @wordpress/scripts build
 * tool generates this automatically; because we hand-write the editor script
 * (no build step), we declare the dependencies ourselves here.
 *
 * Each handle (e.g. 'wp-element') is a core script WordPress ships, so listing
 * them ensures they are loaded before our script runs.
 *
 * @package ASB_Testimonials_Showcase
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-server-side-render',
		'wp-i18n',
		'wp-data',
	),
	'version'      => '1.0.0',
);
