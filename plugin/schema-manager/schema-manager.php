<?php
/**
 * Plugin Name: Simple Schema Manager
 * Description: Adds JSON-LD schema markup to pages and posts, with automatic and manual modes plus a general settings panel.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: simple-schema-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSM_VERSION', '1.0.0' );
define( 'SSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSM_OPTION_KEY', 'ssm_settings' );

require_once SSM_PLUGIN_DIR . 'includes/class-ssm-settings.php';
require_once SSM_PLUGIN_DIR . 'includes/class-ssm-metabox.php';
require_once SSM_PLUGIN_DIR . 'includes/class-ssm-output.php';

function ssm_get_settings() {
	$defaults = array(
		'mode'              => 'automatic',
		'organization_name' => get_bloginfo( 'name' ),
		'organization_type' => 'Organization',
		'logo_url'          => '',
		'site_description'  => get_bloginfo( 'description' ),
		'social_profiles'   => '',
		'enable_posts'      => 1,
		'enable_pages'      => 1,
	);

	$saved = get_option( SSM_OPTION_KEY, array() );

	return wp_parse_args( $saved, $defaults );
}

function ssm_init() {
	new SSM_Settings();
	new SSM_Metabox();
	new SSM_Output();
}
add_action( 'plugins_loaded', 'ssm_init' );

register_uninstall_hook( __FILE__, 'ssm_uninstall' );

function ssm_uninstall() {
	delete_option( SSM_OPTION_KEY );
}
