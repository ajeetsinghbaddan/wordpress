<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * The WP_UNINSTALL_PLUGIN guard is mandatory: without it, anyone who can reach
 * the file over HTTP could wipe your settings by loading it directly.
 *
 * @package WC_Product_Enquiry
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wcpe_settings' );

// Removes the per-product override from every product in one indexed query,
// which is far cheaper than looping through products.
delete_post_meta_by_key( '_wcpe_enabled' );
