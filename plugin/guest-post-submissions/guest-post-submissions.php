<?php
/**
 * Plugin Name:       Guest Post Submissions
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/guest-post-submissions
 * Description:       Lets visitors submit guest blog posts from the front end, and gives editors a dedicated moderation queue to approve or reject them.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guest-post-submissions
 * Domain Path:       /languages
 *
 * @package GuestPostSubmissions
 */

/*
 * ---------------------------------------------------------------------------
 * WHY THIS LINE IS ALWAYS FIRST
 * ---------------------------------------------------------------------------
 * ABSPATH is a constant that only exists once WordPress has booted. If someone
 * requests this file directly in a browser (example.com/wp-content/plugins/...),
 * WordPress never loaded, ABSPATH is undefined, and we exit immediately.
 *
 * Without this guard, a direct request would execute the file outside of
 * WordPress -- no user session, no capability checks, potentially exposing
 * fatal errors that leak absolute server paths. Every PHP file in a plugin
 * should start with this.
 */
defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * CONSTANTS
 * ---------------------------------------------------------------------------
 * Defining paths once means we never hardcode a directory name. If a user
 * renames the plugin folder, everything still resolves.
 *
 * - plugin_dir_path() -> server filesystem path, used for require/include.
 * - plugin_dir_url()  -> public URL, used for enqueueing CSS/JS.
 * These are NOT interchangeable. Using a filesystem path in a <script src>
 * would leak your server layout; using a URL in require() would break.
 */
define( 'GPS_VERSION', '1.0.0' );
define( 'GPS_PLUGIN_FILE', __FILE__ );
define( 'GPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Capability required to moderate guest submissions.
 *
 * We invent our own capability instead of reusing 'manage_options' so a site
 * owner can grant moderation rights to an Editor (or a custom role) without
 * also handing them the keys to the whole site. This is the principle of least
 * privilege applied to WordPress roles.
 */
define( 'GPS_MODERATE_CAP', 'gps_moderate_submissions' );

/*
 * ---------------------------------------------------------------------------
 * AUTOLOADER
 * ---------------------------------------------------------------------------
 * Instead of require_once-ing 12 files on every single page load, we register
 * an autoloader. PHP calls this function only at the moment a class is first
 * referenced, so a class that never gets used is never read from disk.
 *
 * That is a real performance win: on a front-end page view, the admin classes
 * and the list table never touch the filesystem at all.
 *
 * Naming convention: class GPS_Submission_Handler
 *                 -> includes/class-gps-submission-handler.php
 * This mirrors the WordPress core file naming convention.
 */
spl_autoload_register(
	function ( $class_name ) {
		// Fast bail-out: ignore every class that isn't ours.
		if ( 0 !== strpos( $class_name, 'GPS_' ) ) {
			return;
		}

		$file = GPS_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boot the plugin.
 *
 * We hook to 'plugins_loaded' rather than running immediately so that other
 * plugins and the translation system are already available. Running at file
 * scope would be fragile -- WordPress may not have finished loading yet.
 */
add_action(
	'plugins_loaded',
	function () {
		GPS_Plugin::instance();
	}
);

/*
 * ---------------------------------------------------------------------------
 * ACTIVATION / DEACTIVATION
 * ---------------------------------------------------------------------------
 * These run exactly once, when the plugin is switched on or off. They are the
 * right place for one-time setup: seeding options, granting capabilities.
 *
 * Note we pass a static method rather than a closure. register_activation_hook
 * works with closures, but named callbacks are easier to unhook and to test.
 */
register_activation_hook( GPS_PLUGIN_FILE, array( 'GPS_Plugin', 'on_activate' ) );
register_deactivation_hook( GPS_PLUGIN_FILE, array( 'GPS_Plugin', 'on_deactivate' ) );
