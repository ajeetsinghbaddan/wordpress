<?php
/**
 * Registers the server-rendered Gutenberg block.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Block
 *
 * Registers a block whose markup is generated in PHP at display time
 * (server-side rendering). Server-side rendering is ideal here because:
 *   - The output depends on live data (testimonials) that can change after the
 *     post is saved — SSR always reflects the current testimonials.
 *   - We reuse the exact same renderer as the shortcode, so there's one code
 *     path and no duplicated, drift-prone markup in JavaScript.
 *
 * The editor side is a small vanilla-JS script (no JSX/build tooling) that shows
 * inspector controls and a live ServerSideRender preview.
 */
class ASB_TS_Block {

	/**
	 * @var ASB_TS_Renderer
	 */
	private $renderer;

	/**
	 * @param ASB_TS_Renderer $renderer Shared renderer.
	 */
	public function __construct( $renderer ) {
		$this->renderer = $renderer;
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the block from its block.json metadata.
	 *
	 * register_block_type() reading a block.json directory is the modern,
	 * recommended approach: block.json declares the attributes, the editor
	 * script and the styles, and WordPress wires them up. We pass a PHP
	 * render_callback so the front-end HTML comes from our renderer.
	 */
	public function register_block() {
		// register_block_type can take the path to the folder holding block.json.
		register_block_type(
			ASB_TS_PATH . 'blocks/testimonials',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * WordPress passes the block's saved attributes (already coerced to the
	 * types declared in block.json) as $attributes. We map them to renderer args
	 * and let the renderer perform the authoritative validation + escaping.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public function render_block( $attributes ) {
		$args = array(
			'design'   => isset( $attributes['design'] ) ? sanitize_key( $attributes['design'] ) : '',
			'category' => isset( $attributes['category'] ) ? absint( $attributes['category'] ) : 0,
			'count'    => isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 0,
		);

		// Drop zero/empty so site defaults apply for unset controls.
		$args = array_filter(
			$args,
			static function ( $value ) {
				return ! empty( $value );
			}
		);

		$html = $this->renderer->render( $args );

		/*
		 * Wrap the output with the standard block wrapper. get_block_wrapper_attributes()
		 * returns the (already-escaped) class/style string WordPress expects on a
		 * block's root element — this is what makes alignment (wide/full) and other
		 * block supports work, and identifies the element as our block in the DOM.
		 * The inner .asb-ts markup keeps full control of the actual layout.
		 */
		$wrapper_attributes = get_block_wrapper_attributes();

		// $wrapper_attributes is pre-escaped by core; $html is escaped by the renderer.
		return sprintf( '<div %1$s>%2$s</div>', $wrapper_attributes, $html );
	}
}
