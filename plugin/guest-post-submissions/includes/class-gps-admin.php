<?php
/**
 * Admin moderation UI.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * The moderation queue.
 */
class GPS_Admin {

	const PAGE_SLUG = 'gps-submissions';

	/**
	 * Hook suffix returned by add_submenu_page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook the admin screens.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the menu entries.
	 */
	public static function register_menu() {
		$pending = self::get_pending_count();

		/*
		 * The count bubble. Building it here rather than in the label string
		 * keeps the untranslated markup out of the .po file. number_format_i18n
		 * respects the site's locale (so 1,234 vs 1.234).
		 */
		$label = __( 'Guest Submissions', 'guest-post-submissions' );

		if ( $pending > 0 ) {
			$label .= sprintf(
				' <span class="awaiting-mod"><span class="pending-count">%s</span></span>',
				esc_html( number_format_i18n( $pending ) )
			);
		}

		self::$hook = add_submenu_page(
			'edit.php',                                    // Parent: Posts.
			__( 'Guest Submissions', 'guest-post-submissions' ), // <title>.
			$label,                                        // Menu label.
			GPS_MODERATE_CAP,                              // Required capability.
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			'edit.php',
			__( 'Guest Post Settings', 'guest-post-submissions' ),
			__( 'Guest Post Settings', 'guest-post-submissions' ),
			'manage_options', // Settings need a higher bar than moderation.
			'gps-settings',
			array( 'GPS_Settings', 'render_page' )
		);

		if ( self::$hook ) {
			/*
			 * 'load-{hook}' fires early, BEFORE any HTML has been sent. That
			 * matters: our approve/reject handlers finish with a redirect, and
			 * a redirect after output has started throws "headers already
			 * sent". Handling actions inside the render callback would be too
			 * late.
			 */
			add_action( 'load-' . self::$hook, array( __CLASS__, 'handle_actions' ) );
			add_action( 'load-' . self::$hook, array( __CLASS__, 'add_screen_options' ) );
		}
	}

	/**
	 * Per-page screen option.
	 */
	public static function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Submissions per page', 'guest-post-submissions' ),
				'default' => 20,
				'option'  => 'gps_submissions_per_page',
			)
		);
	}

	/**
	 * Load admin CSS only on our screen.
	 *
	 * $hook_suffix tells us which admin page is rendering. Comparing against
	 * our own hook means the stylesheet never loads on the dashboard, the post
	 * editor, or anyone else's plugin page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== self::$hook && 'posts_page_gps-settings' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'gps-admin',
			GPS_PLUGIN_URL . 'assets/css/gps-admin.css',
			array(),
			GPS_VERSION
		);
	}

	/**
	 * Count pending guest submissions, cached.
	 *
	 * wp_count_posts() cannot help us because it groups by status only and
	 * knows nothing about our meta key. So we run a minimal WP_Query and cache
	 * the number -- this runs on EVERY admin page load to build the menu
	 * bubble, so an uncached query here would be a real tax.
	 *
	 * The transient is deleted whenever a submission is created or moderated,
	 * so the badge is never stale in a way anyone would notice.
	 *
	 * @return int
	 */
	public static function get_pending_count() {
		$cached = get_transient( 'gps_pending_count' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'pending',
				'meta_key'               => GPS_Plugin::META_IS_GUEST, // phpcs:ignore WordPress.DB.SlowMetaQuery
				'posts_per_page'         => 1,
				'fields'                 => 'ids',   // Do not hydrate post objects.
				'update_post_meta_cache' => false,   // We only want the count...
				'update_post_term_cache' => false,   // ...so skip both cache priming queries.
				'ignore_sticky_posts'    => true,
			)
		);

		$count = (int) $query->found_posts;

		set_transient( 'gps_pending_count', $count, 10 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Process approve / reject / bulk actions.
	 *
	 * THE THREE CHECKS EVERY ADMIN ACTION NEEDS:
	 *   1. Is the user allowed to do this?          current_user_can()
	 *   2. Did the request come from our UI?        check_admin_referer()
	 *   3. Is the target valid and of the right kind? (the post type + meta check)
	 *
	 * Missing #1 gives you privilege escalation. Missing #2 gives you CSRF --
	 * an attacker emails an admin a link that silently publishes spam. Missing
	 * #3 lets a crafted post ID modify arbitrary content on the site.
	 */
	public static function handle_actions() {
		$action = self::current_action();

		if ( ! $action ) {
			return;
		}

		// ---- Check 1: capability ---------------------------------------
		if ( ! current_user_can( GPS_MODERATE_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to moderate guest submissions.', 'guest-post-submissions' ), 403 );
		}

		$post_ids = self::requested_post_ids();

		if ( empty( $post_ids ) ) {
			return;
		}

		// ---- Check 2: nonce --------------------------------------------
		/*
		 * Single-row actions carry a per-post nonce; bulk actions use the
		 * nonce WP_List_Table generates ('bulk-' . plural). check_admin_referer
		 * calls wp_die() itself on failure, so there is nothing to handle.
		 */
		if ( in_array( $action, array( 'bulk-approve', 'bulk-reject', 'bulk-delete' ), true ) ) {
			check_admin_referer( 'bulk-guest_submissions' );
		} else {
			check_admin_referer( 'gps_' . $action . '_' . $post_ids[0] );
		}

		$processed = 0;

		foreach ( $post_ids as $post_id ) {
			// ---- Check 3: is this really a guest submission? -----------
			if ( ! self::is_guest_submission( $post_id ) ) {
				continue;
			}

			switch ( $action ) {
				case 'approve':
				case 'bulk-approve':
					$processed += self::approve( $post_id ) ? 1 : 0;
					break;

				case 'reject':
				case 'bulk-reject':
					$reason = isset( $_REQUEST['gps_reason'] )
						? sanitize_textarea_field( wp_unslash( $_REQUEST['gps_reason'] ) )
						: '';
					$processed += self::reject( $post_id, $reason ) ? 1 : 0;
					break;

				case 'delete':
				case 'bulk-delete':
					// Deleting is destructive, so require the stronger cap.
					if ( current_user_can( 'delete_post', $post_id ) && wp_trash_post( $post_id ) ) {
						++$processed;
					}
					break;
			}
		}

		delete_transient( 'gps_pending_count' );

		/*
		 * Redirect after acting (Post/Redirect/Get again). This strips the
		 * action and nonce from the URL so a refresh does not re-run it, and
		 * removes the used nonce from the browser history.
		 */
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'gps_done'     => sanitize_key( str_replace( 'bulk-', '', $action ) ),
					'gps_count'    => $processed,
					'post_status'  => isset( $_REQUEST['post_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_status'] ) ) : 'pending',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Read the requested action from either the row link or the bulk selects.
	 *
	 * @return string
	 */
	private static function current_action() {
		if ( ! empty( $_REQUEST['gps_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['gps_action'] ) );

			return in_array( $action, array( 'approve', 'reject', 'delete' ), true ) ? $action : '';
		}

		// WP_List_Table posts action in 'action' and 'action2' (top and bottom
		// select boxes); '-1' means "no action selected".
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( ! empty( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] ) {
				$action = sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );

				if ( in_array( $action, array( 'bulk-approve', 'bulk-reject', 'bulk-delete' ), true ) ) {
					return $action;
				}
			}
		}

		return '';
	}

	/**
	 * Collect the post IDs being acted on.
	 *
	 * absint() on every element guarantees we hand integers to WP_Post
	 * functions -- there is no path here for a string like "1 OR 1=1" to
	 * survive.
	 *
	 * @return int[]
	 */
	private static function requested_post_ids() {
		if ( ! empty( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ) {
			return array_values( array_filter( array_map( 'absint', wp_unslash( $_REQUEST['post'] ) ) ) );
		}

		if ( ! empty( $_REQUEST['submission'] ) ) {
			$id = absint( $_REQUEST['submission'] );

			return $id ? array( $id ) : array();
		}

		return array();
	}

	/**
	 * Confirm a post ID really is a pending/rejected guest submission.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_guest_submission( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return false;
		}

		return (bool) get_post_meta( $post_id, GPS_Plugin::META_IS_GUEST, true );
	}

	/**
	 * Publish a submission.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function approve( $post_id ) {
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return false;
		}

		delete_post_meta( $post_id, GPS_Plugin::META_REJECT_NOTE );
		update_post_meta( $post_id, '_gps_moderated_by', get_current_user_id() );
		update_post_meta( $post_id, '_gps_moderated_at', time() );

		/**
		 * Fires when a submission is published.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'gps_submission_approved', $post_id );

		return true;
	}

	/**
	 * Reject a submission.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  Optional editor note.
	 * @return bool
	 */
	public static function reject( $post_id, $reason = '' ) {
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => GPS_Plugin::STATUS_REJECTED,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return false;
		}

		if ( '' !== $reason ) {
			update_post_meta( $post_id, GPS_Plugin::META_REJECT_NOTE, $reason );
		}

		update_post_meta( $post_id, '_gps_moderated_by', get_current_user_id() );
		update_post_meta( $post_id, '_gps_moderated_at', time() );

		/**
		 * Fires when a submission is rejected.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $reason  Editor note.
		 */
		do_action( 'gps_submission_rejected', $post_id, $reason );

		return true;
	}

	/**
	 * Render the moderation screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( GPS_MODERATE_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'guest-post-submissions' ), 403 );
		}

		// The "reject with a reason" sub-screen.
		if ( isset( $_GET['gps_view'] ) && 'reject' === sanitize_key( wp_unslash( $_GET['gps_view'] ) ) ) {
			self::render_reject_form();
			return;
		}

		$table = new GPS_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Guest Submissions', 'guest-post-submissions' ); ?></h1>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<form method="get">
				<?php
				// The page slug must ride along or the form loses its screen.
				printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
				$table->views();
				$table->search_box( __( 'Search submissions', 'guest-post-submissions' ), 'gps-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Screen for rejecting with a note.
	 */
	private static function render_reject_form() {
		$post_id = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;

		// Even on a read-only screen, verify the referer before trusting the ID.
		check_admin_referer( 'gps_reject_' . $post_id );

		if ( ! self::is_guest_submission( $post_id ) ) {
			wp_die( esc_html__( 'That submission does not exist.', 'guest-post-submissions' ) );
		}
		?>
		<div class="wrap gps-reject-wrap">
			<h1><?php esc_html_e( 'Reject submission', 'guest-post-submissions' ); ?></h1>

			<p class="gps-reject-title">
				<?php
				printf(
					/* translators: %s: post title */
					esc_html__( 'You are rejecting "%s".', 'guest-post-submissions' ),
					esc_html( get_the_title( $post_id ) )
				);
				?>
			</p>

			<?php
			/*
			 * NOTE THE ACTION URL. The page slug MUST be in the query string,
			 * not a hidden POST field.
			 *
			 * WordPress decides which plugin screen to render by reading
			 * $_GET['page'] in wp-admin/admin.php -- specifically $_GET, not
			 * $_REQUEST. A hidden <input name="page"> in a POST form lands in
			 * $_POST, WordPress never sees it, and the request falls through to
			 * the normal Posts list instead of reaching our handler.
			 *
			 * This is a genuinely confusing failure because the form looks
			 * correct and simply "does nothing".
			 */
			$gps_reject_action = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'edit.php' ) );
			?>
			<form method="post" action="<?php echo esc_url( $gps_reject_action ); ?>">
				<input type="hidden" name="gps_action" value="reject" />
				<input type="hidden" name="submission" value="<?php echo esc_attr( $post_id ); ?>" />
				<?php wp_nonce_field( 'gps_reject_' . $post_id ); ?>

				<p>
					<label for="gps_reason"><?php esc_html_e( 'Note for the author (optional)', 'guest-post-submissions' ); ?></label><br />
					<textarea id="gps_reason" name="gps_reason" rows="5" class="large-text"></textarea>
				</p>

				<p class="description">
					<?php esc_html_e( 'If author emails are enabled, this note is included in the message they receive.', 'guest-post-submissions' ); ?>
				</p>

				<?php submit_button( __( 'Reject submission', 'guest-post-submissions' ), 'delete' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show the "X approved" style notice after a redirect.
	 */
	private static function render_notice() {
		if ( empty( $_GET['gps_done'] ) ) {
			return;
		}

		$done  = sanitize_key( wp_unslash( $_GET['gps_done'] ) );
		$count = isset( $_GET['gps_count'] ) ? absint( $_GET['gps_count'] ) : 0;

		$messages = array(
			'approve' => _n_noop( '%s submission published.', '%s submissions published.', 'guest-post-submissions' ),
			'reject'  => _n_noop( '%s submission rejected.', '%s submissions rejected.', 'guest-post-submissions' ),
			'delete'  => _n_noop( '%s submission moved to trash.', '%s submissions moved to trash.', 'guest-post-submissions' ),
		);

		if ( ! isset( $messages[ $done ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( translate_nooped_plural( $messages[ $done ], $count, 'guest-post-submissions' ), number_format_i18n( $count ) ) )
		);
	}
}
