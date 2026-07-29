<?php
/**
 * Plugin Name:       Flipbook Studio
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/flipbook-studio
 * Description:       Upload a PDF and publish it as a secure, interactive flipbook. Private file storage, signed short-lived URLs, password + expiry gating, thumbnails, search, outline and reading analytics.
 * Version:           1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flipbook-studio
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

define( 'FBS_VERSION', '1.1.0' );
define( 'FBS_FILE', __FILE__ );
define( 'FBS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FBS_URL', plugin_dir_url( __FILE__ ) );
define( 'FBS_POST_TYPE', 'flipbook' );

require_once FBS_DIR . 'includes/security.php';
require_once FBS_DIR . 'includes/post-type.php';
require_once FBS_DIR . 'includes/admin.php';
require_once FBS_DIR . 'includes/stream.php';
require_once FBS_DIR . 'includes/analytics.php';
require_once FBS_DIR . 'includes/render.php';
require_once FBS_DIR . 'includes/block.php';

/**
 * Runs once when the plugin is activated.
 *
 * Activation is the only safe place to do "one time" setup: creating the
 * protected upload folder, creating the analytics table, and generating the
 * secret key used to sign file URLs.
 */
function fbs_activate() {
	fbs_prepare_protected_dir();
	fbs_signing_key();
	fbs_install_analytics_table();

	add_option(
		'fbs_settings',
		array(
			'max_upload_mb'   => 64,
			'token_ttl'       => 900,
			'bind_to_ip'      => 0,
			'analytics'       => 1,
			'default_theme'   => 'ink',
			'delete_on_purge' => 0,
		)
	);

	fbs_register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'fbs_activate' );

/**
 * Runs on deactivation. We only flush rewrite rules; user data is never
 * touched here, because deactivating is not the same as uninstalling.
 */
function fbs_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'fbs_deactivate' );

/**
 * Returns a single plugin setting with a fallback default.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Value returned when the key is missing.
 * @return mixed
 */
function fbs_setting( $key, $default = null ) {
	$settings = get_option( 'fbs_settings', array() );
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}

/**
 * Loads translations.
 */
function fbs_load_textdomain() {
	load_plugin_textdomain( 'flipbook-studio', false, dirname( plugin_basename( FBS_FILE ) ) . '/languages' );
}
add_action( 'init', 'fbs_load_textdomain' );
