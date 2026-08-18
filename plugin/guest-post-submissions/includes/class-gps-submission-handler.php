<?php
/**
 * Processes the front-end form POST.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * The security boundary of the plugin.
 *
 * Every byte that reaches this class came from a stranger on the internet.
 * The order of checks below is deliberate: cheapest and most decisive first,
 * so an attacker's request is dropped before it costs us a database write.
 *
 *   1. Nonce            -- is this our form?
 *   2. Honeypot         -- is this a naive bot?
 *   3. Time trap        -- was it filled implausibly fast?
 *   4. Rate limit       -- has this IP flooded us?
 *   5. Field validation -- is the content usable?
 *   6. Insert           -- only now do we touch the database.
 */
class GPS_Submission_Handler {

	/**
	 * Register the endpoint.
	 *
	 * WHY admin-post.php?
	 *
	 * WordPress provides two front-controllers for form submissions:
	 * admin-ajax.php (for XHR) and admin-post.php (for normal form posts).
	 * Using admin-post.php means:
	 *
	 *   - The request is routed before any theme template loads, so we can
	 *     redirect cleanly without "headers already sent" errors.
	 *   - WordPress is fully loaded, so all APIs are available.
	 *   - We are not hijacking 'init' or 'template_redirect' and running our
	 *     handler on every single page view.
	 *
	 * The _nopriv_ variant fires for logged-OUT users. You need BOTH hooks:
	 * registering only admin_post_ silently ignores anonymous visitors, which
	 * is the entire audience for a guest post form. This trips up almost
	 * everyone the first time.
	 */
	public static function init() {
		add_action( 'admin_post_nopriv_gps_submit_post', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_gps_submit_post', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle the POST request.
	 */
	public static function handle() {
		$redirect_to = self::resolve_redirect();

		// ---------------------------------------------------------------
		// 1. NONCE -- cross-site request forgery protection.
		// ---------------------------------------------------------------
		/*
		 * A nonce is a token tied to the action name, the user session and a
		 * time window. It proves the request originated from a form we
		 * rendered, not from a hidden form on evil.com that auto-submits using
		 * the visitor's browser.
		 *
		 * wp_verify_nonce returns 1, 2, or false -- never compare with == to
		 * true carelessly; a plain falsy check is correct.
		 *
		 * Note nonces are per-session even for logged-out users (WordPress
		 * uses the nonce user ID 0 plus a session token), and they expire
		 * after 24 hours. That is why we regenerate the form on every render.
		 */
		if (
			! isset( $_POST[ GPS_Form::NONCE_NAME ] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ GPS_Form::NONCE_NAME ] ) ),
				GPS_Form::NONCE_ACT
			)
		) {
			self::fail(
				$redirect_to,
				new WP_Error( 'gps_nonce', __( 'Your session expired. Please reload the page and submit again.', 'guest-post-submissions' ) ),
				array()
			);
		}

		// ---------------------------------------------------------------
		// 2. HONEYPOT -- catches bots that fill every field they find.
		// ---------------------------------------------------------------
		/*
		 * The field is hidden with CSS and marked aria-hidden + tabindex="-1",
		 * so neither a sighted user nor a screen reader user will ever fill
		 * it. A scraper parsing the raw HTML has no idea it is hidden.
		 *
		 * We respond as if the submission succeeded. Telling a bot it was
		 * detected just teaches its operator to fix it.
		 */
		if ( ! empty( $_POST['gps_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'gps_status', 'success', $redirect_to ) );
			exit;
		}

		// ---------------------------------------------------------------
		// 3. TIME TRAP.
		// ---------------------------------------------------------------
		$min_seconds = (int) GPS_Settings::get( 'min_fill_seconds' );

		if ( $min_seconds > 0 ) {
			$ts   = isset( $_POST['gps_ts'] ) ? (int) $_POST['gps_ts'] : 0;
			$hash = isset( $_POST['gps_ts_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['gps_ts_hash'] ) ) : '';

			if ( ! GPS_Form::verify_timestamp( $ts, $hash ) || ( time() - $ts ) < $min_seconds ) {
				self::fail(
					$redirect_to,
					new WP_Error( 'gps_speed', __( 'That was submitted too quickly. Please try again.', 'guest-post-submissions' ) ),
					self::collect_old_input()
				);
			}
		}

		// ---------------------------------------------------------------
		// 4. RATE LIMIT.
		// ---------------------------------------------------------------
		if ( GPS_Rate_Limiter::is_limited() ) {
			self::fail(
				$redirect_to,
				new WP_Error( 'gps_throttle', __( 'You have reached the submission limit. Please try again later.', 'guest-post-submissions' ) ),
				self::collect_old_input()
			);
		}

		// ---------------------------------------------------------------
		// 5. VALIDATE AND SANITIZE.
		// ---------------------------------------------------------------
		$errors = new WP_Error();
		$data   = self::validate( $errors );

		if ( $errors->has_errors() ) {
			self::fail( $redirect_to, $errors, self::collect_old_input() );
		}

		// ---------------------------------------------------------------
		// 6. CREATE THE POST.
		// ---------------------------------------------------------------
		$post_id = self::create_post( $data );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			self::fail(
				$redirect_to,
				new WP_Error( 'gps_insert', __( 'We could not save your submission. Please try again.', 'guest-post-submissions' ) ),
				self::collect_old_input()
			);
		}

		GPS_Rate_Limiter::record();

		/*
		 * The featured image is deliberately handled AFTER the post exists and
		 * is deliberately non-fatal. A visitor who just wrote 2,000 words
		 * should not lose all of it because their phone produced a HEIC file.
		 *
		 * But we do not swallow the failure either -- we record it on the post
		 * so a moderator can see why there is no image and ask for one.
		 */
		if ( GPS_Settings::get( 'allow_image' ) && ! empty( $_FILES['gps_image']['name'] ) ) {
			$image_result = GPS_Media::attach_featured_image( 'gps_image', $post_id );

			if ( is_wp_error( $image_result ) ) {
				update_post_meta( $post_id, '_gps_image_error', $image_result->get_error_message() );
			}
		}

		// Invalidate the cached "pending" badge count in the admin menu.
		delete_transient( 'gps_pending_count' );

		/**
		 * Fires after a guest submission is stored.
		 *
		 * Providing an action here is what makes the plugin extensible: a site
		 * can hook Slack notifications, Akismet checks, or a CRM sync without
		 * touching this file.
		 *
		 * @param int   $post_id New post ID.
		 * @param array $data    Sanitized submission data.
		 */
		do_action( 'gps_submission_created', $post_id, $data );

		wp_safe_redirect( add_query_arg( 'gps_status', 'success', $redirect_to ) );
		exit;
	}

	/**
	 * Validate and sanitize every field.
	 *
	 * SANITIZE vs VALIDATE -- they are not the same thing and you need both:
	 *   - Sanitizing makes a value SAFE   ("<b>Hi</b>" -> "Hi").
	 *   - Validating makes a value USABLE ("is this actually an email?").
	 * Sanitizing alone would happily store an empty title.
	 *
	 * @param WP_Error $errors Collector, passed by reference semantics.
	 * @return array Clean data.
	 */
	private static function validate( WP_Error $errors ) {
		$data = array();

		/*
		 * wp_unslash() first, ALWAYS. WordPress adds slashes to every value in
		 * $_POST/$_GET/$_COOKIE/$_REQUEST at boot (a legacy of magic quotes).
		 * If you sanitize without unslashing, an apostrophe in "O'Brien" is
		 * stored as "O\'Brien" and every sanitizer that follows sees the wrong
		 * string.
		 */
		$data['author_name'] = isset( $_POST['gps_author_name'] )
			? sanitize_text_field( wp_unslash( $_POST['gps_author_name'] ) )
			: '';

		if ( '' === $data['author_name'] ) {
			$errors->add( 'author_name', __( 'Enter your name.', 'guest-post-submissions' ) );
		} elseif ( mb_strlen( $data['author_name'] ) > 100 ) {
			$errors->add( 'author_name', __( 'Your name is too long (100 characters maximum).', 'guest-post-submissions' ) );
		}

		$data['author_email'] = isset( $_POST['gps_author_email'] )
			? sanitize_email( wp_unslash( $_POST['gps_author_email'] ) )
			: '';

		if ( ! is_email( $data['author_email'] ) ) {
			$errors->add( 'author_email', __( 'Enter a valid email address.', 'guest-post-submissions' ) );
		}

		/*
		 * esc_url_raw() for a URL destined for the DATABASE.
		 * esc_url()     for a URL destined for HTML OUTPUT.
		 * They differ: esc_url() entity-encodes ampersands for HTML, which
		 * would corrupt the stored value. Using the wrong one is a classic bug.
		 * Both strip dangerous protocols like javascript:.
		 */
		$data['author_url'] = isset( $_POST['gps_author_url'] )
			? esc_url_raw( trim( wp_unslash( $_POST['gps_author_url'] ) ), array( 'http', 'https' ) )
			: '';

		$data['author_bio'] = isset( $_POST['gps_author_bio'] )
			? sanitize_textarea_field( wp_unslash( $_POST['gps_author_bio'] ) )
			: '';

		if ( mb_strlen( $data['author_bio'] ) > 600 ) {
			$data['author_bio'] = mb_substr( $data['author_bio'], 0, 600 );
		}

		$data['title'] = isset( $_POST['gps_title'] )
			? sanitize_text_field( wp_unslash( $_POST['gps_title'] ) )
			: '';

		if ( '' === $data['title'] ) {
			$errors->add( 'title', __( 'Enter a title for your post.', 'guest-post-submissions' ) );
		} elseif ( mb_strlen( $data['title'] ) > 180 ) {
			$errors->add( 'title', __( 'Your title is too long (180 characters maximum).', 'guest-post-submissions' ) );
		}

		// ---------------- Content ----------------
		$raw_content = isset( $_POST['gps_content'] ) ? wp_unslash( $_POST['gps_content'] ) : '';

		/*
		 * wp_kses() with an explicit allowlist is the correct tool here.
		 *
		 * NOT wp_kses_post(): that permits the full set of tags an editor may
		 * use, which is far more than a stranger needs.
		 * NOT strip_tags(): it is not an XSS filter -- it does nothing about
		 * malicious attributes and can be defeated by malformed markup.
		 * NOT esc_html(): that would destroy the legitimate formatting.
		 *
		 * wp_kses parses the HTML and rebuilds it from only the tags and
		 * attributes you listed, dropping event handlers (onclick=),
		 * javascript: URLs, <script>, <iframe>, <style>, and anything else.
		 */
		$data['content'] = wp_kses( $raw_content, self::allowed_html() );

		$word_count = self::count_words( $data['content'] );
		$min_words  = (int) GPS_Settings::get( 'min_words' );
		$max_words  = (int) GPS_Settings::get( 'max_words' );

		if ( $word_count < $min_words ) {
			$errors->add(
				'content',
				sprintf(
					/* translators: 1: minimum word count, 2: current word count */
					__( 'Your post needs at least %1$d words. It currently has %2$d.', 'guest-post-submissions' ),
					$min_words,
					$word_count
				)
			);
		} elseif ( $word_count > $max_words ) {
			$errors->add(
				'content',
				sprintf(
					/* translators: 1: maximum word count, 2: current word count */
					__( 'Your post is limited to %1$d words. It currently has %2$d.', 'guest-post-submissions' ),
					$max_words,
					$word_count
				)
			);
		}

		// ---------------- Category ----------------
		/*
		 * Never trust a select field. The browser sends whatever the DOM held
		 * at submit time, and the DOM is fully editable in devtools. We check
		 * the submitted term ID against the same allowlist used to build the
		 * dropdown -- otherwise a visitor could file their post into any
		 * category on the site, including a private or promotional one.
		 */
		$submitted_cat = isset( $_POST['gps_category'] ) ? absint( $_POST['gps_category'] ) : 0;
		$allowed_cats  = array_map( 'intval', (array) GPS_Settings::get( 'allowed_categories' ) );

		if ( empty( $allowed_cats ) ) {
			$data['category'] = term_exists( $submitted_cat, 'category' )
				? $submitted_cat
				: (int) get_option( 'default_category' );
		} else {
			$data['category'] = in_array( $submitted_cat, $allowed_cats, true )
				? $submitted_cat
				: (int) $allowed_cats[0];
		}

		// ---------------- Tags ----------------
		$data['tags'] = array();

		if ( GPS_Settings::get( 'allow_tags' ) && ! empty( $_POST['gps_tags'] ) ) {
			$raw_tags = sanitize_text_field( wp_unslash( $_POST['gps_tags'] ) );
			$max_tags = (int) GPS_Settings::get( 'max_tags' );

			foreach ( array_filter( array_map( 'trim', explode( ',', $raw_tags ) ) ) as $tag ) {
				if ( count( $data['tags'] ) >= $max_tags ) {
					break; // Hard cap: stops someone creating 5,000 terms.
				}
				$tag = mb_substr( $tag, 0, 50 );
				if ( '' !== $tag ) {
					$data['tags'][] = $tag;
				}
			}
		}

		// ---------------- Consent ----------------
		if ( GPS_Settings::get( 'require_consent' ) && empty( $_POST['gps_consent'] ) ) {
			$errors->add( 'consent', __( 'Please confirm the submission terms before sending.', 'guest-post-submissions' ) );
		}

		/**
		 * Filter validation results.
		 *
		 * Use this to plug in Akismet, Turnstile, reCAPTCHA or a profanity
		 * check without editing the plugin:
		 *
		 *     add_action( 'gps_validate_submission', function ( $errors, $data ) {
		 *         if ( my_spam_check( $data['content'] ) ) {
		 *             $errors->add( 'spam', 'This looks like spam.' );
		 *         }
		 *     }, 10, 2 );
		 *
		 * @param WP_Error $errors Error collector.
		 * @param array    $data   Sanitized data so far.
		 */
		do_action( 'gps_validate_submission', $errors, $data );

		return $data;
	}

	/**
	 * The HTML a guest is allowed to use.
	 *
	 * Deliberately narrow. Note what is absent: script, iframe, style, form,
	 * object, embed, and any attribute starting with "on". Also absent is the
	 * "class" and "id" attribute -- a guest with arbitrary class names can
	 * hijack your theme's layout, and arbitrary IDs can break page anchors.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		/**
		 * Filter the allowed HTML for guest content.
		 *
		 * @param array $tags Allowed tags map.
		 */
		return apply_filters(
			'gps_allowed_html',
			array(
				'a'          => array(
					'href'   => array(),
					'title'  => array(),
					'rel'    => array(),
					'target' => array(),
				),
				'p'          => array(),
				'br'         => array(),
				'strong'     => array(),
				'b'          => array(),
				'em'         => array(),
				'i'          => array(),
				'u'          => array(),
				'ul'         => array(),
				'ol'         => array(),
				'li'         => array(),
				'h2'         => array(),
				'h3'         => array(),
				'h4'         => array(),
				'blockquote' => array( 'cite' => array() ),
				'code'       => array(),
				'pre'        => array(),
				'hr'         => array(),
				'figure'     => array(),
				'figcaption' => array(),
				'img'        => array(
					'src'    => array(),
					'alt'    => array(),
					'width'  => array(),
					'height' => array(),
				),
			)
		);
	}

	/**
	 * Count words in a way that works for non-Latin scripts.
	 *
	 * str_word_count() is byte-based and returns nonsense for Hindi, Arabic,
	 * Chinese or anything outside ASCII. Splitting on Unicode whitespace is
	 * both simpler and correct for the languages that use spaces.
	 *
	 * @param string $content HTML content.
	 * @return int
	 */
	private static function count_words( $content ) {
		$text = trim( wp_strip_all_tags( $content ) );

		if ( '' === $text ) {
			return 0;
		}

		$words = preg_split( '/[\s\x{00A0}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Insert the pending post.
	 *
	 * @param array $data Clean data.
	 * @return int|WP_Error
	 */
	private static function create_post( $data ) {
		$author_id = self::resolve_author_id();

		$post_id = wp_insert_post(
			array(
				/*
				 * post_status is HARDCODED to 'pending'. It is never read from
				 * the request. This is the whole point of the moderation
				 * queue: there must be no code path -- no hidden field, no
				 * query argument -- by which a visitor can reach 'publish'.
				 */
				'post_status'   => 'pending',
				'post_type'     => 'post',
				'post_author'   => $author_id,

				/*
				 * wp_slash() re-adds the slashes we removed with wp_unslash().
				 * wp_insert_post expects slashed data because it calls
				 * wp_unslash internally before writing. Skipping this silently
				 * strips backslashes from code samples in the post body.
				 */
				'post_title'    => wp_slash( $data['title'] ),
				'post_content'  => wp_slash( $data['content'] ),

				// Guests must not be able to open a comment thread on a post
				// that has not been reviewed yet.
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_category'  => array( $data['category'] ),
			),
			true // Return WP_Error instead of 0 so failures are diagnosable.
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $data['tags'] ) ) {
			// The 'false' means replace rather than append.
			wp_set_post_terms( $post_id, $data['tags'], 'post_tag', false );
		}

		/*
		 * update_post_meta with a leading underscore makes the key "protected":
		 * WordPress hides it from the Custom Fields metabox, so an editor
		 * cannot accidentally edit or delete our bookkeeping data.
		 */
		update_post_meta( $post_id, GPS_Plugin::META_IS_GUEST, 1 );
		update_post_meta( $post_id, GPS_Plugin::META_AUTHOR_NAME, $data['author_name'] );
		update_post_meta( $post_id, GPS_Plugin::META_AUTHOR_EMAIL, $data['author_email'] );
		update_post_meta( $post_id, GPS_Plugin::META_AUTHOR_URL, $data['author_url'] );
		update_post_meta( $post_id, GPS_Plugin::META_AUTHOR_BIO, $data['author_bio'] );

		// Hashed, not raw -- see GPS_Rate_Limiter for the reasoning.
		update_post_meta( $post_id, GPS_Plugin::META_SUBMIT_IP, wp_hash( GPS_Rate_Limiter::get_ip() ) );

		return $post_id;
	}

	/**
	 * Decide which WordPress account will own the post.
	 *
	 * The configured account can disappear -- someone deletes that user six
	 * months from now and nobody thinks about the guest post plugin. If we
	 * blindly used the stale ID, every post would be created with an author
	 * that does not exist, and themes calling get_the_author_meta() would
	 * render blanks or notices.
	 *
	 * So we verify, and fall back to the first administrator. Posts with
	 * post_author = 0 are the last resort, not the default.
	 *
	 * @return int
	 */
	private static function resolve_author_id() {
		$configured = (int) GPS_Settings::get( 'attribution_user' );

		if ( $configured && get_userdata( $configured ) ) {
			return $configured;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}

	/**
	 * Work out where to send the visitor back to.
	 *
	 * OPEN REDIRECT PROTECTION. The form carries the page URL in a hidden
	 * field so we can return to it. An attacker could change that field to
	 * their own site, and a redirect from your domain lends their phishing
	 * page credibility.
	 *
	 * wp_validate_redirect() checks the host against an allowlist (your own
	 * host by default) and falls back to the second argument otherwise. We
	 * also use wp_safe_redirect() at the call sites, which applies the same
	 * check again -- belt and braces.
	 *
	 * @return string
	 */
	private static function resolve_redirect() {
		$fallback = home_url( '/' );

		if ( empty( $_POST['gps_redirect'] ) ) {
			return $fallback;
		}

		$submitted = esc_url_raw( wp_unslash( $_POST['gps_redirect'] ) );

		return wp_validate_redirect( $submitted, $fallback );
	}

	/**
	 * Gather submitted values so the form can be re-rendered with them.
	 *
	 * These are sanitized here and escaped again on output -- the value is
	 * about to make a round trip through a transient and back into HTML.
	 *
	 * @return array
	 */
	private static function collect_old_input() {
		$keys = array( 'gps_author_name', 'gps_author_email', 'gps_author_url', 'gps_author_bio', 'gps_title', 'gps_tags' );
		$old  = array();

		foreach ( $keys as $key ) {
			$old[ $key ] = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}

		// Content keeps its (already filtered) markup so the draft survives.
		$old['gps_content']  = isset( $_POST['gps_content'] ) ? wp_kses( wp_unslash( $_POST['gps_content'] ), self::allowed_html() ) : '';
		$old['gps_category'] = isset( $_POST['gps_category'] ) ? absint( $_POST['gps_category'] ) : 0;
		$old['gps_consent']  = ! empty( $_POST['gps_consent'] ) ? 1 : 0;

		return $old;
	}

	/**
	 * Abort with errors and redirect back to the form.
	 *
	 * Marked as never returning -- it always exits.
	 *
	 * @param string   $redirect_to Destination.
	 * @param WP_Error $errors      Errors to show.
	 * @param array    $old         Values to restore.
	 */
	private static function fail( $redirect_to, WP_Error $errors, $old ) {
		$token = GPS_Form::store_state( $errors, $old );

		wp_safe_redirect(
			add_query_arg(
				array(
					'gps_status' => 'error',
					'gps_token'  => $token,
				),
				$redirect_to
			) . '#gps-form'
		);
		exit;
	}
}
