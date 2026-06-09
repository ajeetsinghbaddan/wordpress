<?php
/**
 * Elementor integration: registers a Testimonials widget when Elementor is active.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Elementor
 *
 * This is a thin "gatekeeper". Elementor classes (like \Elementor\Widget_Base)
 * only exist when Elementor is installed and loaded, so we must NOT reference
 * them at file-parse time. Instead we:
 *   1. Check did_action('elementor/loaded') — true once Elementor has booted.
 *   2. Only then hook 'elementor/widgets/register', and only INSIDE that hook
 *      do we define + instantiate the widget class (which extends an Elementor
 *      class). This guarantees the parent class exists before we subclass it.
 */
class ASB_TS_Elementor {

	/**
	 * @var ASB_TS_Renderer
	 */
	private $renderer;

	/**
	 * Static holder so the widget (instantiated by Elementor) can reach the
	 * shared renderer without us threading it through Elementor's constructor.
	 *
	 * @var ASB_TS_Renderer
	 */
	public static $shared_renderer;

	/**
	 * @param ASB_TS_Renderer $renderer Shared renderer.
	 */
	public function __construct( $renderer ) {
		$this->renderer        = $renderer;
		self::$shared_renderer = $renderer;

		// Bail immediately if Elementor isn't present — nothing else loads.
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		// Register our widget with Elementor's widget manager.
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
	}

	/**
	 * Define the widget class (only now that Elementor is guaranteed loaded) and
	 * register an instance with the manager.
	 *
	 * @param object $widgets_manager Elementor's widgets manager.
	 */
	public function register_widget( $widgets_manager ) {
		// Load the widget class definition only when Elementor exists.
		require_once ASB_TS_PATH . 'elementor/class-asb-elementor-widget.php';
		$widgets_manager->register( new ASB_TS_Elementor_Widget() );
	}
}
