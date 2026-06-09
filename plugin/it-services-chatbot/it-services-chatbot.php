<?php
/**
 * Plugin Name: IT Services Chatbot
 * Plugin URI:  https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/it-services-chatbot
 * Description: A fully configurable branching chatbot that collects lead details for IT service enquiries.
 * Version:     1.0.0
 * Author:      Ajeet Singh Baddan
 * Text Domain: it-services-chatbot
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'ITSC_VERSION',     '1.0.0' );
define( 'ITSC_PLUGIN_FILE', __FILE__ );
define( 'ITSC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ITSC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once ITSC_PLUGIN_DIR . 'includes/class-itsc-db.php';
require_once ITSC_PLUGIN_DIR . 'includes/class-itsc-ajax.php';
require_once ITSC_PLUGIN_DIR . 'includes/class-itsc-shortcode.php';

if ( is_admin() ) {
    require_once ITSC_PLUGIN_DIR . 'admin/class-itsc-admin.php';
}

register_activation_hook(   __FILE__, [ 'ITSC_DB', 'install' ] );
register_deactivation_hook( __FILE__, [ 'ITSC_DB', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    ITSC_Ajax::init();
    ITSC_Shortcode::init();
    if ( is_admin() ) {
        ITSC_Admin::init();
    }
} );
