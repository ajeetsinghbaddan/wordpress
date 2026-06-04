<?php
/**
 * Elementor widget class. Loaded ONLY when Elementor is active.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Elementor_Widget
 *
 * Extends \Elementor\Widget_Base (safe to reference here because this file is
 * only required from inside the 'elementor/widgets/register' hook, by which
 * point Elementor's base class is defined).
 *
 * The widget exposes the same three options as the shortcode and block (design,
 * category, count) through Elementor's controls API, then delegates rendering
 * to our shared renderer so the markup is identical everywhere.
 */
class ASB_TS_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Internal widget name (unique slug used by Elementor).
	 */
	public function get_name() {
		return 'asb_testimonials';
	}

	/**
	 * Human-readable title shown in the Elementor panel.
	 */
	public function get_title() {
		return esc_html__( 'Testimonials Showcase', 'asb-testimonials-showcase' );
	}

	/**
	 * Panel icon (Elementor bundles eicon-* icon fonts).
	 */
	public function get_icon() {
		return 'eicon-testimonial';
	}

	/**
	 * Which Elementor category the widget appears under.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords in the Elementor widget finder.
	 */
	public function get_keywords() {
		return array( 'testimonial', 'review', 'quote', 'slider' );
	}

	/**
	 * Build the list of design options for the dropdown control.
	 *
	 * @return array key => label
	 */
	private function get_design_options() {
		// Reuse the renderer's single source of truth for valid designs.
		return ASB_TS_Renderer::get_designs();
	}

	/**
	 * Build the list of category options (term_id => name), with 0 = all.
	 *
	 * @return array
	 */
	private function get_category_options() {
		$options = array( 0 => esc_html__( 'All categories', 'asb-testimonials-showcase' ) );

		$terms = get_terms(
			array(
				'taxonomy'   => ASB_TS_CPT::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ $term->term_id ] = $term->name;
			}
		}

		return $options;
	}

	/**
	 * Register the widget's controls (the editing UI in Elementor's panel).
	 *
	 * start_controls_section / add_control / end_controls_section is Elementor's
	 * standard pattern. Each control's value is read later in render() via
	 * get_settings_for_display().
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Testimonials', 'asb-testimonials-showcase' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'design',
			array(
				'label'   => esc_html__( 'Design', 'asb-testimonials-showcase' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => $this->get_design_options(),
			)
		);

		$this->add_control(
			'category',
			array(
				'label'   => esc_html__( 'Category', 'asb-testimonials-showcase' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 0,
				'options' => $this->get_category_options(),
			)
		);

		$this->add_control(
			'count',
			array(
				'label'   => esc_html__( 'Number to show', 'asb-testimonials-showcase' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 50,
				'default' => 6,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget on the front end (and in the Elementor preview).
	 *
	 * We read the saved control values, sanitise them, then hand them to the
	 * shared renderer. The renderer performs the authoritative validation and
	 * escapes all output, so we can safely echo its return value.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$args = array(
			'design'   => isset( $settings['design'] ) ? sanitize_key( $settings['design'] ) : '',
			'category' => isset( $settings['category'] ) ? absint( $settings['category'] ) : 0,
			'count'    => isset( $settings['count'] ) ? absint( $settings['count'] ) : 0,
		);

		// Remove empties so site defaults fill in any unset option.
		$args = array_filter(
			$args,
			static function ( $value ) {
				return ! empty( $value );
			}
		);

		// The renderer returns fully-escaped HTML. Echo it for Elementor.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes internally.
		echo ASB_TS_Elementor::$shared_renderer->render( $args );
	}
}
