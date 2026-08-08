<?php
/**
 * Plugin Name:       Groq Site Chatbot
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/groq-site-chatbot
 * Description:       A secure chatbot that first answers from your own website content, then falls back to web search via the Groq API when the site has no relevant answer.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * Text Domain:       groq-site-chatbot
 */

// Security: block direct access. Without this, hitting the file URL directly
// would execute it outside WordPress, where no auth/nonce checks exist.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GSC_VERSION', '1.0.0' );
define( 'GSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GSC_PLUGIN_DIR . 'includes/class-gsc-settings.php';
require_once GSC_PLUGIN_DIR . 'includes/class-gsc-site-search.php';
require_once GSC_PLUGIN_DIR . 'includes/class-gsc-groq-client.php';
require_once GSC_PLUGIN_DIR . 'includes/class-gsc-chat-controller.php';
require_once GSC_PLUGIN_DIR . 'includes/class-gsc-frontend.php';

/**
 * Boot the plugin. Each class hooks itself into WordPress in its constructor,
 * so instantiating them once on `plugins_loaded` is all we need.
 */
function gsc_bootstrap() {
	new GSC_Settings();
	new GSC_Chat_Controller();
	new GSC_Frontend();
}
add_action( 'plugins_loaded', 'gsc_bootstrap' );
