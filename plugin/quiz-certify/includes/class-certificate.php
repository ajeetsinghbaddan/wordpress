<?php
/**
 * Serves the printable certificate at a tokenized URL: ?qc_cert=TOKEN.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Certificate {

	/**
	 * Tell WordPress that qc_cert is a query variable we care about.
	 *
	 * @param array $vars Existing public query vars.
	 * @return array
	 */
	public static function register_query_var( $vars ) {
		$vars[] = 'qc_cert';
		return $vars;
	}

	/**
	 * Intercept requests that carry a certificate token and render the certificate.
	 *
	 * Why a server-rendered, token-based page instead of letting JS draw the
	 * certificate: a server page can be re-opened, bookmarked, and verified against the
	 * database. The 32-character random token is effectively unguessable, so only
	 * someone who actually passed (and received the link) can view their certificate.
	 */
	public static function maybe_render() {
		$token = get_query_var( 'qc_cert' );
		if ( empty( $token ) ) {
			return;
		}

		// Tokens are always 32 lowercase alphanumerics; reject anything else early.
		$token = sanitize_text_field( $token );
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			self::not_found();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'quiz_certify_results';

		// $wpdb->prepare binds the token as a parameter, so the value can never break
		// out of the query — this is the core defense against SQL injection.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE cert_token = %s AND passed = 1 LIMIT 1",
				$token
			)
		);

		if ( ! $result ) {
			self::not_found();
		}

		$quiz = get_post( (int) $result->quiz_id );
		if ( ! $quiz ) {
			self::not_found();
		}

		// Confirm certificates are still enabled for this quiz.
		if ( ! (int) get_post_meta( $quiz->ID, '_qc_certificate_enabled', true ) ) {
			self::not_found();
		}

		$subtitle = get_post_meta( $quiz->ID, '_qc_certificate_subtitle', true );

		// Render the certificate template as a full standalone page, then stop WordPress
		// from loading the surrounding theme around it.
		$template = QUIZ_CERTIFY_PATH . 'templates/certificate.php';
		if ( file_exists( $template ) ) {
			// These variables are available inside the template.
			$cert_name  = $result->user_name;
			$cert_quiz  = $quiz->post_title;
			$cert_pct   = $result->percentage;
			$cert_date  = $result->created_at;
			$cert_id    = $result->id;
			$cert_sub   = $subtitle;
			$cert_tok   = $token;
			include $template;
		}
		exit;
	}

	/**
	 * Send a clean 404 for missing or invalid tokens.
	 */
	private static function not_found() {
		status_header( 404 );
		wp_die(
			esc_html__( 'Certificate not found or no longer available.', 'quiz-certify' ),
			esc_html__( 'Certificate not found', 'quiz-certify' ),
			array( 'response' => 404 )
		);
	}
}
