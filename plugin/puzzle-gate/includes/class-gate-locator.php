<?php
/**
 * Finds a gate inside a post's raw content, server-side only.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

/**
 * THE CENTRAL IDEA OF THIS PLUGIN
 *
 * Most "content locker" plugins print the secret content into the HTML and hide
 * it with CSS or JavaScript. That is not security — View Source defeats it in
 * two seconds.
 *
 * Here the secret text lives in exactly one place: the `post_content` column in
 * the database. When a page renders we output a placeholder and nothing else.
 * When the server later confirms a solve, it comes *back here*, re-reads the
 * post, extracts the enclosed content and returns it over a REST response.
 *
 * We deliberately do NOT copy the content into a transient at render time:
 *  - it would duplicate data that can go stale when the post is edited;
 *  - it would mean a database write on every page view;
 *  - under a full-page cache the shortcode may not run for hours, so a copy
 *    could expire while the cached page is still being served.
 * Re-parsing on demand is one cheap get_post() (usually already in object cache)
 * and it is always correct.
 */
class Gate_Locator {

	const SHORTCODE = 'puzzle_gate';

	/**
	 * Build the id a gate is addressed by.
	 *
	 * Ideally the author supplies `id="..."`. If not, we derive a stable id from
	 * the *attributes* rather than the content. Reason: by the time an enclosing
	 * shortcode callback runs, `the_content` filters (wptexturize, wpautop) have
	 * already rewritten the inner text, so a hash of the content would differ
	 * between render time and lookup time. Attribute strings survive those
	 * filters untouched, so hashing them gives the same id on both sides.
	 *
	 * @param array $atts Parsed shortcode attributes.
	 */
	public static function derive_id( array $atts ): string {
		if ( ! empty( $atts['id'] ) ) {
			return sanitize_key( $atts['id'] );
		}
		$copy = $atts;
		unset( $copy['id'] );
		ksort( $copy );
		return 'g' . substr( md5( wp_json_encode( $copy ) ), 0, 10 );
	}

	/**
	 * Look up one gate, whether it was authored as a shortcode or as a block.
	 *
	 * Both syntaxes resolve to the same shape, so everything downstream — the
	 * REST controller, the puzzle classes — stays unaware of which one the
	 * author used. Adding the block therefore required no changes at all to the
	 * security-critical code paths.
	 *
	 * @param int    $post_id Post that should contain the gate.
	 * @param string $gate_id Gate identifier.
	 * @return array|null array{source: string, atts: array, content: ?string, blocks: ?array}
	 */
	public static function find( int $post_id, string $gate_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! self::is_viewable( $post ) ) {
			return null;
		}

		$block = self::find_block( $post, $gate_id );
		if ( $block ) {
			return $block;
		}

		return self::find_shortcode( $post, $gate_id );
	}

	/**
	 * Walk the parsed block tree looking for our block.
	 *
	 * parse_blocks() turns post_content into a nested array without rendering
	 * anything — no shortcodes run, no scripts enqueue, no side effects. That
	 * makes it safe to inspect content we may end up not revealing.
	 */
	private static function find_block( \WP_Post $post, string $gate_id ): ?array {
		if ( ! has_blocks( $post ) ) {
			return null;
		}

		$found = self::walk_blocks( parse_blocks( $post->post_content ), $gate_id );
		if ( ! $found ) {
			return null;
		}

		/*
		 * Gutenberg omits attributes that still hold their default value, so
		 * $found['attrs'] is usually partial. prepare_attributes_for_render()
		 * fills in the defaults declared in block.json and coerces types. Using
		 * the registry rather than a hand-written defaults array means PHP and
		 * the editor can never disagree.
		 */
		$atts = $found['attrs'] ?? array();

		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( Block::NAME );

		if ( $block_type instanceof \WP_Block_Type ) {
			$atts = $block_type->prepare_attributes_for_render( $atts );
		}

		return array(
			'source'  => 'block',
			'atts'    => $atts,
			'content' => null,
			'blocks'  => $found['innerBlocks'] ?? array(),
		);
	}

	/** Depth-first search, so gates nested inside columns or groups still work. */
	private static function walk_blocks( array $blocks, string $gate_id ): ?array {
		foreach ( $blocks as $block ) {
			if (
				Block::NAME === ( $block['blockName'] ?? '' )
				&& sanitize_key( (string) ( $block['attrs']['gateId'] ?? '' ) ) === $gate_id
			) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$hit = self::walk_blocks( $block['innerBlocks'], $gate_id );
				if ( $hit ) {
					return $hit;
				}
			}
		}

		return null;
	}

	private static function find_shortcode( \WP_Post $post, string $gate_id ): ?array {
		/*
		 * get_shortcode_regex() builds the exact same pattern WordPress itself
		 * uses in do_shortcode(). Writing our own regex would drift from core's
		 * handling of nesting, escaping ([[foo]]) and self-closing tags.
		 *
		 * Capture groups: 1 = escaping "[", 2 = tag, 3 = attributes,
		 * 4 = self-closing slash, 5 = enclosed content, 6 = escaping "]".
		 */
		$pattern = get_shortcode_regex( array( self::SHORTCODE ) );

		if ( ! preg_match_all( '/' . $pattern . '/s', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $matches as $m ) {
			// [[puzzle_gate]] is an escaped literal, not a real shortcode.
			if ( '[' === $m[1] && ']' === $m[6] ) {
				continue;
			}

			$atts = shortcode_parse_atts( $m[3] );
			$atts = is_array( $atts ) ? $atts : array();

			if ( self::derive_id( $atts ) !== $gate_id ) {
				continue;
			}

			return array(
				'source'  => 'shortcode',
				'atts'    => $atts,
				'content' => $m[5],
				'blocks'  => null,
			);
		}

		return null;
	}

	/**
	 * Turn a located gate into display-ready HTML, whichever syntax it used.
	 *
	 * @param array    $gate Result of find().
	 * @param \WP_Post $post Owning post.
	 */
	public static function render_gate( array $gate, \WP_Post $post ): string {
		if ( 'block' === ( $gate['source'] ?? '' ) ) {
			return self::render_blocks( (array) ( $gate['blocks'] ?? array() ), $post );
		}

		return self::render_secret( (string) ( $gate['content'] ?? '' ), $post );
	}

	/**
	 * Render inner blocks one at a time.
	 *
	 * Deliberately NOT `(new WP_Block($gate_node))->render()`: that would invoke
	 * our own render_callback again and recurse straight back into the lock.
	 * Rendering the children individually gives the same HTML without the loop.
	 */
	public static function render_blocks( array $blocks, \WP_Post $post ): string {
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );

		$out = '';
		foreach ( $blocks as $block ) {
			$out .= render_block( $block );
		}

		wp_reset_postdata();
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		/** This filter documented in this file. */
		return (string) apply_filters( 'puzzle_gate_secret_content', $out, '', $post );
	}

	/**
	 * Can the *current requester* legitimately see this post at all?
	 *
	 * Without this check the REST endpoint would become an oracle for reading
	 * drafts, private posts, trashed posts and password-protected posts — a
	 * classic "insecure direct object reference" (IDOR) bug. The puzzle is the
	 * outer lock; this is the inner one.
	 */
	public static function is_viewable( \WP_Post $post ): bool {
		$status = get_post_status( $post );

		if ( 'publish' === $status ) {
			// Password-protected posts keep their own gate.
			return ! post_password_required( $post );
		}

		// Non-public statuses require an explicit capability on that post.
		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Turn raw shortcode content into display-ready HTML.
	 *
	 * We apply the same filter chain `the_content` normally would, but by hand.
	 * Calling apply_filters('the_content') here would be risky: other plugins
	 * hook it expecting a full page render and some would recurse back into us.
	 *
	 * The content is authored by someone who can already edit posts, so it is
	 * trusted at the same level as the rest of the post — we do not run
	 * wp_kses() on it, exactly as core does not for post content.
	 */
	public static function render_secret( string $raw, \WP_Post $post ): string {
		global $wp_embed;

		// Give shortcodes/blocks inside the secret the right global $post.
		$previous = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );

		if ( $wp_embed instanceof \WP_Embed ) {
			$raw = $wp_embed->autoembed( $wp_embed->run_shortcode( $raw ) );
		}

		$out = wptexturize( $raw );
		$out = convert_smilies( $out );
		$out = wpautop( $out );
		$out = shortcode_unautop( $out );
		$out = do_shortcode( $out );

		wp_reset_postdata();
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		/**
		 * Filter the revealed HTML.
		 *
		 * @param string   $out  Rendered HTML.
		 * @param string   $raw  Original shortcode content.
		 * @param \WP_Post $post Post the gate belongs to.
		 */
		return (string) apply_filters( 'puzzle_gate_secret_content', $out, $raw, $post );
	}
}
