<?php
/**
 * Plugin Name:       WhatsApp Chat Widget
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/whatsapp-chat-widget
 * Description:       A lightweight, secure floating WhatsApp chat box. Visitors type a message and it opens in WhatsApp.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * Text Domain:       wa-chat-widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCW_VERSION', '1.0.0' );
define( 'WCW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCW_OPTION_KEY', 'wcw_settings' );

require_once WCW_PLUGIN_DIR . 'includes/class-wcw-settings.php';
require_once WCW_PLUGIN_DIR . 'includes/class-wcw-frontend.php';

function wcw_get_settings() {
	$defaults = array(
		'enabled'         => 1,
		'phone'           => '',
		'default_message' => 'Hi! I have a question.',
		'agent_name'      => 'Support',
		'agent_status'    => 'Typically replies within minutes',
		'welcome_text'    => 'Hi there 👋 How can we help you?',
		'position'        => 'right',
		'show_on_mobile'  => 1,
	);

	$saved = get_option( WCW_OPTION_KEY, array() );

	return wp_parse_args( $saved, $defaults );
}

new WCW_Settings();
new WCW_Frontend();
