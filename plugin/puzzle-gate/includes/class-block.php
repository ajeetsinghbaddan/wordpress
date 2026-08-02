<?php
/**
 * The puzzle-gate/gate block.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

class Block {

	const NAME = 'puzzle-gate/gate';

	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ) );

		/*
		 * `render_block_data` runs on every block just before it renders. We use
		 * it to delete the hidden inner blocks from the render tree entirely.
		 *
		 * Ignoring the rendered content in the render callback would already be
		 * safe — nothing would be printed. But rendering it anyway is both
		 * wasteful and leaky in a subtler way: blocks have side effects. A
		 * gallery inside the gate would enqueue lightbox scripts; an embed would
		 * hit an oEmbed endpoint; a form block might register itself in the
		 * footer. Any of those tells an observer what is behind the lock without
		 * showing it. Stripping the subtree stops all of that at the source.
		 */
		add_filter( 'render_block_data', array( $this, 'strip_locked_inner_blocks' ) );
	}

	/**
	 * Register the block from its block.json.
	 *
	 * Passing a directory to register_block_type() tells WordPress to read
	 * block.json for the name, attributes, supports and asset handles. That one
	 * file is then the single source of truth shared by PHP and JavaScript —
	 * which is why the editor and the server can never disagree about what
	 * attributes exist or what their defaults are.
	 */
	public function register(): void {
		// Editor-only styling for the placeholder shell inside the canvas.
		wp_register_style(
			'puzzle-gate-editor',
			PUZZLE_GATE_URL . 'assets/css/puzzle-gate-editor.css',
			array(),
			PUZZLE_GATE_VERSION
		);

		register_block_type(
			PUZZLE_GATE_DIR . 'blocks/gate',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Server-side render.
	 *
	 * A "dynamic block" is one whose front-end HTML is produced by PHP at
	 * request time rather than being saved into post_content. That is exactly
	 * the property this plugin needs: the author's inner blocks are stored in
	 * the database, but what the visitor receives is decided here, per request,
	 * per visitor.
	 *
	 * @param array     $attributes Block attributes, defaults already applied.
	 * @param string    $content    Rendered inner blocks (empty while locked — see the filter above).
	 * @param \WP_Block $block      Block instance.
	 */
	public function render( array $attributes, string $content, $block = null ): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$gate_id = sanitize_key( (string) ( $attributes['gateId'] ?? '' ) );
		if ( '' === $gate_id ) {
			// A gate with no id cannot be looked up again, so it can never be
			// unlocked. Fail visibly for editors, silently for everyone else.
			return current_user_can( 'edit_post', $post->ID )
				? '<p class="pgz__noscript">' . esc_html__( 'Puzzle Gate: this block has no id. Re-save the post to generate one.', 'puzzle-gate' ) . '</p>'
				: '';
		}

		$type = sanitize_key( (string) ( $attributes['type'] ?? 'slide' ) );
		if ( ! Puzzle_Registry::get( $type ) ) {
			$type = 'slide';
		}

		// Unlocked states: the inner blocks survived the strip filter, so
		// $content already holds the rendered HTML. No second parse needed.
		if ( $this->is_unlocked( $post, $gate_id ) ) {
			return current_user_can( 'edit_post', $post->ID ) && Plugin::option( 'editor_preview' )
				? Lock_View::preview( $content )
				: Lock_View::revealed( $content );
		}

		wp_enqueue_style( 'puzzle-gate' );
		wp_enqueue_script( 'puzzle-gate' );

		/*
		 * get_block_wrapper_attributes() emits the class and style attributes
		 * that come from block supports (alignment, colours, spacing). Letting
		 * core generate them means the block honours theme.json and the block
		 * toolbar without us reimplementing any of it.
		 */
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

		return Lock_View::render(
			$post->ID,
			$gate_id,
			$type,
			array(
				'title'  => (string) ( $attributes['title'] ?? '' ),
				'teaser' => (string) ( $attributes['teaser'] ?? '' ),
				'button' => (string) ( $attributes['buttonText'] ?? '' ),
			),
			$wrapper
		);
	}

	/**
	 * Remove the hidden subtree before WordPress renders it.
	 *
	 * Keep the early return cheap: this filter fires for every block on the
	 * page, so the name comparison must be the first thing that happens.
	 */
	public function strip_locked_inner_blocks( $parsed_block ) {
		if ( ! is_array( $parsed_block ) || self::NAME !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}

		$post    = get_post();
		$gate_id = sanitize_key( (string) ( $parsed_block['attrs']['gateId'] ?? '' ) );

		if ( $post instanceof \WP_Post && $this->is_unlocked( $post, $gate_id ) ) {
			return $parsed_block;
		}

		$parsed_block['innerBlocks']  = array();
		$parsed_block['innerContent'] = array();
		$parsed_block['innerHTML']    = '';

		return $parsed_block;
	}

	private function is_unlocked( \WP_Post $post, string $gate_id ): bool {
		if ( '' === $gate_id ) {
			return false;
		}
		if ( Plugin::option( 'editor_preview' ) && current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}
		return Session::has_pass( Session::gate_key( $post->ID, $gate_id ) );
	}
}
