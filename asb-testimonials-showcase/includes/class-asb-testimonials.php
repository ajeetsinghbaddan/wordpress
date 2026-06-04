<?php
/**
 * Main bootstrap class for ASB Testimonials Showcase.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_Testimonials
 *
 * Acts as the central "kernel" of the plugin. Its single responsibility is to
 * load every component file and create one instance of each component class.
 * Each component (CPT, meta boxes, settings, renderer, etc.) registers its own
 * WordPress hooks inside its constructor, keeping concerns cleanly separated.
 */
final class ASB_Testimonials {

	/**
	 * The single shared instance of this class (singleton).
	 *
	 * @var ASB_Testimonials|null
	 */
	private static $instance = null;

	/**
	 * Holds the instantiated component objects, keyed by a short name.
	 * Storing them lets one component reach another if ever needed.
	 *
	 * @var array
	 */
	public $components = array();

	/**
	 * Return the one and only instance, creating it on first call.
	 *
	 * The singleton pattern prevents the plugin from being booted twice (which
	 * would register every hook twice and cause duplicate output).
	 *
	 * @return ASB_Testimonials
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor is private so the only way to create the object is through
	 * instance(). It loads dependencies, wires up components and i18n.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_components();

		// Load translations on init so the text domain is ready before strings are used.
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * require_once every class file the plugin needs.
	 *
	 * The order matters a little: helper/CPT classes are loaded before the
	 * classes that depend on them. Each file only DEFINES a class here; nothing
	 * is instantiated until init_components().
	 */
	private function load_dependencies() {
		require_once ASB_TS_PATH . 'includes/class-asb-cpt.php';
		require_once ASB_TS_PATH . 'includes/class-asb-meta-boxes.php';
		require_once ASB_TS_PATH . 'includes/class-asb-settings.php';
		require_once ASB_TS_PATH . 'includes/class-asb-assets.php';
		require_once ASB_TS_PATH . 'includes/class-asb-renderer.php';
		require_once ASB_TS_PATH . 'includes/class-asb-shortcode.php';
		require_once ASB_TS_PATH . 'includes/class-asb-block.php';
		require_once ASB_TS_PATH . 'includes/class-asb-elementor.php';
	}

	/**
	 * Instantiate each component once and stash it in $this->components.
	 *
	 * Creating the object triggers that component's constructor, which is where
	 * it adds its own add_action()/add_filter() hooks. This is the "composition
	 * root" of the plugin — the single place where everything is assembled.
	 */
	private function init_components() {
		$this->components['cpt']        = new ASB_TS_CPT();
		$this->components['meta_boxes'] = new ASB_TS_Meta_Boxes();
		$this->components['settings']   = new ASB_TS_Settings();
		$this->components['assets']     = new ASB_TS_Assets();
		// The renderer turns a set of query args into HTML for any of the 6 layouts.
		$this->components['renderer']   = new ASB_TS_Renderer( $this->components['assets'] );
		// Shortcode + block + Elementor all delegate their actual HTML to the renderer.
		$this->components['shortcode']  = new ASB_TS_Shortcode( $this->components['renderer'] );
		$this->components['block']      = new ASB_TS_Block( $this->components['renderer'] );
		$this->components['elementor']  = new ASB_TS_Elementor( $this->components['renderer'] );
	}

	/**
	 * Load the plugin's translation files (.mo) from /languages.
	 *
	 * This is what makes the plugin "i18n-ready": every user-facing string in
	 * the codebase is wrapped in translation functions like __() or esc_html__()
	 * with the 'asb-testimonials-showcase' text domain, and load_plugin_textdomain
	 * swaps in the translated strings for the active site language.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'asb-testimonials-showcase',
			false,
			dirname( ASB_TS_BASENAME ) . '/languages'
		);
	}

	/**
	 * Activation routine (called statically from the activation hook).
	 *
	 * We register the CPT + taxonomy here too, then flush rewrite rules so the
	 * new URL structures are baked into WordPress immediately. Without this the
	 * pretty permalinks for the CPT would 404 until permalinks are re-saved.
	 */
	public static function activate() {
		// Make sure the CPT class is available even though the plugin object
		// may not have been fully constructed during activation.
		require_once ASB_TS_PATH . 'includes/class-asb-cpt.php';
		$cpt = new ASB_TS_CPT();
		$cpt->register_post_type();
		$cpt->register_taxonomy();

		// Seed default options the first time the plugin is activated so the
		// settings page and front end have sensible defaults from day one.
		require_once ASB_TS_PATH . 'includes/class-asb-settings.php';
		ASB_TS_Settings::maybe_set_default_options();

		flush_rewrite_rules();
	}

	/**
	 * Deactivation routine. Only clears rewrite rules; never deletes data.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
