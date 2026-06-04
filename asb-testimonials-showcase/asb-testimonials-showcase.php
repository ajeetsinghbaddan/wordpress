<?php
/**
 * Plugin Name:       ASB Testimonials Showcase
 * Plugin URI:        https://example.com/asb-testimonials-showcase
 * Description:        Create, manage and display client testimonials in six responsive, accessible layouts via shortcode, Gutenberg block, or Elementor widget.
 * Version:           1.0.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ASB
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       asb-testimonials-showcase
 * Domain Path:       /languages
 *
 * @package ASB_Testimonials_Showcase
 */

/*
 * SECURITY: Block direct file access.
 * ABSPATH is only defined when WordPress core has bootstrapped. If someone
 * points a browser straight at this PHP file, ABSPATH is undefined and we
 * exit immediately so none of the code below ever runs out of context.
 * Every PHP file in this plugin starts with this same guard.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Plugin constants.
 * ---------------------------------------------------------------------------
 * We centralise version, paths and URLs as constants so the rest of the
 * codebase never has to recompute them. The "ASB_TS_" prefix namespaces our
 * constants to avoid collisions with other plugins/themes.
 *
 * - ASB_TS_VERSION is used to "cache-bust" enqueued CSS/JS: when we bump the
 *   version, browsers fetch fresh assets instead of stale cached copies.
 * - plugin_dir_path() returns the server filesystem path (for require/include).
 * - plugin_dir_url() returns the public URL (for enqueuing assets).
 * - plugin_basename() yields "folder/file.php", needed by activation hooks.
 */
define( 'ASB_TS_VERSION', '1.0.6' );
define( 'ASB_TS_FILE', __FILE__ );
define( 'ASB_TS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASB_TS_URL', plugin_dir_url( __FILE__ ) );
define( 'ASB_TS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load the main plugin class file.
 *
 * We deliberately require the bootstrap class directly rather than relying on a
 * fancy autoloader, because there is exactly one entry point and it keeps the
 * load order explicit and easy to audit. The bootstrap class itself wires up
 * (requires + instantiates) every other component.
 */
require_once ASB_TS_PATH . 'includes/class-asb-testimonials.php';

/**
 * Activation hook.
 *
 * Runs ONCE when the plugin is activated. We use a static method so WordPress
 * can call it without us having to build the whole plugin object first.
 *
 * Why flush rewrite rules here? Registering a Custom Post Type adds new URL
 * "rewrite" rules (e.g. /testimonial/...). Those rules are cached in the DB and
 * only regenerated on demand. If we don't flush them on activation, the new CPT
 * URLs would 404 until the user manually re-saves their permalink settings.
 * IMPORTANT: the CPT must be registered *before* we flush, so we register it
 * inside the activation routine too.
 */
register_activation_hook( __FILE__, array( 'ASB_Testimonials', 'activate' ) );

/**
 * Deactivation hook.
 *
 * Runs when the plugin is deactivated (NOT deleted). We only flush rewrite
 * rules so the CPT URLs are cleaned out of the cache. We deliberately do NOT
 * delete any data here — destructive cleanup belongs in uninstall.php, which
 * only runs when the user actually deletes the plugin.
 */
register_deactivation_hook( __FILE__, array( 'ASB_Testimonials', 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * We hook instantiation to 'plugins_loaded' (rather than running it inline) so
 * that all other plugins — most importantly Elementor — have had a chance to
 * load first. This lets us reliably detect Elementor before registering our
 * Elementor widget. The singleton pattern (instance()) guarantees the plugin
 * is only ever built once per request.
 */
function asb_ts_bootstrap() {
	return ASB_Testimonials::instance();
}
add_action( 'plugins_loaded', 'asb_ts_bootstrap' );
