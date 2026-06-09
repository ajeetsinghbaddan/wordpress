<?php
/**
 * Handles quiz submission over AJAX, grading, and result storage.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Ajax {

	public static function register() {
		add_action( 'wp_ajax_qc_submit_quiz', array( __CLASS__, 'submit' ) );
		add_action( 'wp_ajax_nopriv_qc_submit_quiz', array( __CLASS__, 'submit' ) );
	}

	/**
	 * Receive answers, grade them server-side, store the record, and return the result.
	 *
	 * The browser only sends which option indexes were picked; the answer key never
	 * leaves the server, so a score cannot be faked from the page.
	 */
	public static function submit() {
		// 1. Confirm the request came from our form (CSRF protection).
		check_ajax_referer( 'qc_submit_quiz', 'nonce' );

		// 2. Sanitize input.
		$quiz_id = isset( $_POST['quiz_id'] ) ? absint( $_POST['quiz_id'] ) : 0;
		$name    = isset( $_POST['user_name'] ) ? sanitize_text_field( wp_unslash( $_POST['user_name'] ) ) : '';
		$email   = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';

		$raw_answers = isset( $_POST['answers'] ) && is_array( $_POST['answers'] )
			? wp_unslash( $_POST['answers'] )
			: array();

		// 3. Validate the quiz.
		$quiz = get_post( $quiz_id );
		if ( ! $quiz || 'qc_quiz' !== $quiz->post_type || 'publish' !== $quiz->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Invalid quiz.', 'quiz-certify' ) ) );
		}

		if ( '' === trim( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your name.', 'quiz-certify' ) ) );
		}

		// Email is always required. is_email() returns false for empty or malformed
		// values, so this single check enforces both presence and format.
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'quiz-certify' ) ) );
		}

		$questions = get_post_meta( $quiz_id, '_qc_questions', true );
		if ( ! is_array( $questions ) || empty( $questions ) ) {
			wp_send_json_error( array( 'message' => __( 'This quiz has no questions.', 'quiz-certify' ) ) );
		}

		// 4. Grade.
		$total = count( $questions );
		$score = 0;

		foreach ( $questions as $q_index => $question ) {
			$correct = isset( $question['correct'] ) && is_array( $question['correct'] )
				? array_map( 'intval', $question['correct'] )
				: array();

			$picked = array();
			if ( isset( $raw_answers[ $q_index ] ) ) {
				foreach ( (array) $raw_answers[ $q_index ] as $p ) {
					$picked[] = (int) $p;
				}
			}

			sort( $correct );
			$picked = array_values( array_unique( $picked ) );
			sort( $picked );

			// Right only when the chosen set exactly matches the correct set.
			if ( ! empty( $correct ) && $correct === $picked ) {
				$score++;
			}
		}

		$percentage = $total > 0 ? round( ( $score / $total ) * 100, 2 ) : 0;
		$pass_mark  = (int) get_post_meta( $quiz_id, '_qc_pass_percentage', true );
		$pass_mark  = '' === (string) $pass_mark ? 70 : $pass_mark;
		$passed     = $percentage >= $pass_mark;

		// 5. Store the attempt with a certificate token (only meaningful on a pass).
		$cert_token = $passed ? wp_generate_password( 32, false, false ) : '';

		global $wpdb;
		$table = $wpdb->prefix . 'quiz_certify_results';

		// $wpdb->insert binds each value by the format array, blocking SQL injection.
		$wpdb->insert(
			$table,
			array(
				'quiz_id'    => $quiz_id,
				'user_id'    => get_current_user_id(),
				'user_name'  => $name,
				'user_email' => $email,
				'score'      => $score,
				'total'      => $total,
				'percentage' => $percentage,
				'passed'     => $passed ? 1 : 0,
				'cert_token' => $cert_token,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%f', '%d', '%s', '%s' )
		);

		// 6. Respond. Correct answers are never returned.
		$cert_url = '';
		$cert_on  = (int) get_post_meta( $quiz_id, '_qc_certificate_enabled', true );
		if ( $passed && $cert_on && $cert_token ) {
			$cert_url = add_query_arg( 'qc_cert', $cert_token, home_url( '/' ) );
		}

		wp_send_json_success(
			array(
				'score'       => $score,
				'total'       => $total,
				'percentage'  => $percentage,
				'passed'      => $passed,
				'passMark'    => $pass_mark,
				'name'        => $name,
				'certificate' => $cert_url,
			)
		);
	}
}
