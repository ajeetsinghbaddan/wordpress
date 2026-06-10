<?php
/**
 * Plugin Name: Multistep Form Builder
 * Plugin URI: https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/multistep-form-builder
 * Description: Create multistep forms with unlimited steps and fields. Theme-inherited styling, Gutenberg block, Elementor widget, hardened submissions.
 * Version: 1.1.0
 * Author: Ajeet Singh Baddan
 * Text Domain: msfb
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MSFB_VERSION', '1.1.0');
define('MSFB_PATH', plugin_dir_path(__FILE__));
define('MSFB_URL', plugin_dir_url(__FILE__));

require_once MSFB_PATH . 'includes/class-msfb-db.php';
require_once MSFB_PATH . 'includes/class-msfb-admin.php';
require_once MSFB_PATH . 'includes/class-msfb-frontend.php';
require_once MSFB_PATH . 'includes/class-msfb-blocks.php';
require_once MSFB_PATH . 'includes/class-msfb-elementor.php';

register_activation_hook(__FILE__, ['MSFB_DB', 'create_tables']);

add_action('plugins_loaded', function () {
    new MSFB_Admin();
    new MSFB_Frontend();
    new MSFB_Blocks();
    new MSFB_Elementor();
});
