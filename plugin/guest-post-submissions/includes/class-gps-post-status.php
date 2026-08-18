<?php
/**
 * Custom post status for rejected submissions.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Rejected" post status.
 *
 * WHY A CUSTOM STATUS INSTEAD OF DELETING?
 *
 * If a rejected post is deleted, the same spammer can resubmit it and a
 * moderator has no memory of having seen it. Keeping rejected posts gives you
 * an audit trail, lets you store a rejection reason, and lets you reverse a
 * decision. A custom status is how WordPress models "exists but is not one of
 * the built-in lifecycle states".
 *
 * We could have used 'draft' or 'trash', but both are overloaded with meaning
 * elsewhere in the admin -- a rejected guest post appearing in the normal
 * Drafts list would confuse editors.
 */
class GPS_Post_Status {

	/**
	 * Register the status with WordPress.
	 */
	public static function register() {
		register_post_status(
			GPS_Plugin::STATUS_REJECTED,
			array(
				'label' => _x( 'Rejected', 'post status', 'guest-post-submissions' ),

				/*
				 * public => false means the post is not viewable on the front
				 * end by anonymous visitors. This is the single most important
				 * flag here: a rejected post must never be publicly readable.
				 */
				'public'                     => false,

				/*
				 * internal => false means it is a real, author-assignable
				 * status (like draft) rather than a system status (like
				 * auto-draft). We want it to behave like a normal state.
				 */
				'internal'                   => false,

				// Only users who can read private posts may view it.
				'private'                    => false,
				'protected'                  => true,

				// Keep rejected content out of site search and out of the
				// default "All Posts" list so editors aren't distracted by it.
				'exclude_from_search'        => true,
				'show_in_admin_all_list'     => false,
				'show_in_admin_status_list'  => true,

				/*
				 * _n_noop() registers a translatable string pair WITHOUT
				 * translating it immediately. Translating at registration time
				 * (which runs on 'init') can fire before the text domain is
				 * loaded, producing untranslated strings. _n_noop defers it.
				 */
				'label_count'                => _n_noop(
					'Rejected <span class="count">(%s)</span>',
					'Rejected <span class="count">(%s)</span>',
					'guest-post-submissions'
				),
			)
		);
	}
}
