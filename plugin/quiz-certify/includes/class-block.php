<?php
/**
 * Gutenberg block: a first-class "Quiz" block for the block editor.
 *
 * The block is a thin wrapper around the shortcode. Its render_callback simply runs the
 * shortcode on the server, so there is one source of truth for the quiz markup and the
 * block stays compatible with block themes, full-site editing, and the post editor.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Block {

	/**
	 * Register the editor script and the dynamic block.
	 */
	public static function register() {
		// register_block_type with a render_callback makes this a "dynamic" block: the
		// editor stores only the chosen quiz id, and the HTML is generated on the server
		// at view time. That keeps saved content tiny and always up to date.
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // Block editor not available (very old WP) — shortcode still works.
		}

		// The editor script and its quiz list are only needed inside wp-admin (the
		// editor). Building them on every front-end request would be a wasted query.
		if ( is_admin() ) {
			wp_register_script(
				'quiz-certify-block',
				QUIZ_CERTIFY_URL . 'assets/js/block.js',
				array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
				QUIZ_CERTIFY_VERSION,
				true
			);

			wp_localize_script(
				'quiz-certify-block',
				'QuizCertifyBlock',
				array(
					'quizzes' => Quiz_Certify_Shortcode::get_quiz_options(),
				)
			);
		}

		register_block_type(
			'quiz-certify/quiz',
			array(
				'api_version'     => 2,
				'editor_script'   => 'quiz-certify-block',
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => array(
					'quizId' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		// A second block for the listing grid. It takes no settings — it always shows all
		// published quizzes — so it just renders the listing shortcode.
		register_block_type(
			'quiz-certify/list',
			array(
				'api_version'     => 2,
				'editor_script'   => 'quiz-certify-block',
				'render_callback' => array( __CLASS__, 'render_list' ),
			)
		);
	}

	/**
	 * Server-side render for the listing block.
	 *
	 * @return string
	 */
	public static function render_list() {
		return do_shortcode( '[quiz_certify_list]' );
	}

	/**
	 * Server-side render: defer entirely to the shortcode.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$quiz_id = isset( $attributes['quizId'] ) ? absint( $attributes['quizId'] ) : 0;
		if ( ! $quiz_id ) {
			return '<p>' . esc_html__( 'Select a quiz in the block settings.', 'quiz-certify' ) . '</p>';
		}
		// do_shortcode reuses the exact same validation, asset loading, and template.
		return do_shortcode( sprintf( '[quiz_certify id="%d"]', $quiz_id ) );
	}
}
