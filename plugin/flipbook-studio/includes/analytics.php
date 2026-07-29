<?php
/**
 * Reading activity: a small, privacy-conscious record of which pages get read.
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Name of the activity table.
 *
 * @return string
 */
function fbs_views_table() {
	global $wpdb;
	return $wpdb->prefix . 'fbs_views';
}

/**
 * Creates or upgrades the activity table.
 *
 * dbDelta compares the requested schema against what exists and issues only
 * the differences, which makes this safe to run on every activation.
 */
function fbs_install_analytics_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = fbs_views_table();
	$collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		book_id BIGINT UNSIGNED NOT NULL,
		page SMALLINT UNSIGNED NOT NULL,
		session CHAR(64) NOT NULL,
		ip_hash CHAR(64) NOT NULL,
		viewed_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY book_page (book_id, page),
		KEY book_session (book_id, session),
		KEY viewed_at (viewed_at)
	) {$collate};";

	dbDelta( $sql );

	update_option( 'fbs_db_version', '1.0.0', false );
}

/**
 * Stores one page read.
 *
 * The visitor is identified only by a hash. The session id comes from the
 * browser's sessionStorage (it dies with the tab) and the IP is salted with the
 * site's own key before hashing, so the stored value cannot be reversed with a
 * rainbow table of every IPv4 address.
 *
 * @param int    $book_id Flipbook post ID.
 * @param int    $page    Page number.
 * @param string $session Client-generated session id.
 */
function fbs_record_view( $book_id, $page, $session ) {
	global $wpdb;

	if ( $book_id < 1 || $page < 1 || FBS_POST_TYPE !== get_post_type( $book_id ) ) {
		return;
	}

	// $wpdb->insert escapes and type-casts every value it is given, which is
	// why no string concatenation happens anywhere near this query.
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		fbs_views_table(),
		array(
			'book_id'   => $book_id,
			'page'      => min( 65535, $page ),
			'session'   => hash( 'sha256', $session . fbs_signing_key() ),
			'ip_hash'   => hash( 'sha256', fbs_client_ip() . fbs_signing_key() ),
			'viewed_at' => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s', '%s', '%s' )
	);
}

/**
 * Summarises activity for one flipbook.
 *
 * Cached for five minutes so a busy list table does not run three aggregate
 * queries per row on every page load.
 *
 * @param int $book_id Flipbook post ID.
 * @return array
 */
function fbs_get_stats( $book_id ) {
	global $wpdb;

	$cache_key = 'fbs_stats_' . (int) $book_id;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$table = fbs_views_table();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$views = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE book_id = %d", $book_id )
	);

	$sessions = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(DISTINCT session) FROM {$table} WHERE book_id = %d", $book_id )
	);

	$top = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT page, COUNT(*) AS hits FROM {$table} WHERE book_id = %d GROUP BY page ORDER BY hits DESC LIMIT 5",
			$book_id
		)
	);
	// phpcs:enable

	$stats = array(
		'views'    => $views,
		'sessions' => $sessions,
		'top'      => $top ? $top : array(),
	);

	set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

	return $stats;
}

/**
 * Removes activity rows for a deleted flipbook.
 *
 * @param int $book_id Flipbook post ID.
 */
function fbs_delete_analytics_for( $book_id ) {
	global $wpdb;

	$wpdb->delete( fbs_views_table(), array( 'book_id' => (int) $book_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_transient( 'fbs_stats_' . (int) $book_id );
}

/**
 * Trims activity older than a year on a daily schedule.
 *
 * Keeping raw rows forever turns a helpful feature into a storage problem and
 * a data-retention liability, so old rows age out on their own.
 */
function fbs_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'fbs_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fbs_daily_cleanup' );
	}
}
add_action( 'init', 'fbs_schedule_cleanup' );

/**
 * The scheduled trim itself.
 */
function fbs_run_cleanup() {
	global $wpdb;

	$table  = fbs_views_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE viewed_at < %s", $cutoff ) );
}
add_action( 'fbs_daily_cleanup', 'fbs_run_cleanup' );
