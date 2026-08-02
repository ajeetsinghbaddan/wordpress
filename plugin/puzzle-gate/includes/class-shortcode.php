<?php
/**
 * The [puzzle_gate] shortcode: renders the lock, never the secret.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

class Shortcode {

	public function hooks(): void {
		add_shortcode( Gate_Locator::SHORTCODE, array( $this, 'render' ) );

		/*
		 * Excerpts and feeds.
		 *
		 * get_the_excerpt() calls strip_shortcodes(), which removes an enclosing
		 * shortcode together with its content — so excerpts are already safe.
		 * We register the shortcode early enough that strip_shortcodes() knows
		 * about it, which is the part people usually get wrong: strip_shortcodes
		 * only strips *registered* tags.
		 */
	}

	/**
	 * @param array|string $atts    Attributes as parsed by WordPress.
	 * @param string|null  $content Enclosed content — the secret.
	 */
	public function render( $atts, $content = null ): string {
		// shortcode_atts() merges defaults, drops unknown keys and gives other
		// developers a filter hook. It also guarantees every key exists.
		$atts = shortcode_atts(
			array(
				'id'         => '',
				'type'       => Plugin::option( 'default_type' ),
				'title'      => __( 'Locked', 'puzzle-gate' ),
				'teaser'     => __( 'Solve the puzzle to open this section.', 'puzzle-gate' ),
				'button'     => __( 'Open the lock', 'puzzle-gate' ),
				'size'       => 3,
				'image'      => '',
				'question'   => '',
				'answer'     => '',
				'hint'       => '',
				'difficulty' => 'normal',
			),
			is_array( $atts ) ? $atts : array(),
			Gate_Locator::SHORTCODE
		);

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$type = sanitize_key( $atts['type'] );
		if ( ! Puzzle_Registry::get( $type ) ) {
			$type = 'slide';
		}

		$gate_id = Gate_Locator::derive_id( $this->raw_atts( $atts ) );

		/*
		 * CONVENIENCE: people who can edit the post see the content unlocked, so
		 * they are not forced to solve their own puzzle on every preview. This is
		 * a capability check against *this specific post*, not a role name —
		 * `edit_post` respects custom roles and multi-author setups correctly.
		 */
		if ( Plugin::option( 'editor_preview' ) && current_user_can( 'edit_post', $post->ID ) ) {
			wp_enqueue_style( 'puzzle-gate' );
			return Lock_View::preview( Gate_Locator::render_secret( (string) $content, $post ) );
		}

		// Already solved earlier in this browser? Render it straight away —
		// no request, no flash of a lock the visitor already opened.
		if ( Session::has_pass( Session::gate_key( $post->ID, $gate_id ) ) ) {
			return Lock_View::revealed( Gate_Locator::render_secret( (string) $content, $post ) );
		}

		// Late enqueue: assets load only on pages that actually contain a gate.
		wp_enqueue_style( 'puzzle-gate' );
		wp_enqueue_script( 'puzzle-gate' );

		return Lock_View::render(
			$post->ID,
			$gate_id,
			$type,
			array(
				'title'  => $atts['title'],
				'teaser' => $atts['teaser'],
				'button' => $atts['button'],
			)
		);
	}

	/**
	 * Rebuild the attribute array exactly as Gate_Locator will see it.
	 *
	 * shortcode_atts() has filled in defaults that were not written in the post,
	 * so hashing its output would produce a different id than hashing the raw
	 * attributes found in post_content. We therefore hash only the keys that the
	 * author actually typed. (Simplest fix of all: always set id="...".)
	 */
	private function raw_atts( array $atts ): array {
		if ( ! empty( $atts['id'] ) ) {
			return array( 'id' => $atts['id'] );
		}
		return array();
	}

}
