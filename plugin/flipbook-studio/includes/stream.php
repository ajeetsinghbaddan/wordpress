<?php
/**
 * Delivery layer: the only route through which a stored PDF reaches a browser.
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the query variable the file endpoint listens on.
 *
 * A query variable is used instead of a rewrite rule so the endpoint works the
 * moment the plugin is active, even on sites with plain permalinks or a
 * cached rewrite table.
 *
 * @param array $vars Recognised query vars.
 * @return array
 */
function fbs_query_vars( $vars ) {
	$vars[] = 'fbs_file';
	return $vars;
}
add_filter( 'query_vars', 'fbs_query_vars' );

/**
 * Builds a signed, expiring URL for one flipbook's PDF.
 *
 * @param int    $book_id  Flipbook post ID.
 * @param string $unlock   Optional unlock ticket for password-protected books.
 * @param bool   $download Ask the browser to save rather than display.
 * @return string
 */
function fbs_file_url( $book_id, $unlock = '', $download = false ) {
	$args = array(
		'fbs_file' => (int) $book_id,
		'token'    => fbs_make_token( $book_id ),
	);

	if ( $unlock ) {
		$args['unlock'] = $unlock;
	}

	if ( $download ) {
		$args['dl'] = 1;
	}

	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Serves a PDF when the request carries a valid signed token.
 *
 * Order matters here: cheap checks first (rate limit, token shape), expensive
 * ones last (database lookups, disk access). A flood of forged requests should
 * be rejected before it can touch the database.
 */
function fbs_handle_file_request() {
	$book_id = (int) get_query_var( 'fbs_file' );

	if ( ! $book_id ) {
		return;
	}

	if ( ! fbs_rate_limit( 'file', 120, MINUTE_IN_SECONDS ) ) {
		fbs_send_error( 429, __( 'Too many requests. Wait a moment and reload.', 'flipbook-studio' ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$unlock = isset( $_GET['unlock'] ) ? sanitize_text_field( wp_unslash( $_GET['unlock'] ) ) : '';
	$is_dl  = ! empty( $_GET['dl'] );
	// phpcs:enable

	if ( ! fbs_verify_token( $token, $book_id ) ) {
		fbs_send_error( 403, __( 'This reader link has expired. Reload the page to get a new one.', 'flipbook-studio' ) );
	}

	if ( 'allowed' !== fbs_access_status( $book_id, $unlock ) ) {
		fbs_send_error( 403, __( 'You do not have access to this flipbook.', 'flipbook-studio' ) );
	}

	if ( $is_dl && ! fbs_get_meta( $book_id, '_fbs_allow_download' ) && ! current_user_can( 'edit_post', $book_id ) ) {
		fbs_send_error( 403, __( 'Downloading is turned off for this flipbook.', 'flipbook-studio' ) );
	}

	$path = fbs_resolve_path( get_post_meta( $book_id, '_fbs_file', true ) );
	if ( ! $path ) {
		fbs_send_error( 404, __( 'The file for this flipbook is missing.', 'flipbook-studio' ) );
	}

	fbs_stream_file( $path, $book_id, $is_dl );
}
add_action( 'template_redirect', 'fbs_handle_file_request' );

/**
 * Sends a file to the browser, honouring HTTP range requests.
 *
 * Range support is what lets PDF.js fetch only the bytes it needs. On a 200 MB
 * catalogue the reader can show page one after a few hundred kilobytes instead
 * of waiting for the whole file, and the server never has to hold it in memory.
 *
 * @param string $path     Absolute file path (already validated).
 * @param int    $book_id  Flipbook post ID.
 * @param bool   $download Send as an attachment.
 */
function fbs_stream_file( $path, $book_id, $download = false ) {
	$size     = (int) filesize( $path );
	$filename = get_post_meta( $book_id, '_fbs_filename', true );
	$filename = $filename ? $filename : 'flipbook.pdf';

	// Any buffering or gzip layer would corrupt a byte range, so clear them.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
	@ini_set( 'zlib.output_compression', 'Off' );
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	$start = 0;
	$end   = $size - 1;
	$range = isset( $_SERVER['HTTP_RANGE'] ) ? wp_unslash( $_SERVER['HTTP_RANGE'] ) : '';

	if ( $range && preg_match( '/bytes=(\d*)-(\d*)/', $range, $m ) ) {
		$start = ( '' === $m[1] ) ? 0 : (int) $m[1];
		$end   = ( '' === $m[2] ) ? $size - 1 : (int) $m[2];

		if ( $start > $end || $start >= $size ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}

		$end = min( $end, $size - 1 );
		status_header( 206 );
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
	} else {
		status_header( 200 );
	}

	$length = $end - $start + 1;

	header( 'Content-Type: application/pdf' );
	header( 'Content-Length: ' . $length );
	header( 'Accept-Ranges: bytes' );
	// nosniff stops a browser from second-guessing the type and executing the
	// body as something else if the file were ever crafted to look ambiguous.
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	// private + no-store keeps signed responses out of shared proxy caches and
	// out of the browser's disk cache, so an expired link cannot be replayed.
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . rawurlencode( $filename ) . '"' );

	$handle = fopen( $path, 'rb' ); // phpcs:ignore
	if ( ! $handle ) {
		fbs_send_error( 500, __( 'The file could not be opened.', 'flipbook-studio' ) );
	}

	fseek( $handle, $start );
	$chunk     = 256 * KB_IN_BYTES;
	$remaining = $length;

	while ( $remaining > 0 && ! feof( $handle ) ) {
		$buffer = fread( $handle, (int) min( $chunk, $remaining ) ); // phpcs:ignore

		if ( false === $buffer || '' === $buffer ) {
			break;
		}

		echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$remaining -= strlen( $buffer );
		flush();
	}

	fclose( $handle ); // phpcs:ignore
	exit;
}

/**
 * Ends the request with a plain status and message.
 *
 * @param int    $code    HTTP status code.
 * @param string $message Human readable reason.
 */
function fbs_send_error( $code, $message ) {
	status_header( $code );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo esc_html( $message );
	exit;
}

/**
 * Registers the small JSON endpoints the reader talks to.
 *
 * These are separate from the file endpoint because they exchange JSON, and
 * the REST API already gives us routing, argument validation and CORS handling.
 */
function fbs_register_routes() {
	register_rest_route(
		'flipbook/v1',
		'/unlock',
		array(
			'methods'             => 'POST',
			'callback'            => 'fbs_rest_unlock',
			'permission_callback' => '__return_true',
			'args'                => array(
				'id'       => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'password' => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		'flipbook/v1',
		'/token',
		array(
			'methods'             => 'POST',
			'callback'            => 'fbs_rest_token',
			'permission_callback' => '__return_true',
			'args'                => array(
				'id'     => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'unlock' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		)
	);

	register_rest_route(
		'flipbook/v1',
		'/view',
		array(
			'methods'             => 'POST',
			'callback'            => 'fbs_rest_view',
			'permission_callback' => '__return_true',
			'args'                => array(
				'id'      => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'page'    => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'session' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'fbs_register_routes' );

/**
 * Checks a submitted password and hands back an unlock ticket.
 *
 * Rate limited hard, because this is the one endpoint where guessing pays off.
 * The response is deliberately identical in shape and timing-insensitive: it
 * never reveals whether the book exists, only whether the password matched.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function fbs_rest_unlock( $request ) {
	if ( ! fbs_rate_limit( 'unlock', 8, 5 * MINUTE_IN_SECONDS ) ) {
		return new WP_REST_Response(
			array( 'ok' => false, 'message' => __( 'Too many attempts. Try again in a few minutes.', 'flipbook-studio' ) ),
			429
		);
	}

	$book_id = (int) $request->get_param( 'id' );
	$hash    = get_post_meta( $book_id, '_fbs_password', true );
	$plain   = (string) $request->get_param( 'password' );

	if ( ! $hash || ! wp_check_password( $plain, $hash ) ) {
		return new WP_REST_Response(
			array( 'ok' => false, 'message' => __( 'That password is not right.', 'flipbook-studio' ) ),
			401
		);
	}

	$ticket = fbs_issue_unlock( $book_id );

	return new WP_REST_Response(
		array(
			'ok'     => true,
			'unlock' => $ticket,
			'file'   => fbs_file_url( $book_id, $ticket ),
		),
		200
	);
}

/**
 * Issues a fresh signed file URL.
 *
 * The reader calls this when its current link is close to expiring, so a long
 * reading session never breaks even though each individual link is short-lived.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function fbs_rest_token( $request ) {
	$book_id = (int) $request->get_param( 'id' );
	$unlock  = (string) $request->get_param( 'unlock' );

	if ( 'allowed' !== fbs_access_status( $book_id, $unlock ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 403 );
	}

	return new WP_REST_Response(
		array(
			'ok'        => true,
			'file'      => fbs_file_url( $book_id, $unlock ),
			'expiresIn' => (int) fbs_setting( 'token_ttl', 900 ),
		),
		200
	);
}

/**
 * Records that a page was read.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function fbs_rest_view( $request ) {
	if ( ! fbs_setting( 'analytics', 1 ) || ! fbs_rate_limit( 'view', 200, MINUTE_IN_SECONDS ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 200 );
	}

	fbs_record_view(
		(int) $request->get_param( 'id' ),
		(int) $request->get_param( 'page' ),
		(string) $request->get_param( 'session' )
	);

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}
