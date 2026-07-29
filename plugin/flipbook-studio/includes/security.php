<?php
/**
 * Security layer.
 *
 * Everything that decides *where files live*, *who may read them* and
 * *how a request proves it is allowed* lives in this file.
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Absolute path to the private folder that holds uploaded PDFs.
 *
 * The folder sits inside wp-content/uploads so it survives updates, but it is
 * blocked from direct HTTP access (see fbs_prepare_protected_dir). Nothing in
 * here is ever linked to directly; files are only served through the streaming
 * endpoint after an access check.
 *
 * @return string
 */
function fbs_protected_dir() {
	$uploads = wp_upload_dir();
	return trailingslashit( $uploads['basedir'] ) . 'flipbook-protected';
}

/**
 * Creates the private folder and drops in the deny rules.
 *
 * Three files are written because three server stacks read three different
 * things: Apache reads .htaccess, IIS reads web.config, and a blank index.php
 * stops directory listing everywhere. Nginx ignores all of them, which is why
 * the settings screen shows an nginx snippet and a live self-test.
 */
function fbs_prepare_protected_dir() {
	$dir = fbs_protected_dir();

	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$htaccess = "# Flipbook Studio - deny all direct HTTP access.\n"
		. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	$webconfig = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. "<configuration><system.webServer><authorization>\n"
		. "<deny users=\"*\" />\n"
		. "</authorization></system.webServer></configuration>\n";

	fbs_write_file( $dir . '/.htaccess', $htaccess );
	fbs_write_file( $dir . '/web.config', $webconfig );
	fbs_write_file( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
}

/**
 * Writes a file only when the contents differ, using the WP filesystem rules.
 *
 * @param string $path     Absolute path.
 * @param string $contents File body.
 * @return bool
 */
function fbs_write_file( $path, $contents ) {
	if ( file_exists( $path ) && md5_file( $path ) === md5( $contents ) ) {
		return true;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return false !== @file_put_contents( $path, $contents, LOCK_EX );
}

/**
 * The secret used to sign file URLs.
 *
 * Stored separately from WordPress salts so that rotating it (by deleting the
 * option) instantly invalidates every share link that is still in the wild,
 * without logging anybody out of the site.
 *
 * @return string
 */
function fbs_signing_key() {
	$key = get_option( 'fbs_signing_key' );

	if ( ! $key ) {
		$key = wp_generate_password( 64, true, true );
		add_option( 'fbs_signing_key', $key, '', false );
	}

	return $key;
}

/**
 * Fingerprint of the current visitor.
 *
 * A signed URL is bound to this value, so a token copied out of the network
 * tab and pasted into a different browser stops working. The user agent is
 * always included; the IP is opt-in because mobile networks rotate addresses
 * and would break legitimate readers mid-session.
 *
 * @return string
 */
function fbs_client_fingerprint() {
	$parts = array( (string) get_current_user_id() );

	$parts[] = isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';

	if ( fbs_setting( 'bind_to_ip', 0 ) ) {
		$parts[] = fbs_client_ip();
	}

	return hash( 'sha256', implode( '|', $parts ) );
}

/**
 * Best-effort client IP.
 *
 * Proxy headers are only trusted when the site owner opts in, because a
 * forged X-Forwarded-For would otherwise let an attacker sidestep rate limits.
 *
 * @return string
 */
function fbs_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

	if ( apply_filters( 'fbs_trust_proxy_headers', false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$ip        = trim( $forwarded[0] );
	}

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Builds a short-lived signed token for one flipbook.
 *
 * Format: base64url( expiry . "|" . signature ). The signature covers the book
 * id, the expiry and the visitor fingerprint, so none of the three can be
 * edited without invalidating it. There is no secret inside the token itself,
 * which is why it is safe to put in a URL.
 *
 * @param int      $book_id Flipbook post ID.
 * @param int|null $ttl     Lifetime in seconds.
 * @return string
 */
function fbs_make_token( $book_id, $ttl = null ) {
	$ttl     = null === $ttl ? (int) fbs_setting( 'token_ttl', 900 ) : (int) $ttl;
	$expires = time() + max( 60, $ttl );
	$payload = (int) $book_id . '|' . $expires . '|' . fbs_client_fingerprint();
	$sig     = hash_hmac( 'sha256', $payload, fbs_signing_key() );

	return rtrim( strtr( base64_encode( $expires . '|' . $sig ), '+/', '-_' ), '=' ); // phpcs:ignore
}

/**
 * Validates a token produced by fbs_make_token().
 *
 * @param string $token   Raw token from the request.
 * @param int    $book_id Flipbook post ID being requested.
 * @return bool
 */
function fbs_verify_token( $token, $book_id ) {
	if ( ! is_string( $token ) || '' === $token ) {
		return false;
	}

	$raw = base64_decode( strtr( $token, '-_', '+/' ), true ); // phpcs:ignore
	if ( false === $raw || false === strpos( $raw, '|' ) ) {
		return false;
	}

	list( $expires, $sig ) = array_pad( explode( '|', $raw, 2 ), 2, '' );
	$expires               = (int) $expires;

	if ( $expires < time() ) {
		return false;
	}

	$expected = hash_hmac(
		'sha256',
		(int) $book_id . '|' . $expires . '|' . fbs_client_fingerprint(),
		fbs_signing_key()
	);

	// hash_equals compares in constant time so an attacker cannot learn the
	// correct signature one byte at a time by measuring response speed.
	return hash_equals( $expected, $sig );
}

/**
 * Validates an uploaded file before it is allowed anywhere near the disk.
 *
 * Four independent checks, because any single one can be fooled:
 *   1. PHP's own upload error code (catches truncated / oversized uploads).
 *   2. Size ceiling from settings.
 *   3. WordPress extension + MIME agreement check.
 *   4. The first bytes of the file really are the PDF magic number.
 *
 * @param array $file One entry from $_FILES.
 * @return true|WP_Error
 */
function fbs_validate_upload( $file ) {
	if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'fbs_no_file', __( 'No file was received.', 'flipbook-studio' ) );
	}

	if ( ! empty( $file['error'] ) ) {
		return new WP_Error( 'fbs_upload_error', __( 'The upload did not complete. Try a smaller file or check the server upload limit.', 'flipbook-studio' ) );
	}

	$max_bytes = (int) fbs_setting( 'max_upload_mb', 64 ) * MB_IN_BYTES;
	if ( $file['size'] > $max_bytes ) {
		return new WP_Error(
			'fbs_too_large',
			sprintf(
				/* translators: %s: size limit in megabytes. */
				__( 'That PDF is larger than the %s MB limit set in Flipbook settings.', 'flipbook-studio' ),
				number_format_i18n( fbs_setting( 'max_upload_mb', 64 ) )
			)
		);
	}

	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'pdf' => 'application/pdf' ) );
	if ( 'pdf' !== $check['ext'] || 'application/pdf' !== $check['type'] ) {
		return new WP_Error( 'fbs_not_pdf', __( 'Only PDF files can be uploaded.', 'flipbook-studio' ) );
	}

	// A file can be named .pdf and declared as application/pdf and still be a
	// PHP script. Reading the real header is the check that cannot be spoofed.
	$handle = fopen( $file['tmp_name'], 'rb' ); // phpcs:ignore
	$head   = $handle ? fread( $handle, 1024 ) : ''; // phpcs:ignore
	if ( $handle ) {
		fclose( $handle ); // phpcs:ignore
	}

	if ( 0 !== strpos( ltrim( $head ), '%PDF-' ) ) {
		return new WP_Error( 'fbs_bad_header', __( 'This file does not look like a real PDF.', 'flipbook-studio' ) );
	}

	return true;
}

/**
 * Moves a validated upload into the private folder.
 *
 * The stored name is randomised, so even if a server is misconfigured and the
 * folder becomes readable, the path is not guessable from the post title.
 *
 * @param array $file    One entry from $_FILES.
 * @param int   $book_id Flipbook post ID.
 * @return array|WP_Error Array with 'path' (relative) and 'name' (original).
 */
function fbs_store_upload( $file, $book_id ) {
	$valid = fbs_validate_upload( $file );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	fbs_prepare_protected_dir();

	$folder = (int) $book_id . '-' . wp_generate_password( 12, false, false );
	$target = trailingslashit( fbs_protected_dir() ) . $folder;

	if ( ! wp_mkdir_p( $target ) ) {
		return new WP_Error( 'fbs_mkdir', __( 'The private upload folder could not be created. Check file permissions on wp-content/uploads.', 'flipbook-studio' ) );
	}

	$stored = wp_generate_password( 24, false, false ) . '.pdf';

	if ( ! move_uploaded_file( $file['tmp_name'], $target . '/' . $stored ) ) {
		return new WP_Error( 'fbs_move', __( 'The uploaded file could not be saved.', 'flipbook-studio' ) );
	}

	// 0644: readable by the web server, never executable.
	@chmod( $target . '/' . $stored, 0644 ); // phpcs:ignore

	return array(
		'path' => $folder . '/' . $stored,
		'name' => sanitize_file_name( $file['name'] ),
		'size' => (int) $file['size'],
	);
}

/**
 * Resolves a stored relative path to a real absolute path.
 *
 * This is the anti path-traversal gate. A stored value of "../../wp-config.php"
 * would resolve outside the protected folder, and the prefix comparison rejects
 * it before anything is opened.
 *
 * @param string $relative Value from post meta.
 * @return string|false
 */
function fbs_resolve_path( $relative ) {
	if ( ! is_string( $relative ) || '' === $relative ) {
		return false;
	}

	$base = realpath( fbs_protected_dir() );
	$full = realpath( trailingslashit( fbs_protected_dir() ) . ltrim( $relative, '/\\' ) );

	if ( ! $base || ! $full || 0 !== strpos( $full, $base . DIRECTORY_SEPARATOR ) ) {
		return false;
	}

	return is_file( $full ) ? $full : false;
}

/**
 * Deletes the stored PDF and its folder for a flipbook.
 *
 * @param int $book_id Flipbook post ID.
 */
function fbs_delete_stored_file( $book_id ) {
	$relative = get_post_meta( $book_id, '_fbs_file', true );
	$path     = fbs_resolve_path( $relative );

	if ( $path ) {
		wp_delete_file( $path );
		$folder = dirname( $path );
		if ( is_dir( $folder ) && 2 === count( (array) scandir( $folder ) ) ) {
			@rmdir( $folder ); // phpcs:ignore
		}
	}

	delete_post_meta( $book_id, '_fbs_file' );
	delete_post_meta( $book_id, '_fbs_filename' );
	delete_post_meta( $book_id, '_fbs_filesize' );
}

/**
 * Decides whether the current request may read a flipbook.
 *
 * Returns a status string rather than a bare boolean so the front end can tell
 * "you need the password" apart from "this expired" and show the right screen.
 *
 * @param WP_Post|int $book Flipbook.
 * @param string      $unlock Optional unlock ticket from a password submission.
 * @return string One of: allowed, not_found, no_file, login_required, password_required, expired, blocked.
 */
function fbs_access_status( $book, $unlock = '' ) {
	$book = get_post( $book );

	if ( ! $book || FBS_POST_TYPE !== $book->post_type ) {
		return 'not_found';
	}

	// Editors previewing their own draft always get through.
	$is_editor = current_user_can( 'edit_post', $book->ID );

	if ( 'publish' !== $book->post_status && ! $is_editor ) {
		return 'not_found';
	}

	if ( ! fbs_resolve_path( get_post_meta( $book->ID, '_fbs_file', true ) ) ) {
		return 'no_file';
	}

	if ( $is_editor ) {
		return 'allowed';
	}

	// The stored value is site-local wall-clock time, so it is converted to UTC
	// before being compared with time(). Mixing the two is the classic way an
	// expiry silently fires hours early or late.
	$expires = get_post_meta( $book->ID, '_fbs_expires', true );
	if ( $expires ) {
		$expires_utc = strtotime( get_gmt_from_date( $expires ) . ' UTC' );
		if ( $expires_utc && time() > $expires_utc ) {
			return 'expired';
		}
	}

	if ( get_post_meta( $book->ID, '_fbs_require_login', true ) && ! is_user_logged_in() ) {
		return 'login_required';
	}

	if ( ! fbs_referer_allowed( $book->ID ) ) {
		return 'blocked';
	}

	$hash = get_post_meta( $book->ID, '_fbs_password', true );
	if ( $hash && ! fbs_unlock_is_valid( $unlock, $book->ID ) ) {
		return 'password_required';
	}

	return 'allowed';
}

/**
 * Checks the request referer against the per-book domain allow list.
 *
 * This is what stops somebody hot-linking your flipbook into their own site.
 * It is a deterrent, not a wall: referers can be forged. It is layered on top
 * of the token, not instead of it.
 *
 * @param int $book_id Flipbook post ID.
 * @return bool
 */
function fbs_referer_allowed( $book_id ) {
	$raw = trim( (string) get_post_meta( $book_id, '_fbs_allowed_domains', true ) );

	if ( '' === $raw ) {
		return true;
	}

	$referer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
	$host    = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );

	if ( '' === $host ) {
		return false;
	}

	foreach ( preg_split( '/[\r\n,]+/', $raw ) as $allowed ) {
		$allowed = strtolower( trim( $allowed ) );
		if ( '' === $allowed ) {
			continue;
		}
		if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
			return true;
		}
	}

	return false;
}

/**
 * Issues an unlock ticket after a correct password.
 *
 * The ticket is a random string; only its hash and the book id are stored
 * server side, in a transient that expires on its own. The browser keeps the
 * ticket in sessionStorage, so it dies when the tab closes and it is never
 * written to a cookie that other requests would carry around.
 *
 * @param int $book_id Flipbook post ID.
 * @return string
 */
function fbs_issue_unlock( $book_id ) {
	$ticket = wp_generate_password( 40, false, false );
	set_transient( 'fbs_unlock_' . hash( 'sha256', $ticket ), (int) $book_id, 2 * HOUR_IN_SECONDS );
	return $ticket;
}

/**
 * Validates an unlock ticket for a book.
 *
 * @param string $ticket  Ticket string sent by the browser.
 * @param int    $book_id Flipbook post ID.
 * @return bool
 */
function fbs_unlock_is_valid( $ticket, $book_id ) {
	if ( ! is_string( $ticket ) || '' === $ticket ) {
		return false;
	}
	return (int) get_transient( 'fbs_unlock_' . hash( 'sha256', $ticket ) ) === (int) $book_id;
}

/**
 * Simple transient-backed rate limiter.
 *
 * Guards the password endpoint against brute force and the streaming endpoint
 * against someone scripting a bulk download.
 *
 * @param string $bucket Name of the thing being limited.
 * @param int    $limit  Allowed hits per window.
 * @param int    $window Window length in seconds.
 * @return bool True when the request is within budget.
 */
function fbs_rate_limit( $bucket, $limit = 30, $window = MINUTE_IN_SECONDS ) {
	$key   = 'fbs_rl_' . hash( 'sha256', $bucket . '|' . fbs_client_ip() );
	$hits  = (int) get_transient( $key );
	$hits++;

	set_transient( $key, $hits, $window );

	return $hits <= $limit;
}
