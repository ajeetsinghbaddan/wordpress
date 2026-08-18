<?php
/**
 * Runs when the plugin is DELETED (not deactivated).
 *
 * @package GuestPostSubmissions
 */

/*
 * WP_UNINSTALL_PLUGIN is defined by WordPress only when it invokes this file
 * as part of a genuine uninstall. Checking it means the file cannot be
 * executed by requesting its URL directly -- which would otherwise be a
 * one-request way for anyone to wipe the plugin's data.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$gps_settings = get_option( 'gps_settings', array() );

// Always remove our own settings and the capability -- these are ours alone.
delete_option( 'gps_settings' );
delete_transient( 'gps_pending_count' );

foreach ( array( 'administrator', 'editor' ) as $gps_role_name ) {
	$gps_role = get_role( $gps_role_name );

	if ( $gps_role ) {
		$gps_role->remove_cap( 'gps_moderate_submissions' );
	}
}

/*
 * Content is only destroyed if the site owner explicitly ticked the box. This
 * is the correct default: a plugin that silently deletes a year of published
 * guest posts because someone was testing an upgrade path is unforgivable.
 */
if ( empty( $gps_settings['delete_data_on_uninstall'] ) ) {
	return;
}

/*
 * Delete in batches. A site with 10,000 submissions would exhaust memory or
 * hit the PHP time limit if we loaded them all at once -- and an uninstall
 * that fatals halfway leaves the database in a worse state than doing nothing.
 *
 * 'fields' => 'ids' means WordPress returns a flat array of integers instead
 * of building 200 full WP_Post objects per batch.
 */
$gps_batch_size = 200;

do {
	$gps_ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'any',
			'posts_per_page'         => $gps_batch_size,
			'fields'                 => 'ids',
			'meta_key'               => '_gps_is_guest_submission', // phpcs:ignore WordPress.DB.SlowMetaQuery
			'no_found_rows'          => true, // Skip the SQL_CALC_FOUND_ROWS count.
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $gps_ids as $gps_id ) {
		// true = skip the trash and delete permanently, including attachments
		// and meta, which wp_delete_post handles for us.
		wp_delete_post( $gps_id, true );
	}
} while ( count( $gps_ids ) === $gps_batch_size );

/*
 * Sweep up any orphaned rate-limit transients. Transients live in the options
 * table when no persistent object cache is present, so a direct query is the
 * only way to match them by prefix. $wpdb->esc_like() escapes the % and _
 * wildcards in our prefix, and prepare() parameterises the query -- never
 * concatenate a variable into SQL.
 */
global $wpdb;

$gps_like = $wpdb->esc_like( '_transient_gps_' ) . '%';
$gps_like_timeout = $wpdb->esc_like( '_transient_timeout_gps_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$gps_like,
		$gps_like_timeout
	)
);
