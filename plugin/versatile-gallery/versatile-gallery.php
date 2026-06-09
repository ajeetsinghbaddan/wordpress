<?php
/**
 * Plugin Name:       Versatile Gallery
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/versatile-gallery
 * Description:       A secure, lightweight image gallery available as a Gutenberg block, an Elementor widget, and a shortcode.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       versatile-gallery
 * Domain Path:       /languages
 *
 * @package VersatileGallery
 */

// SECURITY: bail if this file is requested directly. When WordPress loads a
// plugin, ABSPATH is always defined. If someone navigates straight to this
// PHP file, ABSPATH is missing and we stop before any code runs.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin-wide constants. __FILE__ is this file's absolute path; the helper
// functions turn it into the plugin's directory path and public URL.
define( 'VGAL_VERSION', '1.0.0' );
define( 'VGAL_PLUGIN_FILE', __FILE__ );
define( 'VGAL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ); // e.g. .../wp-content/plugins/versatile-gallery/
define( 'VGAL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );  // e.g. https://site.com/wp-content/plugins/versatile-gallery/

// Load the shared renderer (the engine every consumer uses) and the
// Elementor integration glue.
require_once VGAL_PLUGIN_DIR . 'includes/class-vgal-renderer.php';
require_once VGAL_PLUGIN_DIR . 'includes/class-vgal-elementor.php';

/**
 * Register the frontend CSS + JS as named "handles".
 *
 * Registering is not the same as loading: here we only tell WordPress these
 * files exist and give them a handle. We actually enqueue (load) them lazily
 * at render time, so pages with no gallery never download the assets.
 *
 * Hooked at priority 5 so the handles exist before the block (priority 10)
 * references them in its block.json.
 */
function vgal_register_assets() {
	wp_register_style(
		'versatile-gallery',                       // handle other code refers to
		VGAL_PLUGIN_URL . 'assets/css/gallery.css', // file URL
		array(),                                    // dependencies
		VGAL_VERSION                                // version (cache-busting)
	);

	wp_register_script(
		'versatile-gallery',
		VGAL_PLUGIN_URL . 'assets/js/gallery.js',
		array(),
		VGAL_VERSION,
		true // true = load in the footer, after the markup exists
	);
}
add_action( 'init', 'vgal_register_assets', 5 );

/**
 * Register the dynamic Gutenberg block.
 *
 * Passing the block's directory lets WordPress read blocks/gallery/block.json,
 * which declares the attributes, the editor script, the frontend style/script
 * handles, and the server render file (render.php). Because block.json points
 * at render.php, this is a "dynamic" block: its HTML is produced by PHP on each
 * request rather than being frozen into the post content.
 */
function vgal_register_block() {
	register_block_type( VGAL_PLUGIN_DIR . 'blocks/gallery' );
}
add_action( 'init', 'vgal_register_block' );

/**
 * Register the [versatile_gallery] shortcode.
 *
 * The shortcode is the universal fallback: it works in the Classic editor,
 * inside widgets, and in theme template files via do_shortcode().
 */
function vgal_register_shortcode() {
	add_shortcode( 'versatile_gallery', 'vgal_shortcode_handler' );
}
add_action( 'init', 'vgal_register_shortcode' );

/**
 * Shortcode callback.
 *
 * shortcode_atts() merges the user's attributes over a set of defaults, so
 * unknown keys are dropped and missing keys get a default. Every value still
 * arrives as a string, so the renderer is responsible for typing/sanitizing.
 *
 * @param array|string $atts Raw shortcode attributes.
 * @return string Safe HTML.
 */
function vgal_shortcode_handler( $atts ) {
	$atts = shortcode_atts(
		array(
			'ids'      => '',
			'columns'  => 3,
			'gap'      => 12,
			'size'     => 'medium',
			'layout'   => 'grid',
			'lightbox' => 'true',
		),
		$atts,
		'versatile_gallery'
	);

	return VGAL_Renderer::render( $atts );
}

/**
 * Wire up the Elementor widget.
 *
 * VGAL_Elementor::init() only registers a hook that Elementor itself fires,
 * so nothing breaks when Elementor is not installed.
 */
VGAL_Elementor::init();
