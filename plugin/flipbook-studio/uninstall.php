<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * WordPress loads this file on its own, outside the plugin, so nothing from
 * the plugin is available here and everything is spelled out again. The
 * WP_UNINSTALL_PLUGIN guard makes sure the file can only ever run in that one
 * context and never by a direct HTTP request.
 *
 * @package FlipbookStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$fbs_settings = get_option( 'fbs_settings', array() );

// Data is only destroyed when the site owner explicitly asked for it.
if ( empty( $fbs_settings['delete_on_purge'] ) ) {
	return;
}

global $wpdb;

// 1. Remove the flipbook posts and their meta.
$fbs_ids = get_posts(
	array(
		'post_type'      => 'flipbook',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);

foreach ( $fbs_ids as $fbs_id ) {
	wp_delete_post( $fbs_id, true );
}

// 2. Remove the private PDF folder, one level of subfolders deep.
$fbs_uploads = wp_upload_dir();
$fbs_dir     = trailingslashit( $fbs_uploads['basedir'] ) . 'flipbook-protected';

if ( is_dir( $fbs_dir ) ) {
	$fbs_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $fbs_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $fbs_items as $fbs_item ) {
		if ( $fbs_item->isDir() ) {
			rmdir( $fbs_item->getRealPath() ); // phpcs:ignore
		} else {
			wp_delete_file( $fbs_item->getRealPath() );
		}
	}

	rmdir( $fbs_dir ); // phpcs:ignore
}

// 3. Drop the activity table and the options.
$fbs_table = $wpdb->prefix . 'fbs_views';
$wpdb->query( "DROP TABLE IF EXISTS {$fbs_table}" ); // phpcs:ignore

delete_option( 'fbs_settings' );
delete_option( 'fbs_signing_key' );
delete_option( 'fbs_db_version' );

wp_clear_scheduled_hook( 'fbs_daily_cleanup' );
