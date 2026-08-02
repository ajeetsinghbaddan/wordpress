<?php
/**
 * Runs when the plugin is deleted from the Plugins screen — not on deactivate.
 *
 * WordPress only executes this file if the constant below is defined, which it
 * sets itself. Checking for it stops the file being run directly over HTTP.
 *
 * @package PuzzleGate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'puzzle_gate_settings' );
delete_option( 'puzzle_gate_stats' );

global $wpdb;

/*
 * Sweep leftover transients.
 *
 * Every transient is two option rows (`_transient_x` and `_transient_timeout_x`).
 * Expired ones are only cleaned up lazily, so an uninstall should remove them.
 *
 * $wpdb->prepare() is what makes this safe: the LIKE pattern is passed as a
 * bound parameter, never concatenated. esc_like() escapes the SQL wildcards `%`
 * and `_` so a literal underscore in our prefix stays literal.
 */
foreach ( array( 'pgz_ch_', 'pgz_pass_', 'pgz_rl_' ) as $prefix ) {
	$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
	$time = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like,
			$time
		)
	);
}

// If a persistent object cache is in use, transients live there instead.
wp_cache_flush();
