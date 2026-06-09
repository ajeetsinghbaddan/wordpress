<?php
/**
 * Runs only when the user deletes the plugin from the Plugins screen.
 *
 * WordPress loads this file automatically on uninstall. We remove everything the
 * plugin created so deletion leaves no orphaned data behind. (Deactivation does NOT
 * trigger this — only a full delete does — so data survives a temporary deactivation.)
 *
 * @package QuizCertify
 */

// WP_UNINSTALL_PLUGIN is defined by WordPress only during a genuine uninstall. If it
// is missing, someone is loading this file directly, so we refuse to run.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the results table.
$table = $wpdb->prefix . 'quiz_certify_results';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Remove the stored option.
delete_option( 'quiz_certify_db_version' );

// Delete the quiz posts and their meta. We query our CPT directly and delete each one,
// which also clears the associated postmeta rows.
$quiz_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'qc_quiz' )
);

if ( $quiz_ids ) {
	foreach ( $quiz_ids as $quiz_id ) {
		wp_delete_post( (int) $quiz_id, true );
	}
}
