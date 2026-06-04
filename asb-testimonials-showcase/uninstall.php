<?php
/**
 * Uninstall routine for ASB Testimonials Showcase.
 *
 * This file runs ONLY when the user deletes the plugin from the Plugins screen
 * (not on deactivate). WordPress loads it automatically because it is named
 * uninstall.php and sits in the plugin root.
 *
 * @package ASB_Testimonials_Showcase
 */

/*
 * SECURITY: WP_UNINSTALL_PLUGIN is defined by WordPress only when it legitimately
 * triggers an uninstall. If this constant is missing, the file was accessed some
 * other way (e.g. directly) and we exit so nothing runs out of context.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The single option key the plugin uses (kept in sync with ASB_TS_Settings).
$asb_ts_option_key = 'asb_ts_settings';

// Read the saved settings to decide how aggressive cleanup should be.
$asb_ts_settings = get_option( $asb_ts_option_key, array() );
$asb_ts_delete   = is_array( $asb_ts_settings ) && ! empty( $asb_ts_settings['delete_data'] );

/*
 * Always remove the plugin's own option so no orphaned settings remain.
 * This is the minimum "leave nothing behind" cleanup.
 */
delete_option( $asb_ts_option_key );

/*
 * Only if the admin explicitly opted in (the "Also delete all testimonials"
 * setting) do we destroy user content. Deleting content by default would be a
 * nasty surprise, so it is strictly behind this toggle.
 */
if ( $asb_ts_delete ) {

	$asb_ts_post_type = 'testimonial';
	$asb_ts_taxonomy  = 'testimonial_category';

	// Delete every testimonial (and its meta) permanently. We page through in
	// batches to stay memory-friendly on sites with many testimonials.
	$paged = true;
	while ( $paged ) {
		$posts = get_posts(
			array(
				'post_type'      => $asb_ts_post_type,
				'post_status'    => 'any',
				'numberposts'    => 200,
				'fields'         => 'ids',
				'suppress_filters' => true,
			)
		);

		if ( empty( $posts ) ) {
			$paged = false;
			break;
		}

		foreach ( $posts as $post_id ) {
			// true = bypass trash and delete permanently (also clears post meta).
			wp_delete_post( $post_id, true );
		}
	}

	// Delete all terms in our taxonomy so no orphan categories remain.
	$terms = get_terms(
		array(
			'taxonomy'   => $asb_ts_taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( $term_id, $asb_ts_taxonomy );
		}
	}
}
