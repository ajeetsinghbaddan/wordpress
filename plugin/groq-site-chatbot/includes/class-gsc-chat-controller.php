<?php
/**
 * The chat endpoint: /wp-json/gsc/v1/chat
 *
 * Pipeline for every message:
 *   nonce check → rate limit → validate input → cache lookup
 *   → site search → Groq (site context) → fallback Groq (web search)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSC_Chat_Controller {

	// Sentinel the model must output when the site context can't answer.
	const NO_ANSWER = 'NO_ANSWER';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'gsc/v1',
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST only
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						// Runs before the callback: strips tags/odd bytes.
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'history' => array(
						'required' => false,
						'type'     => 'array',
					),
				),
			)
		);
	}

	/**
	 * CSRF protection. The frontend sends the standard REST nonce in the
	 * X-WP-Nonce header; a request forged from another site won't have it.
	 * Works for logged-out visitors too (WordPress issues them a nonce
	 * tied to their session token).
	 */
	public function check_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'gsc_forbidden', __( 'Invalid session. Please refresh the page.', 'groq-site-chatbot' ), array( 'status' => 403 ) );
		}
		if ( ! GSC_Settings::get( 'enabled' ) ) {
			return new WP_Error( 'gsc_disabled', __( 'Chatbot is disabled.', 'groq-site-chatbot' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function handle_chat( WP_REST_Request $request ) {
		// ---- 1. Rate limit (per visitor IP, sliding one-minute window) ----
		if ( ! $this->pass_rate_limit() ) {
			return new WP_Error( 'gsc_rate', __( 'Too many messages. Please wait a minute.', 'groq-site-chatbot' ), array( 'status' => 429 ) );
		}

		// ---- 2. Validate the message ----
		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return new WP_Error( 'gsc_empty', __( 'Please type a question.', 'groq-site-chatbot' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $message ) > 1000 : strlen( $message ) > 1000 ) {
			return new WP_Error( 'gsc_long', __( 'Message is too long (1000 characters max).', 'groq-site-chatbot' ), array( 'status' => 400 ) );
		}

		$history = $this->sanitize_history( $request->get_param( 'history' ) );

		// ---- 3. Cache: identical first-turn questions reuse the answer ----
		$cache_key = '';
		if ( empty( $history ) ) {
			$cache_key = 'gsc_ans_' . md5( strtolower( $message ) );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				$cached['cached'] = true;
				return rest_ensure_response( $cached );
			}
		}

		// ---- 4. Step one: try to answer from the site itself ----
		$search  = GSC_Site_Search::search( $message );
		$answer  = null;
		$source  = 'site';
		$sources = array();

		if ( '' !== $search['context'] ) {
			$system = 'You are ' . GSC_Settings::get( 'bot_name' ) . ', the assistant for the website "'
				. get_bloginfo( 'name' ) . '". Answer the visitor using ONLY the website excerpts below. '
				. 'Be concise and helpful. If the excerpts do not contain the information needed to answer, '
				. 'reply with exactly ' . self::NO_ANSWER . ' and nothing else.'
				. "\n\nWEBSITE EXCERPTS:\n" . $search['context'];

			$messages = array_merge(
				array( array( 'role' => 'system', 'content' => $system ) ),
				$history,
				array( array( 'role' => 'user', 'content' => $message ) )
			);

			$result = GSC_Groq_Client::chat( GSC_Settings::get( 'site_model' ), $messages );

			if ( ! is_wp_error( $result ) && false === strpos( $result, self::NO_ANSWER ) ) {
				$answer  = $result;
				$sources = $search['sources'];
			}
		}

		// ---- 5. Step two: fallback to Groq's web-search model ----
		if ( null === $answer ) {
			$source = 'web';
			$system = 'You are ' . GSC_Settings::get( 'bot_name' ) . ', the assistant for the website "'
				. get_bloginfo( 'name' ) . '". The website content did not answer the visitor\'s question, '
				. 'so use web search to find an accurate, up-to-date answer. Be concise. '
				. 'If you genuinely cannot find an answer, say so honestly.';

			$messages = array_merge(
				array( array( 'role' => 'system', 'content' => $system ) ),
				$history,
				array( array( 'role' => 'user', 'content' => $message ) )
			);

			$result = GSC_Groq_Client::chat( GSC_Settings::get( 'web_model' ), $messages );

			if ( is_wp_error( $result ) ) {
				return $result; // safe, generic message from the client class
			}
			$answer = $result;
		}

		$payload = array(
			'answer'  => $answer,
			'source'  => $source,   // lets the UI label "From this site" vs "From the web"
			'sources' => $sources,  // site links the answer was based on
		);

		if ( $cache_key ) {
			set_transient( $cache_key, $payload, HOUR_IN_SECONDS );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Sliding-window rate limiter backed by transients (uses the object
	 * cache when available, otherwise the options table).
	 */
	private function pass_rate_limit() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'gsc_rl_' . md5( $ip . wp_salt() ); // salted so keys aren't guessable
		$count = (int) get_transient( $key );

		if ( $count >= (int) GSC_Settings::get( 'rate_limit' ) ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * The client sends previous turns so the bot has memory, but the
	 * client is untrusted: we re-validate roles, strip anything that is
	 * not plain text, and cap both turn count and length. A malicious
	 * visitor cannot inject a fake "system" role or a 2 MB payload.
	 */
	private function sanitize_history( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( array_slice( $raw, -6 ) as $turn ) { // last 3 exchanges max
			if ( ! is_array( $turn ) || empty( $turn['role'] ) || ! isset( $turn['content'] ) ) {
				continue;
			}
			$role = ( 'assistant' === $turn['role'] ) ? 'assistant' : 'user'; // never 'system'
			$text = sanitize_textarea_field( (string) $turn['content'] );
			if ( function_exists( 'mb_substr' ) ) {
				$text = mb_substr( $text, 0, 1000 );
			} else {
				$text = substr( $text, 0, 1000 );
			}
			if ( '' !== trim( $text ) ) {
				$clean[] = array(
					'role'    => $role,
					'content' => $text,
				);
			}
		}
		return $clean;
	}
}
