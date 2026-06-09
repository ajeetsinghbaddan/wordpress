<?php
/**
 * Registers and conditionally enqueues front-end CSS/JS.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Assets
 *
 * Strategy for "assets on demand": we REGISTER our stylesheet and script early
 * (on wp_enqueue_scripts) but do NOT enqueue them there. They are only enqueued
 * the moment the renderer actually outputs testimonials on a page. That way a
 * page with no testimonials ships zero extra CSS/JS — better performance and a
 * smaller front-end footprint.
 *
 * Registering early + enqueuing at render time is the standard WordPress way to
 * achieve conditional asset loading for shortcodes/blocks/widgets.
 */
class ASB_TS_Assets {

	const STYLE_HANDLE  = 'asb-ts-frontend';
	const SCRIPT_HANDLE = 'asb-ts-frontend-js';

	/**
	 * Register (but don't enqueue) assets on the front end.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register the handles so they are known to WordPress and can be enqueued
	 * later by handle name. Versioning with ASB_TS_VERSION busts caches on update.
	 */
	public function register_assets() {
		wp_register_style(
			self::STYLE_HANDLE,
			ASB_TS_URL . 'assets/css/asb-testimonials.css',
			array(),
			ASB_TS_VERSION
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			ASB_TS_URL . 'assets/js/asb-testimonials.js',
			array( 'jquery' ), // Depend on WordPress' bundled jQuery (loaded first).
			ASB_TS_VERSION,
			true               // Load in the footer so it never blocks rendering.
		);
	}

	/**
	 * Enqueue the assets on demand. Called by the renderer the first time it
	 * outputs any layout on the current request. wp_enqueue_* is safe to call
	 * more than once — WordPress de-duplicates by handle.
	 *
	 * Because shortcodes/blocks render during 'the_content' (after the normal
	 * enqueue phase), enqueuing here still works: scripts print in the footer,
	 * and WordPress will print our (already registered) style as well.
	 */
	public function enqueue_frontend() {
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}
}
