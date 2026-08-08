<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen.
 * Removes the stored settings (including the API key) and any cached
 * answers/rate-limit counters so nothing sensitive is left behind.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gsc_settings' );

// Clear our transients (cached answers gsc_ans_* and rate limits gsc_rl_*).
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_gsc\_%'
	    OR option_name LIKE '\_transient\_timeout\_gsc\_%'"
);
