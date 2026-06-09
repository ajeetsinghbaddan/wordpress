<?php
/**
 * Quiz listing: [quiz_certify_list] plus the AJAX loader that serves one quiz on demand.
 *
 * Efficiency notes:
 *  - The grid is one indexed query (no_found_rows skips the pagination COUNT, and term
 *    caches are skipped since quizzes use no taxonomies). WordPress primes all the
 *    quizzes' post meta in a single batched query, so reading each quiz's question count
 *    and settings in the template hits the cache rather than the database.
 *  - Quizzes are NOT all rendered up front. Picking a card lazily fetches just that one
 *    quiz's markup over AJAX, so a page with 50 quizzes still ships a light initial page.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_List {

	public static function register() {
		add_shortcode( 'quiz_certify_list', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_qc_load_quiz', array( __CLASS__, 'ajax_load_quiz' ) );
		add_action( 'wp_ajax_nopriv_qc_load_quiz', array( __CLASS__, 'ajax_load_quiz' ) );
	}

	/**
	 * Render the listing, or a single quiz when ?quiz=ID is in the URL.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'   => 50,
				'orderby' => 'title',
				'order'   => 'ASC',
			),
			$atts,
			'quiz_certify_list'
		);

		Quiz_Certify_Shortcode::enqueue();
		if ( wp_script_is( 'quiz-certify-list', 'registered' ) ) {
			wp_enqueue_script( 'quiz-certify-list' );
		}

		// Shareable / no-JS path: ?quiz=ID renders that one quiz with a back link.
		// This is a plain GET navigation to public content, so no nonce is needed; the
		// value is cast with absint and the quiz must be published.
		$selected = isset( $_GET['quiz'] ) ? absint( $_GET['quiz'] ) : 0;
		if ( $selected ) {
			$quiz = get_post( $selected );
			if ( $quiz && 'qc_quiz' === $quiz->post_type && 'publish' === $quiz->post_status ) {
				$back  = esc_url( remove_query_arg( 'quiz' ) );
				$html  = '<div class="qc-quiz-list qc-quiz-list--single">';
				$html .= '<a class="qc-list-back" href="' . $back . '">' . esc_html__( '← All quizzes', 'quiz-certify' ) . '</a>';
				$html .= Quiz_Certify_Shortcode::get_quiz_html( $selected );
				$html .= '</div>';
				return $html;
			}
		}

		$quizzes = self::get_quizzes( $atts );

		ob_start();
		$template = QUIZ_CERTIFY_PATH . 'templates/quiz-list.php';
		if ( file_exists( $template ) ) {
			include $template; // $quizzes in scope.
		}
		return ob_get_clean();
	}

	/**
	 * One efficient query for the published quizzes to list.
	 *
	 * @param array $atts Sanitized shortcode attributes.
	 * @return WP_Post[]
	 */
	private static function get_quizzes( $atts ) {
		$limit = (int) $atts['limit'];
		if ( $limit <= 0 ) {
			$limit = 50;
		}

		// Whitelist orderby/order so a shortcode attribute can never inject into the query.
		$orderby = in_array( $atts['orderby'], array( 'title', 'date', 'menu_order', 'modified' ), true )
			? $atts['orderby']
			: 'title';
		$order = ( 'DESC' === strtoupper( (string) $atts['order'] ) ) ? 'DESC' : 'ASC';

		return get_posts(
			array(
				'post_type'              => 'qc_quiz',
				'post_status'            => 'publish',
				'numberposts'            => $limit,
				'orderby'                => $orderby,
				'order'                  => $order,
				'no_found_rows'          => true,  // Skip the COUNT query — we don't paginate.
				'update_post_term_cache' => false, // Quizzes have no taxonomies.
			)
		);
	}

	/**
	 * AJAX: return the markup for one quiz so the listing can swap it in without a reload.
	 */
	public static function ajax_load_quiz() {
		check_ajax_referer( 'qc_load_quiz', 'nonce' );

		$quiz_id = isset( $_POST['quiz_id'] ) ? absint( $_POST['quiz_id'] ) : 0;
		$quiz    = get_post( $quiz_id );

		if ( ! $quiz || 'qc_quiz' !== $quiz->post_type || 'publish' !== $quiz->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Quiz not found.', 'quiz-certify' ) ) );
		}

		wp_send_json_success(
			array(
				'html'  => Quiz_Certify_Shortcode::get_quiz_html( $quiz_id ),
				'title' => get_the_title( $quiz_id ),
				'id'    => $quiz_id,
			)
		);
	}
}
