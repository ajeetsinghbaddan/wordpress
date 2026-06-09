<?php
/**
 * Front-end shortcode: [quiz_certify id="123"].
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Shortcode {

	public static function register() {
		add_shortcode( 'quiz_certify', array( __CLASS__, 'render' ) );
	}

	/**
	 * Register (not yet enqueue) front-end assets on wp_enqueue_scripts.
	 *
	 * Registering up front lets the shortcode, the blocks, the listing, and Elementor's
	 * Shortcode widget all enqueue the same handles later without notices, and keeps the
	 * styles loading reliably whichever builder rendered the page.
	 */
	public static function register_assets() {
		if ( apply_filters( 'quiz_certify_load_styles', true ) ) {
			wp_register_style(
				'quiz-certify-frontend',
				QUIZ_CERTIFY_URL . 'assets/css/quiz-frontend.css',
				array(),
				QUIZ_CERTIFY_VERSION
			);
		}

		wp_register_script(
			'quiz-certify-frontend',
			QUIZ_CERTIFY_URL . 'assets/js/quiz-frontend.js',
			array(),
			QUIZ_CERTIFY_VERSION,
			true
		);

		// The listing script depends on the frontend script so the shared QuizCertify
		// object (and the delegated submit handler) are available when a quiz is injected.
		wp_register_script(
			'quiz-certify-list',
			QUIZ_CERTIFY_URL . 'assets/js/quiz-list.js',
			array( 'quiz-certify-frontend' ),
			QUIZ_CERTIFY_VERSION,
			true
		);

		wp_localize_script(
			'quiz-certify-frontend',
			'QuizCertify',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'qc_submit_quiz' ),
				'listNonce' => wp_create_nonce( 'qc_load_quiz' ),
				'i18n'      => array(
					'submitting'  => __( 'Scoring…', 'quiz-certify' ),
					'error'       => __( 'Something went wrong. Please try again.', 'quiz-certify' ),
					'nameReq'     => __( 'Please enter your name first.', 'quiz-certify' ),
					'emailReq'    => __( 'Please enter a valid email address.', 'quiz-certify' ),
					'submitLabel' => __( 'Submit answers', 'quiz-certify' ),
					'congrats'    => __( 'Congratulations, %s!', 'quiz-certify' ),
					'notQuite'    => __( 'Not quite this time', 'quiz-certify' ),
					'scored'      => __( 'You scored %1$d / %2$d (%3$s%%)', 'quiz-certify' ),
					'metPass'     => __( 'You met the passing score of %d%%.', 'quiz-certify' ),
					'missedPass'  => __( 'The passing score is %d%%. You can try again.', 'quiz-certify' ),
					'viewCert'    => __( 'View & print your certificate', 'quiz-certify' ),
					'loading'     => __( 'Loading quiz…', 'quiz-certify' ),
					'loadError'   => __( 'Could not load that quiz. Please try again.', 'quiz-certify' ),
					'backToList'  => __( '← All quizzes', 'quiz-certify' ),
				),
			)
		);
	}

	/**
	 * Enqueue the registered front-end assets (called by shortcode, block, and listing).
	 */
	public static function enqueue() {
		if ( wp_style_is( 'quiz-certify-frontend', 'registered' ) ) {
			wp_enqueue_style( 'quiz-certify-frontend' );
		}
		wp_enqueue_script( 'quiz-certify-frontend' );
	}

	/**
	 * Shortcode renderer for a single quiz.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'quiz_certify' );
		$quiz_id = absint( $atts['id'] );

		self::enqueue();
		return self::get_quiz_html( $quiz_id );
	}

	/**
	 * Build the quiz markup for a given quiz id.
	 *
	 * Shared by the shortcode and the listing's AJAX loader, so there is one source of
	 * truth for the quiz HTML. Does NOT enqueue assets (callers decide that), which keeps
	 * it safe to call during an AJAX request.
	 *
	 * @param int $quiz_id Quiz post id.
	 * @return string
	 */
	public static function get_quiz_html( $quiz_id ) {
		$quiz_id = absint( $quiz_id );

		$quiz = get_post( $quiz_id );
		if ( ! $quiz || 'qc_quiz' !== $quiz->post_type || 'publish' !== $quiz->post_status ) {
			return '<p>' . esc_html__( 'Quiz not found.', 'quiz-certify' ) . '</p>';
		}

		$questions = get_post_meta( $quiz_id, '_qc_questions', true );
		if ( ! is_array( $questions ) || empty( $questions ) ) {
			return '<p>' . esc_html__( 'This quiz has no questions yet.', 'quiz-certify' ) . '</p>';
		}

		ob_start();
		$template = QUIZ_CERTIFY_PATH . 'templates/quiz-display.php';
		if ( file_exists( $template ) ) {
			include $template; // $quiz_id, $questions in scope.
		}
		return ob_get_clean();
	}

	/**
	 * A lightweight list of published quizzes for the block editor dropdowns.
	 *
	 * @return array
	 */
	public static function get_quiz_options() {
		$quizzes = get_posts(
			array(
				'post_type'   => 'qc_quiz',
				'post_status' => 'publish',
				'numberposts' => 200,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		$options = array();
		foreach ( $quizzes as $quiz ) {
			$options[] = array(
				'value' => (string) $quiz->ID,
				'label' => $quiz->post_title,
			);
		}
		return $options;
	}
}
