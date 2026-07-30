<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen (not on
 * deactivate). WordPress defines WP_UNINSTALL_PLUGIN before including this
 * file; checking it stops the file doing anything if requested directly.
 *
 * @package YouTube_Embed_Pro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ytep_channels' );
