<?php
/**
 * ytep/embed block. The block is "dynamic": nothing but an HTML comment with
 * attributes is saved in post content, and the markup is generated at render
 * time by PHP. That means saved content can never contain an iframe someone
 * hand-edited into the database.
 *
 * @package YouTube_Embed_Pro
 */

defined( 'ABSPATH' ) || exit;

class YTEP_Block {

	public static function init() {
		register_block_type(
			YTEP_PATH . 'blocks/embed',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * @param array $attributes Attributes validated against block.json by WordPress.
	 * @return string
	 */
	public static function render( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		$html = YTEP_Renderer::render(
			array(
				'url'       => isset( $attributes['url'] ) ? $attributes['url'] : '',
				'channel'   => isset( $attributes['channel'] ) ? $attributes['channel'] : '',
				'kind'      => isset( $attributes['kind'] ) ? $attributes['kind'] : 'auto',
				'title'     => isset( $attributes['title'] ) ? $attributes['title'] : '',
				'ratio'     => isset( $attributes['ratio'] ) ? $attributes['ratio'] : '',
				'max_width' => isset( $attributes['maxWidth'] ) ? $attributes['maxWidth'] : 0,
				'start'     => isset( $attributes['start'] ) ? $attributes['start'] : 0,
				'privacy'   => isset( $attributes['privacy'] ) ? $attributes['privacy'] : true,
				'facade'    => isset( $attributes['facade'] ) ? $attributes['facade'] : true,
				'autoplay'  => isset( $attributes['autoplay'] ) ? $attributes['autoplay'] : false,
				'loop'      => isset( $attributes['loop'] ) ? $attributes['loop'] : false,
				'controls'  => isset( $attributes['controls'] ) ? $attributes['controls'] : true,
				'captions'  => isset( $attributes['captions'] ) ? $attributes['captions'] : false,
			)
		);

		if ( '' === $html ) {
			return '';
		}

		// Adds the alignment / spacing / custom class attributes the editor set.
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}
}
