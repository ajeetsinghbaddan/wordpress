<?php
/**
 * Plugin Name: Scroll Reveal Animator
 * Plugin URI: https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/scroll-reveal-animator
 * Description: Reveal any content with smooth animations as visitors scroll. Works with Gutenberg blocks, Elementor, shortcodes, and any theme via CSS classes.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Ajeet Singh Baddan
 * License: GPL-2.0-or-later
 * Text Domain: scroll-reveal-animator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SRA_VERSION', '1.0.0' );
define( 'SRA_PLUGIN_FILE', __FILE__ );
define( 'SRA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SRA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SRA_PLUGIN_DIR . 'includes/class-sra-settings.php';
require_once SRA_PLUGIN_DIR . 'includes/class-sra-frontend.php';
require_once SRA_PLUGIN_DIR . 'includes/class-sra-editor.php';

SRA_Settings::init();
SRA_Frontend::init();
SRA_Editor::init();
