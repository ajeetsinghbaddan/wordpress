<?php
/**
 * Plugin Name:       YouTube Embed Pro
 * Description:       Secure, privacy-friendly YouTube embeds for videos, Shorts, playlists and live streams. Provides a [yt_embed] shortcode and a block.
 * Version:           1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ytep
 *
 * @package YouTube_Embed_Pro
 */

defined( 'ABSPATH' ) || exit;

define( 'YTEP_VERSION', '1.1.0' );
define( 'YTEP_FILE', __FILE__ );
define( 'YTEP_PATH', plugin_dir_path( __FILE__ ) );
define( 'YTEP_URL', plugin_dir_url( __FILE__ ) );

require_once YTEP_PATH . 'includes/class-ytep-parser.php';
require_once YTEP_PATH . 'includes/class-ytep-renderer.php';
require_once YTEP_PATH . 'includes/class-ytep-shortcode.php';
require_once YTEP_PATH . 'includes/class-ytep-block.php';
require_once YTEP_PATH . 'includes/class-ytep-settings.php';

add_action( 'init', array( 'YTEP_Shortcode', 'init' ) );
add_action( 'init', array( 'YTEP_Block', 'init' ) );
add_action( 'init', array( 'YTEP_Settings', 'init' ) );
add_action( 'wp_enqueue_scripts', array( 'YTEP_Renderer', 'register_assets' ) );
add_action( 'enqueue_block_assets', array( 'YTEP_Renderer', 'register_assets' ) );

/**
 * Hand the saved channel list to the block editor. wp_localize_script prints
 * the data as a JS object (window.ytepData) just before the editor script,
 * JSON-encoded by WordPress so nothing can break out into script context.
 * "ytep-embed-editor-script" is the handle WordPress auto-generates for the
 * editorScript declared in blocks/embed/block.json.
 */
add_action(
	'enqueue_block_editor_assets',
	function () {
		wp_localize_script(
			'ytep-embed-editor-script',
			'ytepData',
			array(
				'channels'    => YTEP_Settings::get_channels(),
				'settingsUrl' => admin_url( 'options-general.php?page=ytep-settings' ),
			)
		);
	}
);
