<?php
/**
 * REST endpoints. This is the only door between the browser and the secret.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

class REST_Controller {

	const NAMESPACE_ = 'puzzle-gate/v1';

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$gate_args = array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				// `sanitize_callback` runs before your handler, so by the time
				// the callback executes the value is already an int. This is the
				// REST API's built-in input validation layer and it is the reason
				// you rarely need manual checks in the handler itself.
				'sanitize_callback' => 'absint',
			),
			'gate_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route(
			self::NAMESPACE_,
			'/challenge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE, // POST
				'callback'            => array( $this, 'challenge' ),
				// Public endpoint. WordPress *requires* an explicit
				// permission_callback — omitting it triggers a _doing_it_wrong
				// notice, because silently-public endpoints have caused real
				// vulnerabilities in the wild. Access control here is: the post
				// must be viewable, plus rate limiting.
				'permission_callback' => '__return_true',
				'args'                => $gate_args,
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/solve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'solve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'payload' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/reveal',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reveal' ),
				'permission_callback' => '__return_true',
				'args'                => $gate_args,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * POST /challenge  — hand out a puzzle
	 * ------------------------------------------------------------------- */

	public function challenge( \WP_REST_Request $request ) {
		nocache_headers(); // never let a proxy or page cache store a challenge

		if ( ! Session::allow( 'challenge' ) ) {
			return $this->error( 'pgz_rate_limited', __( 'Too many requests. Wait a moment.', 'puzzle-gate' ), 429 );
		}

		$post_id = (int) $request['post_id'];
		$gate_id = (string) $request['gate_id'];

		$gate = Gate_Locator::find( $post_id, $gate_id );
		if ( ! $gate ) {
			// Same generic message whether the post is missing, private or the
			// gate id is wrong. Distinct errors would let someone enumerate
			// which private posts exist.
			return $this->error( 'pgz_not_found', __( 'That lock does not exist.', 'puzzle-gate' ), 404 );
		}

		$type   = isset( $gate['atts']['type'] ) ? sanitize_key( $gate['atts']['type'] ) : (string) Plugin::option( 'default_type' );
		$puzzle = Puzzle_Registry::get( $type ) ?? Puzzle_Registry::get( 'slide' );

		$built = $puzzle->generate( $gate['atts'] );

		$token = Session::create_challenge(
			array(
				'post_id'  => $post_id,
				'gate_id'  => $gate_id,
				'type'     => $puzzle->slug(),
				'solution' => $built['solution'], // stays here, never serialised out
			)
		);

		Stats::record( $post_id, $gate_id, 'starts' );

		return rest_ensure_response(
			array(
				'token'   => $token,
				'type'    => $puzzle->slug(),
				'puzzle'  => $built['public'],
				'expires' => Session::challenge_ttl(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * POST /solve  — check an attempt, and reveal on success
	 * ------------------------------------------------------------------- */

	public function solve( \WP_REST_Request $request ) {
		nocache_headers();

		if ( ! Session::allow( 'solve' ) ) {
			return $this->error( 'pgz_rate_limited', __( 'Too many attempts. Wait a minute.', 'puzzle-gate' ), 429 );
		}

		$token   = (string) $request['token'];
		$session = Session::get_challenge( $token );

		if ( ! $session ) {
			return $this->error( 'pgz_expired', __( 'This puzzle expired. Start a new one.', 'puzzle-gate' ), 410 );
		}

		$puzzle = Puzzle_Registry::get( (string) $session['type'] );
		if ( ! $puzzle ) {
			return $this->error( 'pgz_bad_type', __( 'Unknown puzzle type.', 'puzzle-gate' ), 400 );
		}

		$max = max( 1, (int) Plugin::option( 'max_attempts' ) );
		if ( (int) $session['attempts'] >= $max ) {
			Session::destroy_challenge( $token );
			return $this->error( 'pgz_burned', __( 'Out of attempts on this puzzle.', 'puzzle-gate' ), 429 );
		}

		$elapsed = time() - (int) $session['issued_at'];
		$payload = $request->get_param( 'payload' );
		$payload = is_array( $payload ) ? $payload : array();

		$correct = $elapsed >= $puzzle->min_seconds() && $puzzle->verify( (array) $session['solution'], $payload );

		if ( ! $correct ) {
			$session['attempts']++;
			Session::save_challenge( $token, $session );
			Stats::record( (int) $session['post_id'], (string) $session['gate_id'], 'fails' );

			$left = $max - (int) $session['attempts'];

			return rest_ensure_response(
				array(
					'solved'   => false,
					'attempts' => max( 0, $left ),
					// Hint only after a real struggle — handing it over on the
					// first miss removes the reason to think.
					'hint'     => $session['attempts'] >= 3 ? $puzzle->hint( (array) $session['solution'] ) : '',
				)
			);
		}

		// Success. Burn the token so the same payload cannot be replayed.
		Session::destroy_challenge( $token );

		$post_id = (int) $session['post_id'];
		$gate_id = (string) $session['gate_id'];

		$html = $this->fetch_secret( $post_id, $gate_id );
		if ( null === $html ) {
			return $this->error( 'pgz_not_found', __( 'That lock does not exist.', 'puzzle-gate' ), 404 );
		}

		Session::grant_pass( Session::gate_key( $post_id, $gate_id ) );
		Stats::record( $post_id, $gate_id, 'solves', $elapsed );

		/**
		 * Fires after a visitor opens a gate. Useful for analytics, awarding
		 * points, sending a notification, etc.
		 *
		 * @param int    $post_id
		 * @param string $gate_id
		 * @param int    $elapsed Seconds taken.
		 */
		do_action( 'puzzle_gate_solved', $post_id, $gate_id, $elapsed );

		return rest_ensure_response(
			array(
				'solved'  => true,
				'html'    => $html,
				'seconds' => $elapsed,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * POST /reveal  — "I solved this before, let me back in"
	 * ------------------------------------------------------------------- */

	public function reveal( \WP_REST_Request $request ) {
		nocache_headers();

		if ( ! Session::allow( 'reveal', 60 ) ) {
			return $this->error( 'pgz_rate_limited', __( 'Too many requests.', 'puzzle-gate' ), 429 );
		}

		$post_id = (int) $request['post_id'];
		$gate_id = (string) $request['gate_id'];

		// The pass lives server-side; the cookie only points at it.
		if ( ! Session::has_pass( Session::gate_key( $post_id, $gate_id ) ) ) {
			return $this->error( 'pgz_locked', __( 'Still locked.', 'puzzle-gate' ), 403 );
		}

		$html = $this->fetch_secret( $post_id, $gate_id );
		if ( null === $html ) {
			return $this->error( 'pgz_not_found', __( 'That lock does not exist.', 'puzzle-gate' ), 404 );
		}

		return rest_ensure_response(
			array(
				'solved' => true,
				'html'   => $html,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private function fetch_secret( int $post_id, string $gate_id ): ?string {
		$gate = Gate_Locator::find( $post_id, $gate_id );
		if ( ! $gate ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return Gate_Locator::render_gate( $gate, $post );
	}

	/**
	 * WP_Error converted to a proper HTTP status by the REST server.
	 * Returning WP_Error rather than a 200 with an "error" key keeps the API
	 * honest and lets fetch() branch on response.ok.
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
