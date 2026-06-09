<?php
/**
 * Fired during plugin activation, and on version upgrades.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Activator {

	/**
	 * Run the schema build on activation.
	 */
	public static function activate() {
		self::create_table();
		update_option( 'quiz_certify_db_version', QUIZ_CERTIFY_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Run on every load (cheaply) to upgrade the schema of existing installs.
	 *
	 * The activation hook only fires when the plugin is switched on. A site that was
	 * already running an older version never re-activates, so we compare the stored DB
	 * version to the current one here and run dbDelta when they differ. dbDelta adds the
	 * new user_email column without touching existing data.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'quiz_certify_db_version' );
		if ( QUIZ_CERTIFY_VERSION !== $installed ) {
			self::create_table();
			update_option( 'quiz_certify_db_version', QUIZ_CERTIFY_VERSION );
		}
	}

	/**
	 * Create or update the results table.
	 *
	 * We store each attempt in its own table because results are high-volume and
	 * queried by their own columns. user_email is new in 1.1.0 for record-keeping.
	 */
	private static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'quiz_certify_results';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta compares this statement to the live table and applies the difference,
		// so the same code both creates the table and upgrades it. Its formatting is
		// strict: two spaces after PRIMARY KEY, one column per line.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_name varchar(191) NOT NULL DEFAULT '',
			user_email varchar(191) NOT NULL DEFAULT '',
			score int(11) NOT NULL DEFAULT 0,
			total int(11) NOT NULL DEFAULT 0,
			percentage decimal(5,2) NOT NULL DEFAULT 0.00,
			passed tinyint(1) NOT NULL DEFAULT 0,
			cert_token char(32) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY quiz_id (quiz_id),
			KEY user_id (user_id),
			KEY cert_token (cert_token)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
