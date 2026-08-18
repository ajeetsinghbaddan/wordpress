<?php
/**
 * Featured image uploads from untrusted visitors.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * File uploads are the highest-risk feature in this plugin.
 *
 * The nightmare scenario is an attacker uploading "photo.php" (or
 * "photo.php.jpg", or a JPEG with PHP appended to it) into a web-accessible
 * directory and then requesting it, giving them arbitrary code execution.
 *
 * Our defence is layered:
 *   1. Only run at all if the site owner enabled image uploads.
 *   2. Reject on any PHP upload error before touching the file.
 *   3. Enforce a size limit.
 *   4. wp_check_filetype_and_ext() -- compares the claimed extension against
 *      the file's real magic bytes, and rejects mismatches.
 *   5. getimagesize() -- proves the bytes actually decode as an image.
 *   6. wp_handle_upload() via media_handle_upload(), which sanitizes the
 *      filename and refuses any extension not in the site's allowed MIME map.
 *
 * Any one of these can be argued around; together they are hard to defeat.
 */
class GPS_Media {

	/**
	 * Validate and attach an uploaded image as the post's featured image.
	 *
	 * @param string $field_name Key in $_FILES.
	 * @param int    $post_id    Post to attach to.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public static function attach_featured_image( $field_name, $post_id ) {
		if ( empty( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
			return new WP_Error( 'gps_no_file', __( 'No file was uploaded.', 'guest-post-submissions' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each member is validated individually below.
		$file = $_FILES[ $field_name ];

		// ---- Layer 2: PHP-level upload errors --------------------------
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'gps_upload_error', __( 'The image could not be uploaded.', 'guest-post-submissions' ) );
		}

		/*
		 * is_uploaded_file() confirms the temp path really came from an HTTP
		 * upload in this request. Without it, a bug elsewhere that let an
		 * attacker control $_FILES['tmp_name'] could be used to read arbitrary
		 * server files such as wp-config.php.
		 */
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gps_upload_invalid', __( 'The upload was not valid.', 'guest-post-submissions' ) );
		}

		// ---- Layer 3: size --------------------------------------------
		$max_bytes = (int) GPS_Settings::get( 'max_image_kb' ) * 1024;

		if ( (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'gps_too_big', __( 'The image is larger than the allowed size.', 'guest-post-submissions' ) );
		}

		// ---- Layer 4: extension vs real content ------------------------
		$allowed_mimes = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		);

		/*
		 * wp_check_filetype_and_ext reads the file's magic bytes via finfo and
		 * compares them with the extension. A file named "shell.php.jpg" whose
		 * content is PHP fails here, and so does a real JPEG renamed to .php.
		 *
		 * Note we pass the ALLOWED MIME MAP as the fourth argument, so even a
		 * site that has enabled SVG uploads globally cannot receive an SVG
		 * here -- SVG is XML and can carry script.
		 */
		$check = wp_check_filetype_and_ext(
			$file['tmp_name'],
			$file['name'],
			$allowed_mimes
		);

		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $check['type'], $allowed_mimes, true ) ) {
			return new WP_Error( 'gps_bad_type', __( 'Only JPG, PNG, GIF and WebP images are accepted.', 'guest-post-submissions' ) );
		}

		// ---- Layer 5: does it actually decode as an image? -------------
		$dimensions = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $dimensions || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return new WP_Error( 'gps_not_image', __( 'That file is not a readable image.', 'guest-post-submissions' ) );
		}

		// ---- Layer 6: hand off to core ---------------------------------
		/*
		 * These admin includes are not loaded on front-end requests, and
		 * admin-post.php does NOT load them either. Forgetting these produces
		 * "call to undefined function media_handle_upload()" -- a very common
		 * bug in front-end upload code.
		 */
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload(
			$field_name,
			$post_id,
			array(),
			array(
				/*
				 * test_form => false tells wp_handle_upload not to look for an
				 * 'action' field matching its own expectation. We already
				 * verified our nonce; this check would otherwise reject the
				 * upload outright.
				 */
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		// Tag the attachment so uninstall/cleanup can find guest uploads.
		update_post_meta( $attachment_id, '_gps_guest_upload', 1 );

		return $attachment_id;
	}
}
