<?php
/**
 * The submissions table.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/*
 * WP_List_Table is not loaded on every admin screen, so we pull it in
 * ourselves. This runs at the moment the autoloader reads this file, which is
 * the first time GPS_List_Table is referenced -- i.e. only on our screen.
 */
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders submissions using the same table component core uses for Posts.
 *
 * WHY EXTEND WP_List_Table RATHER THAN WRITING A <table>?
 * You inherit pagination, sortable column headers, the search box, bulk-action
 * selects with their nonce, row hover actions, screen options, and -- crucially
 * -- the exact CSS and keyboard/screen-reader behaviour of the rest of wp-admin.
 * A hand-rolled table always ends up looking and behaving like a foreign object.
 */
class GPS_List_Table extends WP_List_Table {

	/**
	 * Set up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'guest_submission',
				'plural'   => 'guest_submissions', // Used for the bulk nonce name.
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns, keyed by internal name.
	 *
	 * The 'cb' key is special: core renders it as the select-all checkbox
	 * column and it must be first for bulk actions to work.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'title'     => __( 'Title', 'guest-post-submissions' ),
			'author'    => __( 'Submitted by', 'guest-post-submissions' ),
			'category'  => __( 'Category', 'guest-post-submissions' ),
			'words'     => __( 'Words', 'guest-post-submissions' ),
			'date'      => __( 'Received', 'guest-post-submissions' ),
		);
	}

	/**
	 * Which columns can be clicked to sort.
	 *
	 * Only expose columns the database can sort efficiently. 'words' is
	 * computed in PHP, so it is deliberately NOT sortable -- making it
	 * sortable would force loading every row to order them.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
			'date'  => array( 'date', true ), // true = already sorted desc.
		);
	}

	/**
	 * The status filter links above the table.
	 *
	 * @return array
	 */
	protected function get_views() {
		$current = $this->current_status();
		$base    = admin_url( 'edit.php?page=' . GPS_Admin::PAGE_SLUG );

		$statuses = array(
			'pending'                    => __( 'Awaiting review', 'guest-post-submissions' ),
			'publish'                    => __( 'Published', 'guest-post-submissions' ),
			GPS_Plugin::STATUS_REJECTED  => __( 'Rejected', 'guest-post-submissions' ),
		);

		$views = array();

		foreach ( $statuses as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url( add_query_arg( 'post_status', $status, $base ) ),
				$current === $status ? 'current' : '',
				esc_html( $label )
			);
		}

		return $views;
	}

	/**
	 * Bulk actions offered in the dropdown.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array();

		if ( 'publish' !== $this->current_status() ) {
			$actions['bulk-approve'] = __( 'Publish', 'guest-post-submissions' );
		}

		if ( GPS_Plugin::STATUS_REJECTED !== $this->current_status() ) {
			$actions['bulk-reject'] = __( 'Reject', 'guest-post-submissions' );
		}

		if ( current_user_can( 'delete_posts' ) ) {
			$actions['bulk-delete'] = __( 'Move to trash', 'guest-post-submissions' );
		}

		return $actions;
	}

	/**
	 * Which status is being viewed.
	 *
	 * Validated against an allowlist -- this value goes straight into a
	 * WP_Query argument, and an unexpected status would either error or, worse,
	 * expose posts we did not intend to show.
	 *
	 * @return string
	 */
	private function current_status() {
		$allowed = array( 'pending', 'publish', GPS_Plugin::STATUS_REJECTED );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on a capability-gated screen.
		$status = isset( $_REQUEST['post_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_status'] ) ) : 'pending';

		return in_array( $status, $allowed, true ) ? $status : 'pending';
	}

	/**
	 * Fetch the rows.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'gps_submissions_per_page', 20 );
		$paged    = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable

		// Allowlist both, because they are interpolated into SQL by WP_Query.
		$orderby = in_array( $orderby, array( 'date', 'title' ), true ) ? $orderby : 'date';
		$order   = in_array( strtolower( $order ), array( 'asc', 'desc' ), true ) ? strtoupper( $order ) : 'DESC';

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => $this->current_status(),

				/*
				 * Passing meta_key alone (no meta_value) makes WP_Query join
				 * wp_postmeta with "meta_key = '_gps_is_guest_submission'",
				 * i.e. an EXISTS check. wp_postmeta has an index on meta_key,
				 * so this stays fast as the posts table grows.
				 */
				'meta_key'       => GPS_Plugin::META_IS_GUEST, // phpcs:ignore WordPress.DB.SlowMetaQuery
				's'              => $search,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'orderby'        => $orderby,
				'order'          => $order,
				'ignore_sticky_posts' => true,

				/*
				 * We DO want the term cache (the Category column reads terms)
				 * and the meta cache (author name, email). Leaving these true
				 * means WordPress fetches all of them in 2 queries instead of
				 * 2 queries per row -- the classic N+1 problem.
				 */
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			)
		);

		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(), // Hidden columns.
			$this->get_sortable_columns(),
			'title', // Primary column, used for the mobile toggle.
		);
	}

	/**
	 * Message when there is nothing to moderate.
	 */
	public function no_items() {
		esc_html_e( 'Nothing here yet. New guest posts will appear in this list.', 'guest-post-submissions' );
	}

	/**
	 * The row checkbox.
	 *
	 * @param WP_Post $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="post[]" value="%d" />', (int) $item->ID );
	}

	/**
	 * Title column with the hover row actions.
	 *
	 * Every action URL is wrapped in wp_nonce_url(), which appends a nonce
	 * bound to that exact action AND that exact post ID. That specificity is
	 * what stops a valid "approve post 41" link being replayed as
	 * "approve post 42".
	 *
	 * @param WP_Post $item Row.
	 * @return string
	 */
	protected function column_title( $item ) {
		$base = admin_url( 'edit.php?page=' . GPS_Admin::PAGE_SLUG );

		$actions = array();

		if ( 'publish' !== $item->post_status ) {
			$actions['approve'] = sprintf(
				'<a href="%s" class="gps-action-approve">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'gps_action' => 'approve',
								'submission' => $item->ID,
							),
							$base
						),
						'gps_approve_' . $item->ID
					)
				),
				esc_html__( 'Publish', 'guest-post-submissions' )
			);
		}

		if ( GPS_Plugin::STATUS_REJECTED !== $item->post_status ) {
			$actions['reject'] = sprintf(
				'<a href="%s" class="gps-action-reject">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'gps_view'   => 'reject',
								'submission' => $item->ID,
							),
							$base
						),
						'gps_reject_' . $item->ID
					)
				),
				esc_html__( 'Reject', 'guest-post-submissions' )
			);
		}

		if ( current_user_can( 'edit_post', $item->ID ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_post_link( $item->ID ) ),
				esc_html__( 'Edit', 'guest-post-submissions' )
			);
		}

		// Preview works for pending posts thanks to the nonce core adds.
		$actions['view'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( get_preview_post_link( $item->ID ) ),
			esc_html__( 'Preview', 'guest-post-submissions' )
		);

		$title = get_the_title( $item->ID );
		$title = $title ? $title : __( '(no title)', 'guest-post-submissions' );

		$rejection_note = get_post_meta( $item->ID, GPS_Plugin::META_REJECT_NOTE, true );

		$output = sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
			esc_url( get_edit_post_link( $item->ID ) ),
			esc_html( $title )
		);

		if ( $rejection_note ) {
			$output .= sprintf(
				'<div class="gps-note"><em>%s</em> %s</div>',
				esc_html__( 'Note:', 'guest-post-submissions' ),
				esc_html( wp_trim_words( $rejection_note, 20 ) )
			);
		}

		$image_error = get_post_meta( $item->ID, '_gps_image_error', true );

		if ( $image_error ) {
			$output .= sprintf(
				'<div class="gps-note gps-note--warn"><em>%s</em> %s</div>',
				esc_html__( 'Image rejected:', 'guest-post-submissions' ),
				esc_html( $image_error )
			);
		}

		// row_actions() adds the markup and the screen-reader "Show more"
		// toggle used on small screens.
		return $output . $this->row_actions( $actions );
	}

	/**
	 * Submitter details.
	 *
	 * @param WP_Post $item Row.
	 * @return string
	 */
	protected function column_author( $item ) {
		$name  = get_post_meta( $item->ID, GPS_Plugin::META_AUTHOR_NAME, true );
		$email = get_post_meta( $item->ID, GPS_Plugin::META_AUTHOR_EMAIL, true );
		$url   = get_post_meta( $item->ID, GPS_Plugin::META_AUTHOR_URL, true );

		$out = '<strong>' . esc_html( $name ) . '</strong>';

		if ( $email ) {
			// antispambot() obfuscates the address in the HTML source so
			// scrapers harvesting your admin markup get nothing useful.
			$out .= '<br /><a href="mailto:' . esc_attr( antispambot( $email ) ) . '">' . esc_html( antispambot( $email ) ) . '</a>';
		}

		if ( $url ) {
			$out .= '<br /><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow ugc">' . esc_html( wp_parse_url( $url, PHP_URL_HOST ) ) . '</a>';
		}

		return $out;
	}

	/**
	 * Category column.
	 *
	 * @param WP_Post $item Row.
	 * @return string
	 */
	protected function column_category( $item ) {
		$terms = get_the_terms( $item->ID, 'category' );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return '&mdash;';
		}

		return esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * Approximate word count.
	 *
	 * @param WP_Post $item Row.
	 * @return string
	 */
	protected function column_words( $item ) {
		$words = preg_split( '/[\s\x{00A0}]+/u', trim( wp_strip_all_tags( $item->post_content ) ), -1, PREG_SPLIT_NO_EMPTY );

		return esc_html( number_format_i18n( is_array( $words ) ? count( $words ) : 0 ) );
	}

	/**
	 * Received date, shown as a relative time.
	 *
	 * @param WP_Post $item Row.
	 * @return string
	 */
	protected function column_date( $item ) {
		$timestamp = get_post_timestamp( $item );

		if ( ! $timestamp ) {
			return '&mdash;';
		}

		return sprintf(
			'<abbr title="%1$s">%2$s</abbr>',
			esc_attr( wp_date( 'Y-m-d H:i', $timestamp ) ),
			esc_html(
				sprintf(
					/* translators: %s: human readable time difference */
					__( '%s ago', 'guest-post-submissions' ),
					human_time_diff( $timestamp, time() )
				)
			)
		);
	}

	/**
	 * Fallback for any column without its own method.
	 *
	 * Always escape here -- this is the catch-all, so it is where an
	 * unescaped value would silently slip through if a column is added later.
	 *
	 * @param WP_Post $item        Row.
	 * @param string  $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}
}
