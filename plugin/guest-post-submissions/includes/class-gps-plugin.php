<?php
/**
 * Plugin orchestrator.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point that wires every subsystem together.
 *
 * This class is deliberately thin. Its only jobs are:
 *   1. Decide WHICH subsystems load in WHICH request context.
 *   2. Own the handful of hooks that don't belong to any one subsystem.
 *
 * Keeping orchestration separate from behaviour means each other class can be
 * reasoned about (and unit tested) on its own.
 */
final class GPS_Plugin {

	/**
	 * The single shared instance.
	 *
	 * @var GPS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Meta key that marks a post as having come through the guest form.
	 *
	 * Every guest submission carries this. It is what lets the moderation
	 * screen show ONLY guest posts instead of every pending post on the site.
	 */
	const META_IS_GUEST = '_gps_is_guest_submission';

	const META_AUTHOR_NAME  = '_gps_author_name';
	const META_AUTHOR_EMAIL = '_gps_author_email';
	const META_AUTHOR_URL   = '_gps_author_url';
	const META_AUTHOR_BIO   = '_gps_author_bio';
	const META_SUBMIT_IP    = '_gps_submitter_ip_hash';
	const META_REJECT_NOTE  = '_gps_rejection_reason';

	/**
	 * Custom post status applied to rejected submissions.
	 */
	const STATUS_REJECTED = 'gps_rejected';

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * The singleton pattern here prevents hooks being registered twice, which
	 * would send duplicate emails and double-process submissions.
	 *
	 * @return GPS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor -- forces use of instance().
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Prevent cloning and unserialising the singleton.
	 */
	private function __clone() {}

	/**
	 * Wire everything up.
	 */
	private function register_hooks() {
		add_action( 'init', array( 'GPS_Post_Status', 'register' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Always needed: the shortcode may appear on any front-end page, and
		// the form handler must exist for the POST request to be routed.
		GPS_Form::init();
		GPS_Submission_Handler::init();
		GPS_Notifications::init();

		/*
		 * Conditional loading. is_admin() is true for wp-admin requests AND for
		 * admin-ajax.php. That is fine here: the moderation UI is admin-only,
		 * so there is no reason to parse those classes during a front-end page
		 * view. On a busy blog this is the difference between loading 6 files
		 * per request and loading 10.
		 */
		if ( is_admin() ) {
			GPS_Admin::init();
			GPS_Settings::init();
		}

		// Display the guest's name instead of the placeholder account's name.
		add_filter( 'the_author', array( $this, 'filter_author_display_name' ) );
		add_filter( 'get_the_author_display_name', array( $this, 'filter_author_display_name' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'guest-post-submissions',
			false,
			dirname( plugin_basename( GPS_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Show the guest's real name on the front end.
	 *
	 * Published guest posts are owned by a real WordPress user account (see the
	 * attribution setting) because a post with post_author = 0 breaks a lot of
	 * themes -- get_userdata( 0 ) returns false and any theme calling
	 * get_the_author_meta() on it will output nothing or throw a notice.
	 *
	 * So: the post is OWNED by a real account for stability, but DISPLAYS the
	 * guest's name. This filter performs that swap.
	 *
	 * get_post_meta() is cached in object cache after the first call within a
	 * request, so this is cheap even inside a loop of 20 posts.
	 *
	 * @param string $display_name The author name WordPress was about to print.
	 * @return string
	 */
	public function filter_author_display_name( $display_name ) {
		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return $display_name;
		}

		$guest_name = get_post_meta( $post_id, self::META_AUTHOR_NAME, true );

		return $guest_name ? $guest_name : $display_name;
	}

	/**
	 * Runs once on activation.
	 *
	 * Two jobs:
	 *  1. Grant the moderation capability to roles that should have it.
	 *  2. Seed default settings so the plugin works before anyone visits
	 *     the settings screen. A plugin that fatals or misbehaves until
	 *     configured is a bad plugin.
	 */
	public static function on_activate() {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( GPS_MODERATE_CAP );
			}
		}

		GPS_Settings::seed_defaults();

		/*
		 * The custom post status must exist before we can query for it. It is
		 * registered on 'init', which has NOT run yet during activation on
		 * some flows, so we register it here too. Registering twice is
		 * harmless -- it just overwrites the same array key.
		 */
		GPS_Post_Status::register();
	}

	/**
	 * Runs once on deactivation.
	 *
	 * We deliberately do NOT delete submissions or settings here. Deactivation
	 * is often temporary (debugging a conflict); destroying user data on it
	 * would be hostile. Destructive cleanup belongs in uninstall.php, which
	 * only runs on explicit deletion.
	 */
	public static function on_deactivate() {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( GPS_MODERATE_CAP );
			}
		}

		delete_transient( 'gps_pending_count' );
	}
}
