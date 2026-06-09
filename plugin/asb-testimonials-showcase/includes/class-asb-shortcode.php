<?php
/**
 * Registers the [testimonials] shortcode.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Shortcode
 *
 * Provides [testimonials design="slider" category="clients" count="6"]. The
 * shortcode is intentionally thin: it just converts attributes into args and
 * hands them to the renderer, which does the querying, escaping and HTML.
 */
class ASB_TS_Shortcode {

	/**
	 * @var ASB_TS_Renderer
	 */
	private $renderer;

	/**
	 * @param ASB_TS_Renderer $renderer Shared renderer.
	 */
	public function __construct( $renderer ) {
		$this->renderer = $renderer;
		add_shortcode( 'testimonials', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * shortcode_atts() merges user-supplied attributes over our defaults and,
	 * crucially, DROPS any attribute the user invents that isn't in our list —
	 * so the attribute surface is limited to exactly what we expect. The
	 * renderer then re-validates each value (allow-listing design, clamping
	 * count, etc.), so even malformed shortcode input is handled safely.
	 *
	 * A shortcode callback must RETURN its HTML (never echo) so it lands in the
	 * right place within the_content.
	 *
	 * @param array|string $atts Raw shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'design'   => '',  // Empty -> renderer uses the site default.
				'category' => '',
				'count'    => '',
				'orderby'  => 'date',
				'order'    => 'DESC',
			),
			$atts,
			'testimonials'
		);

		// Light pre-sanitisation; the renderer performs the authoritative checks.
		$args = array(
			'design'   => sanitize_key( $atts['design'] ),
			'category' => sanitize_text_field( $atts['category'] ),
			'count'    => '' === $atts['count'] ? '' : absint( $atts['count'] ),
			'orderby'  => sanitize_key( $atts['orderby'] ),
			'order'    => sanitize_key( $atts['order'] ),
		);

		// Remove empty values so the renderer falls back to site defaults for them.
		$args = array_filter(
			$args,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		return $this->renderer->render( $args );
	}
}
