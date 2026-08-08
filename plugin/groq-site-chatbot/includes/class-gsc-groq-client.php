<?php
/**
 * Thin wrapper around Groq's OpenAI-compatible chat completions endpoint.
 * All HTTP goes through wp_remote_post so WordPress handles SSL
 * verification, proxies and timeouts consistently.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSC_Groq_Client {

	const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

	/**
	 * @param string $model    Groq model ID.
	 * @param array  $messages OpenAI-style [role, content] messages.
	 * @return string|WP_Error Assistant text on success.
	 */
	public static function chat( $model, array $messages ) {
		$api_key = GSC_Settings::get( 'api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'gsc_no_key', __( 'Chatbot is not configured yet.', 'groq-site-chatbot' ) );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 30, // LLMs are slow; default 5s would fail constantly.
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => $messages,
						'temperature' => 0.3,  // low = factual, less creative drift
						'max_tokens'  => 700,  // hard cap on response cost/size
					)
				),
			)
		);

		// Network-level failure (DNS, timeout, SSL...).
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gsc_http', __( 'Could not reach the AI service. Please try again.', 'groq-site-chatbot' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// API-level failure (bad key, rate limit, bad model name...).
		// We log the detail for the admin but return a generic message so
		// internal errors are never exposed to visitors.
		if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'GSC Groq API error (' . $code . '): ' . wp_remote_retrieve_body( $response ) );
			}
			return new WP_Error( 'gsc_api', __( 'The assistant is unavailable right now. Please try again shortly.', 'groq-site-chatbot' ) );
		}

		return trim( $body['choices'][0]['message']['content'] );
	}
}
