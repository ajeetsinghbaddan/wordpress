<?php
/**
 * [yt_embed] shortcode.
 *
 * @package YouTube_Embed_Pro
 */

defined( 'ABSPATH' ) || exit;

class YTEP_Shortcode {

	public static function init() {
		add_shortcode( 'yt_embed', array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		// shortcode_atts() throws away any attribute we did not declare,
		// so unexpected keys can never reach the renderer.
		$atts = shortcode_atts(
			array(
				'url'       => '',
				'channel'   => '',
				'type'      => 'auto',
				'title'     => '',
				'ratio'     => '',
				'max_width' => '',
				'start'     => '',
				'privacy'   => 'yes',
				'facade'    => 'yes',
				'autoplay'  => 'no',
				'loop'      => 'no',
				'controls'  => 'yes',
				'captions'  => 'no',
				'class'     => '',
			),
			$atts,
			'yt_embed'
		);

		return YTEP_Renderer::render(
			array(
				'url'       => $atts['url'],
				'channel'   => $atts['channel'],
				'kind'      => $atts['type'],
				'title'     => $atts['title'],
				'ratio'     => $atts['ratio'],
				'max_width' => $atts['max_width'],
				'start'     => YTEP_Parser::parse_time( $atts['start'] ),
				'privacy'   => $atts['privacy'],
				'facade'    => $atts['facade'],
				'autoplay'  => $atts['autoplay'],
				'loop'      => $atts['loop'],
				'controls'  => $atts['controls'],
				'captions'  => $atts['captions'],
				'class'     => $atts['class'],
			)
		);
	}
}
