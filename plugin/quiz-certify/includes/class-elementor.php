<?php
/**
 * Elementor integration.
 *
 * This controller is always loaded, but it is inert unless Elementor is active: the
 * 'elementor/widgets/register' hook only fires when Elementor is present. The actual
 * widget classes (which extend \Elementor\Widget_Base) live in a separate file that is
 * required ONLY inside that callback, so a site without Elementor never tries to extend
 * a class that doesn't exist.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Elementor {

	/**
	 * Hook into Elementor's widget registration.
	 */
	public static function register() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
	}

	/**
	 * Register the native Elementor widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's widget manager.
	 */
	public static function register_widgets( $widgets_manager ) {
		// Safe to load here: Elementor's base classes are guaranteed to exist by now.
		require_once QUIZ_CERTIFY_PATH . 'includes/elementor-widgets.php';

		$widgets_manager->register( new QC_Elementor_Quiz() );
		$widgets_manager->register( new QC_Elementor_Quiz_List() );
	}
}
