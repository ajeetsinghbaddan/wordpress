<?php
/**
 * Front-end form: shortcode, assets, and post-redirect state.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the guest submission form.
 */
class GPS_Form {

	const SHORTCODE  = 'guest_post_form';
	const NONCE_NAME = 'gps_nonce';
	const NONCE_ACT  = 'gps_submit_post';

	/**
	 * Hook the shortcode and register (but do not enqueue) assets.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register assets without loading them.
	 *
	 * THIS IS THE KEY PERFORMANCE PATTERN IN THE PLUGIN.
	 *
	 * wp_register_style() only tells WordPress "this file exists and here is
	 * its handle". Nothing is added to the page. We then call
	 * wp_enqueue_style() from inside the shortcode callback -- which only runs
	 * on pages that actually contain the shortcode.
	 *
	 * Result: a site with the form on one page ships zero extra CSS/JS on the
	 * other 200 pages. Plugins that enqueue unconditionally in
	 * wp_enqueue_scripts are the single most common cause of bloated
	 * WordPress front ends.
	 *
	 * This works even though shortcodes run during 'the_content' (after
	 * wp_head) because WordPress prints any still-queued assets in wp_footer.
	 */
	public static function register_assets() {
		wp_register_style(
			'gps-form',
			GPS_PLUGIN_URL . 'assets/css/gps-form.css',
			array(),
			GPS_VERSION // Version string busts the browser cache on updates.
		);

		wp_register_script(
			'gps-form',
			GPS_PLUGIN_URL . 'assets/js/gps-form.js',
			array(),
			GPS_VERSION,
			true // Load in the footer so it never blocks rendering.
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Escaped HTML.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'  => __( 'Submit a guest post', 'guest-post-submissions' ),
				'intro'  => '',
			),
			$atts,
			self::SHORTCODE
		);

		wp_enqueue_style( 'gps-form' );
		wp_enqueue_script( 'gps-form' );

		wp_localize_script(
			'gps-form',
			'gpsFormConfig',
			array(
				'minWords'   => (int) GPS_Settings::get( 'min_words' ),
				'maxWords'   => (int) GPS_Settings::get( 'max_words' ),
				'maxImageKb' => (int) GPS_Settings::get( 'max_image_kb' ),
				'i18n'       => array(
					'words'        => __( 'words', 'guest-post-submissions' ),
					'imageTooBig'  => __( 'That image is larger than the allowed size.', 'guest-post-submissions' ),
					'sending'      => __( 'Sending…', 'guest-post-submissions' ),
				),
			)
		);

		$state = self::consume_state();

		/*
		 * Output buffering lets the template use normal HTML instead of string
		 * concatenation. A shortcode MUST return its output, never echo it --
		 * echoing prints the form at the top of the page instead of in place,
		 * because the shortcode runs while the content is still being built.
		 */
		ob_start();

		$template = locate_template( 'guest-post-submissions/form.php' );

		// locate_template lets a theme override the markup by dropping a file
		// at wp-content/themes/your-theme/guest-post-submissions/form.php --
		// so site owners can restyle without editing the plugin.
		if ( ! $template ) {
			$template = GPS_PLUGIN_DIR . 'templates/form.php';
		}

		// Variables used inside the template.
		$errors    = $state['errors'];
		$old       = $state['old'];
		$submitted = $state['submitted'];

		include $template;

		return ob_get_clean();
	}

	/**
	 * Store validation errors and previous input, then hand back a token.
	 *
	 * WHY NOT JUST RENDER THE ERRORS DIRECTLY FROM THE POST HANDLER?
	 *
	 * Because of the Post/Redirect/Get pattern. If we rendered the response to
	 * the POST request itself, a refresh would re-submit the form and create a
	 * duplicate post, and the back button would show a browser warning.
	 *
	 * So the handler always redirects. But a redirect loses $_POST -- and we
	 * want to show the visitor their 1,200-word draft again rather than an
	 * empty form. A short-lived transient carries that state across the
	 * redirect. The token in the URL is random, so one visitor cannot read
	 * another's draft by guessing.
	 *
	 * @param WP_Error $errors Validation errors.
	 * @param array    $old    Previously submitted values.
	 * @return string Token to append to the redirect URL.
	 */
	public static function store_state( $errors, $old ) {
		$token = wp_generate_password( 20, false, false );

		set_transient(
			'gps_state_' . $token,
			array(
				'errors' => $errors instanceof WP_Error ? $errors->get_error_messages() : array(),
				'old'    => $old,
			),
			10 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	/**
	 * Read and immediately delete the stored state.
	 *
	 * Deleting on read ("consume") means a refresh shows a clean form rather
	 * than repeating a stale error.
	 *
	 * @return array
	 */
	private static function consume_state() {
		$state = array(
			'errors'    => array(),
			'old'       => array(),
			'submitted' => false,
		);

		// Reading $_GET here is safe: we sanitize, and nothing is acted upon
		// beyond looking up our own transient.
		$status = isset( $_GET['gps_status'] ) ? sanitize_key( wp_unslash( $_GET['gps_status'] ) ) : '';

		if ( 'success' === $status ) {
			$state['submitted'] = true;
			return $state;
		}

		if ( 'error' !== $status || empty( $_GET['gps_token'] ) ) {
			return $state;
		}

		// sanitize_key strips everything that is not a-z0-9_- so the value
		// cannot break out of the transient key.
		$token  = sanitize_key( wp_unslash( $_GET['gps_token'] ) );
		$stored = get_transient( 'gps_state_' . $token );

		if ( is_array( $stored ) ) {
			delete_transient( 'gps_state_' . $token );
			$state['errors'] = isset( $stored['errors'] ) ? (array) $stored['errors'] : array();
			$state['old']    = isset( $stored['old'] ) ? (array) $stored['old'] : array();
		}

		return $state;
	}

	/**
	 * Helper for templates: read a previously submitted value.
	 *
	 * @param array  $old  Old input.
	 * @param string $key  Field name.
	 * @return string
	 */
	public static function old( $old, $key ) {
		return isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
	}

	/**
	 * Build the signed timestamp used by the bot time-trap.
	 *
	 * We send the render time to the browser and check on submit that at least
	 * N seconds elapsed. Humans take 30+ seconds to write a blog post; naive
	 * bots submit instantly.
	 *
	 * The timestamp is paired with an HMAC so it cannot simply be edited in
	 * devtools to an older value. wp_hash() keys the HMAC with the site's
	 * AUTH_SALT, which an attacker does not have.
	 *
	 * @return array{time:int,hash:string}
	 */
	public static function timestamp_pair() {
		$time = time();

		return array(
			'time' => $time,
			'hash' => wp_hash( 'gps_ts_' . $time ),
		);
	}

	/**
	 * Verify a timestamp/hash pair.
	 *
	 * @param string $time Submitted timestamp.
	 * @param string $hash Submitted hash.
	 * @return bool
	 */
	public static function verify_timestamp( $time, $hash ) {
		$time = (int) $time;

		if ( ! $time ) {
			return false;
		}

		/*
		 * hash_equals() compares in constant time. A normal === on a secret
		 * returns as soon as it finds a differing byte, which leaks how many
		 * leading bytes were correct and allows a timing attack. Always use
		 * hash_equals for anything secret-derived.
		 */
		return hash_equals( wp_hash( 'gps_ts_' . $time ), (string) $hash );
	}
}
