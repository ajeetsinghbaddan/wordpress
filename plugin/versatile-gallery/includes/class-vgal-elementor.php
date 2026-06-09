<?php
/**
 * Elementor bootstrap.
 *
 * This file is always loaded, but it must NOT reference any Elementor class at
 * parse time (that would fatal-error on sites without Elementor). So all it
 * does is attach a callback to "elementor/widgets/register" — a hook that
 * Elementor itself fires only when it is active. The actual widget class lives
 * in a separate file we include lazily, at the moment that hook runs.
 *
 * @package VersatileGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VGAL_Elementor {

	/**
	 * Attach the registration hook. Safe to call unconditionally.
	 */
	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Load and register the widget. Runs only inside Elementor, so by now
	 * \Elementor\Widget_Base definitely exists.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's widget manager.
	 */
	public static function register_widget( $widgets_manager ) {
		if ( ! class_exists( 'VGAL_Elementor_Widget' ) ) {
			require_once VGAL_PLUGIN_DIR . 'includes/class-vgal-elementor-widget.php';
		}
		$widgets_manager->register( new VGAL_Elementor_Widget() );
	}
}
