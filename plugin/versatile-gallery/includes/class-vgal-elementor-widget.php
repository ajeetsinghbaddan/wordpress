<?php
/**
 * Versatile Gallery — Elementor widget.
 *
 * Loaded ONLY from VGAL_Elementor::register_widget(), which runs inside
 * Elementor. That is why it is safe to extend \Elementor\Widget_Base here.
 *
 * @package VersatileGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VGAL_Elementor_Widget extends \Elementor\Widget_Base {

	/** Machine name used internally by Elementor. */
	public function get_name() {
		return 'versatile_gallery';
	}

	/** Human label shown in the Elementor panel. */
	public function get_title() {
		return esc_html__( 'Versatile Gallery', 'versatile-gallery' );
	}

	/** Icon shown in the widget list (an Elementor icon class). */
	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	/** Which panel category the widget appears under. */
	public function get_categories() {
		return array( 'general' );
	}

	/** Search terms that surface this widget. */
	public function get_keywords() {
		return array( 'gallery', 'image', 'photos', 'lightbox' );
	}

	/**
	 * Define the controls (the editing UI) shown in the Elementor panel.
	 * Each add_control() call creates one input bound to a setting key.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Gallery', 'versatile-gallery' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		// GALLERY control = Elementor's native multi-image picker.
		$this->add_control(
			'images',
			array(
				'label'   => esc_html__( 'Add images', 'versatile-gallery' ),
				'type'    => \Elementor\Controls_Manager::GALLERY,
				'default' => array(),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => esc_html__( 'Layout', 'versatile-gallery' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => array(
					'grid'      => esc_html__( 'Uniform grid', 'versatile-gallery' ),
					'masonry'   => esc_html__( 'Masonry', 'versatile-gallery' ),
					'justified' => esc_html__( 'Justified rows', 'versatile-gallery' ),
					'mosaic'    => esc_html__( 'Mosaic (featured)', 'versatile-gallery' ),
					'carousel'  => esc_html__( 'Carousel', 'versatile-gallery' ),
					'captions'  => esc_html__( 'Grid with captions', 'versatile-gallery' ),
				),
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => esc_html__( 'Columns', 'versatile-gallery' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 6,
				'default' => 3,
			)
		);

		$this->add_control(
			'gap',
			array(
				'label'   => esc_html__( 'Gap (px)', 'versatile-gallery' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 0,
				'max'     => 80,
				'default' => 12,
			)
		);

		$this->add_control(
			'size',
			array(
				'label'   => esc_html__( 'Image size', 'versatile-gallery' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'medium',
				'options' => array(
					'thumbnail' => esc_html__( 'Thumbnail', 'versatile-gallery' ),
					'medium'    => esc_html__( 'Medium', 'versatile-gallery' ),
					'large'     => esc_html__( 'Large', 'versatile-gallery' ),
					'full'      => esc_html__( 'Full', 'versatile-gallery' ),
				),
			)
		);

		$this->add_control(
			'lightbox',
			array(
				'label'        => esc_html__( 'Enable lightbox', 'versatile-gallery' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'versatile-gallery' ),
				'label_off'    => esc_html__( 'No', 'versatile-gallery' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Output the widget on the frontend.
	 *
	 * We translate Elementor's settings into the shape our renderer expects and
	 * hand off. The renderer re-sanitizes and escapes, so this method does not
	 * need to build any HTML itself — and the output looks identical to the
	 * block and shortcode.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// The GALLERY control returns an array of { id, url } items; we want IDs.
		$ids = array();
		if ( ! empty( $settings['images'] ) && is_array( $settings['images'] ) ) {
			foreach ( $settings['images'] as $image ) {
				if ( isset( $image['id'] ) ) {
					$ids[] = (int) $image['id'];
				}
			}
		}

		echo VGAL_Renderer::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns fully-escaped HTML.
			array(
				'ids'      => $ids,
				'columns'  => isset( $settings['columns'] ) ? $settings['columns'] : 3,
				'gap'      => isset( $settings['gap'] ) ? $settings['gap'] : 12,
				'size'     => isset( $settings['size'] ) ? $settings['size'] : 'medium',
				'layout'   => isset( $settings['layout'] ) ? $settings['layout'] : 'grid',
				'lightbox' => ( isset( $settings['lightbox'] ) && 'yes' === $settings['lightbox'] ),
			)
		);
	}
}
