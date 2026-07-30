<?php
/**
 * WordPress expects a *.asset.php next to every block script. Normally
 * @wordpress/scripts generates it during a build; because this plugin ships
 * plain ES5 with no build step, we write it by hand.
 *
 * @package YouTube_Embed_Pro
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
		'wp-server-side-render',
	),
	'version'      => '1.1.0',
);
