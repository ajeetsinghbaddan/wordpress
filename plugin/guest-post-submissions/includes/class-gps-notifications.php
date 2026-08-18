<?php
/**
 * Transactional emails.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the three emails this workflow needs.
 *
 * Everything here is hooked to actions rather than called directly from the
 * submission handler. That decoupling means notifications can be turned off,
 * replaced, or reordered by unhooking a single callback -- and it keeps email
 * concerns out of the security-critical validation code.
 */
class GPS_Notifications {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'gps_submission_created', array( __CLASS__, 'notify_moderator' ), 10, 2 );
		add_action( 'gps_submission_approved', array( __CLASS__, 'notify_author_approved' ) );
		add_action( 'gps_submission_rejected', array( __CLASS__, 'notify_author_rejected' ), 10, 2 );
	}

	/**
	 * Tell the moderator something is waiting.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Submission data.
	 */
	public static function notify_moderator( $post_id, $data ) {
		$to = GPS_Settings::get( 'notify_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] New guest post awaiting review', 'guest-post-submissions' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$review_url = admin_url( 'edit.php?page=gps-submissions' );

		/*
		 * Plain text, not HTML. Plain text emails are far less likely to be
		 * flagged as spam, render everywhere, and remove any possibility of
		 * the guest's input being interpreted as markup in someone's inbox.
		 *
		 * The values interpolated here are already sanitized, but note there
		 * is no escaping function for "email body" -- avoiding HTML is what
		 * makes this safe.
		 */
		$lines = array(
			sprintf(
				/* translators: %s: author name */
				__( '%s submitted a guest post.', 'guest-post-submissions' ),
				$data['author_name']
			),
			'',
			sprintf( __( 'Title: %s', 'guest-post-submissions' ), $data['title'] ),
			sprintf( __( 'Email: %s', 'guest-post-submissions' ), $data['author_email'] ),
			'',
			__( 'Review it here:', 'guest-post-submissions' ),
			$review_url,
		);

		self::send( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Congratulate the guest.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function notify_author_approved( $post_id ) {
		if ( ! GPS_Settings::get( 'notify_author' ) ) {
			return;
		}

		$to = get_post_meta( $post_id, GPS_Plugin::META_AUTHOR_EMAIL, true );

		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Your guest post is live', 'guest-post-submissions' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$lines = array(
			sprintf(
				/* translators: %s: post title */
				__( 'Good news - "%s" has been published.', 'guest-post-submissions' ),
				get_the_title( $post_id )
			),
			'',
			get_permalink( $post_id ),
			'',
			__( 'Thanks for writing for us.', 'guest-post-submissions' ),
		);

		self::send( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Let the guest down gently.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  Optional moderator note.
	 */
	public static function notify_author_rejected( $post_id, $reason = '' ) {
		if ( ! GPS_Settings::get( 'notify_author' ) ) {
			return;
		}

		$to = get_post_meta( $post_id, GPS_Plugin::META_AUTHOR_EMAIL, true );

		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] About your guest post submission', 'guest-post-submissions' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$lines = array(
			sprintf(
				/* translators: %s: post title */
				__( 'Thanks for sending us "%s". We are not going to publish it this time.', 'guest-post-submissions' ),
				get_the_title( $post_id )
			),
		);

		if ( $reason ) {
			$lines[] = '';
			$lines[] = __( 'Notes from the editor:', 'guest-post-submissions' );
			$lines[] = $reason;
		}

		$lines[] = '';
		$lines[] = __( 'You are welcome to submit something else.', 'guest-post-submissions' );

		self::send( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Wrapper around wp_mail.
	 *
	 * Note the header sanitization. Newlines in a subject or a "to" address
	 * are a header-injection vector: an attacker who can inject "\nBcc: ..."
	 * turns your site into a spam relay. wp_mail does guard against this, but
	 * stripping them ourselves means we never depend on that behaviour.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Plain text body.
	 * @return bool
	 */
	private static function send( $to, $subject, $body ) {
		$to      = sanitize_email( $to );
		$subject = str_replace( array( "\r", "\n" ), '', $subject );

		if ( ! is_email( $to ) ) {
			return false;
		}

		return wp_mail(
			$to,
			$subject,
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}
}
