<?php
/**
 * Searches the site's own content and turns the best matches into a
 * compact text "context" the model can answer from (a lightweight RAG step).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSC_Site_Search {

	/**
	 * @param string $query Visitor's question (already sanitized upstream).
	 * @return array{context:string, sources:array} Empty context = nothing relevant found.
	 */
	public static function search( $query ) {
		$limit = (int) GSC_Settings::get( 'max_context_docs' );

		// WP_Query with 's' uses WordPress's built-in relevance-ordered
		// search across titles, content and excerpts. It also automatically
		// respects post_status, so drafts/private posts can never leak
		// to anonymous visitors.
		$q = new WP_Query(
			array(
				's'                      => $query,
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'no_found_rows'          => true,  // skip the COUNT query we don't need
				'update_post_meta_cache' => false, // skip meta cache priming
				'update_post_term_cache' => false, // skip term cache priming
				'ignore_sticky_posts'    => true,
			)
		);

		$context = '';
		$sources = array();

		foreach ( $q->posts as $post ) {
			// Render shortcodes, then strip tags so the model receives
			// clean prose instead of HTML soup (smaller + safer).
			$text = wp_strip_all_tags( do_shortcode( $post->post_content ) );
			$text = preg_replace( '/\s+/', ' ', $text );

			// Cap each document so 4 huge pages can't blow up the prompt
			// (cost + latency). 1500 chars ≈ 350 tokens per doc.
			if ( function_exists( 'mb_substr' ) ) {
				$text = mb_substr( $text, 0, 1500 );
			} else {
				$text = substr( $text, 0, 1500 );
			}

			$context .= "### " . $post->post_title . "\n"
				. "URL: " . get_permalink( $post ) . "\n"
				. $text . "\n\n";

			$sources[] = array(
				'title' => $post->post_title,
				'url'   => get_permalink( $post ),
			);
		}

		return array(
			'context' => trim( $context ),
			'sources' => $sources,
		);
	}
}
