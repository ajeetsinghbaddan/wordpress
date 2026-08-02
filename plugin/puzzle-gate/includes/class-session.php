<?php
/**
 * Short-lived challenge tokens, unlock passes and abuse throttling.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

class Session {

	const CHALLENGE_PREFIX = 'pgz_ch_';
	const PASS_PREFIX      = 'pgz_pass_';
	const RATE_PREFIX      = 'pgz_rl_';

	/** HttpOnly cookie holding the pass id. JavaScript can never read it. */
	const COOKIE = 'pgz_pass';

	/** Readable "you have a pass" flag so JS knows whether to ask the server. */
	const HINT_COOKIE = 'pgz_has_pass';

	/* ---------------------------------------------------------------------
	 * Challenge tokens
	 * ------------------------------------------------------------------- */

	/**
	 * Create a challenge and hand back its opaque token.
	 *
	 * The solution is stored **server-side only**, in a transient. The browser
	 * receives the token and the puzzle's visible half; it never receives the
	 * answer. That is what makes cheating by reading the network response
	 * impossible for answer-based puzzles.
	 *
	 * Transients are used rather than a custom table because they transparently
	 * use Redis/Memcached when the site has a persistent object cache, and fall
	 * back to the options table otherwise. They also expire themselves.
	 *
	 * @param array $data Everything needed to verify later.
	 * @return string Token (32 hex chars).
	 */
	public static function create_challenge( array $data ): string {
		// random_bytes() is a CSPRNG. mt_rand()/uniqid() are predictable and
		// must never be used for anything a user could try to guess.
		$token = bin2hex( random_bytes( 16 ) );

		$data['issued_at'] = time();
		$data['attempts']  = 0;

		set_transient( self::CHALLENGE_PREFIX . $token, $data, self::challenge_ttl() );

		return $token;
	}

	public static function get_challenge( string $token ): ?array {
		if ( ! self::is_token_shaped( $token ) ) {
			return null;
		}
		$data = get_transient( self::CHALLENGE_PREFIX . $token );
		return is_array( $data ) ? $data : null;
	}

	public static function save_challenge( string $token, array $data ): void {
		if ( self::is_token_shaped( $token ) ) {
			// Keep the original expiry window rather than extending it, so an
			// attacker cannot keep a session alive forever by guessing slowly.
			$left = max( 60, self::challenge_ttl() - ( time() - (int) ( $data['issued_at'] ?? time() ) ) );
			set_transient( self::CHALLENGE_PREFIX . $token, $data, $left );
		}
	}

	/** One-time use: burn the token the moment it succeeds (blocks replay). */
	public static function destroy_challenge( string $token ): void {
		if ( self::is_token_shaped( $token ) ) {
			delete_transient( self::CHALLENGE_PREFIX . $token );
		}
	}

	private static function is_token_shaped( string $token ): bool {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	public static function challenge_ttl(): int {
		return max( 60, (int) Plugin::option( 'session_minutes' ) * MINUTE_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------
	 * Unlock passes ("remember that I solved this")
	 * ------------------------------------------------------------------- */

	/**
	 * Record that this visitor has opened a gate, and set the cookie.
	 *
	 * The cookie carries only a random id. The list of unlocked gates lives
	 * server-side, so a visitor cannot edit their cookie to claim they solved
	 * something. This is the difference between a *bearer token* and a
	 * *client-side claim*: only the first is safe.
	 */
	public static function grant_pass( string $gate_key ): void {
		$pass_id = self::read_pass_id();
		$gates   = array();

		if ( $pass_id ) {
			$stored = get_transient( self::PASS_PREFIX . $pass_id );
			if ( is_array( $stored ) ) {
				$gates = $stored;
			}
		}

		if ( ! $pass_id ) {
			$pass_id = bin2hex( random_bytes( 16 ) );
		}

		$gates[ $gate_key ] = time();
		// Guard against a bloated transient if someone farms hundreds of gates.
		if ( count( $gates ) > 200 ) {
			$gates = array_slice( $gates, -200, null, true );
		}

		$ttl = max( HOUR_IN_SECONDS, (int) Plugin::option( 'remember_hours' ) * HOUR_IN_SECONDS );
		set_transient( self::PASS_PREFIX . $pass_id, $gates, $ttl );

		self::set_cookies( $pass_id, $ttl );
	}

	public static function has_pass( string $gate_key ): bool {
		$pass_id = self::read_pass_id();
		if ( ! $pass_id ) {
			return false;
		}
		$gates = get_transient( self::PASS_PREFIX . $pass_id );
		return is_array( $gates ) && isset( $gates[ $gate_key ] );
	}

	private static function read_pass_id(): string {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return self::is_token_shaped( $raw ) ? $raw : '';
	}

	/**
	 * Cookie flags, and why each one matters:
	 *  - httponly : JavaScript (including injected XSS payloads) cannot read it.
	 *  - secure   : only sent over HTTPS, so it cannot be sniffed in transit.
	 *  - samesite : 'Lax' stops another site from silently using the visitor's
	 *               pass through a cross-site request.
	 */
	private static function set_cookies( string $pass_id, int $ttl ): void {
		if ( headers_sent() ) {
			return;
		}

		$common = array(
			'expires'  => time() + $ttl,
			'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'samesite' => 'Lax',
		);

		setcookie( self::COOKIE, $pass_id, $common + array( 'httponly' => true ) );

		// A separate, deliberately non-secret flag. The front-end reads it to
		// decide whether it is worth asking the server "am I already unlocked?",
		// which saves a request on the vast majority of page views.
		setcookie( self::HINT_COOKIE, '1', $common + array( 'httponly' => false ) );
	}

	/* ---------------------------------------------------------------------
	 * Rate limiting
	 * ------------------------------------------------------------------- */

	/**
	 * Fixed-window counter per IP + bucket.
	 *
	 * Without this, an answer-based puzzle can be brute-forced with a loop, and
	 * the challenge endpoint can be used to hammer the database. With it, an
	 * attacker gets a handful of tries a minute, which makes guessing pointless.
	 *
	 * @return bool True when the request is allowed.
	 */
	public static function allow( string $bucket, ?int $max = null ): bool {
		$max = $max ?? max( 1, (int) Plugin::option( 'rate_limit' ) );
		$key = self::RATE_PREFIX . md5( $bucket . '|' . self::client_fingerprint() );

		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * A hashed, non-reversible identifier for the requester.
	 *
	 * PRIVACY: we hash the IP with the site's own salt before storing it, so the
	 * database never holds raw IP addresses — that keeps the plugin comfortably
	 * inside GDPR-style expectations while still working as a throttle.
	 *
	 * NOTE: REMOTE_ADDR is the only value a client cannot forge. Headers such as
	 * X-Forwarded-For are trivially spoofed unless your site sits behind a proxy
	 * that overwrites them, which is why they are opt-in through a filter.
	 */
	public static function client_fingerprint(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		/**
		 * Filter the raw client IP (e.g. return HTTP_CF_CONNECTING_IP behind Cloudflare).
		 *
		 * @param string $ip Remote address.
		 */
		$ip = (string) apply_filters( 'puzzle_gate_client_ip', $ip );

		$user = get_current_user_id();

		return hash_hmac( 'sha256', $ip . '|' . $user, wp_salt( 'auth' ) );
	}

	/** Stable key identifying one gate on one post. */
	public static function gate_key( int $post_id, string $gate_id ): string {
		return $post_id . ':' . $gate_id;
	}
}
