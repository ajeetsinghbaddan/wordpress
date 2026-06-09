<?php
/**
 * Student records: an admin list page plus CSV export.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Results {

	/**
	 * The capability required to view or export records.
	 *
	 * @return string
	 */
	private static function cap() {
		// Anyone who can edit quizzes can see who took them. Filterable for stricter sites.
		return apply_filters( 'quiz_certify_results_capability', 'edit_posts' );
	}

	/**
	 * Add a "Results" submenu under the Quizzes menu.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=qc_quiz',          // Parent: the Quizzes menu.
			__( 'Quiz Results', 'quiz-certify' ),  // Page title.
			__( 'Results', 'quiz-certify' ),       // Menu label.
			self::cap(),                           // Capability gate.
			'qc-results',                          // Menu slug.
			array( __CLASS__, 'render_page' )      // Callback.
		);
	}

	/**
	 * Render the records table.
	 */
	public static function render_page() {
		// Gate the whole page on capability — never rely on the menu being hidden.
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'quiz-certify' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'quiz_certify_results';

		// Read filters/paging from the URL, casting to safe integers.
		$quiz_filter = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0;
		$per_page    = 25;
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset      = ( $paged - 1 ) * $per_page;

		// All queries below either use a safe constant table name or bind user values
		// with $wpdb->prepare, so no untrusted value is ever concatenated into SQL.
		if ( $quiz_filter ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE quiz_id = %d", $quiz_filter )
			);
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE quiz_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$quiz_filter,
					$per_page,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		}

		$total_pages = (int) ceil( $total / $per_page );
		$quizzes     = Quiz_Certify_Shortcode::get_quiz_options();

		// The export link carries a nonce so the export endpoint can confirm intent.
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'qc_export_results',
					'quiz_id' => $quiz_filter,
				),
				admin_url( 'admin-post.php' )
			),
			'qc_export_results'
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Quiz Results', 'quiz-certify' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Export CSV', 'quiz-certify' ); ?>
			</a>
			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="post_type" value="qc_quiz" />
				<input type="hidden" name="page" value="qc-results" />
				<p>
					<label for="qc-filter-quiz"><?php esc_html_e( 'Filter by quiz:', 'quiz-certify' ); ?></label>
					<select name="quiz_id" id="qc-filter-quiz">
						<option value="0"><?php esc_html_e( 'All quizzes', 'quiz-certify' ); ?></option>
						<?php foreach ( $quizzes as $q ) : ?>
							<option value="<?php echo esc_attr( $q['value'] ); ?>" <?php selected( (string) $quiz_filter, $q['value'] ); ?>>
								<?php echo esc_html( $q['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'quiz-certify' ); ?></button>
				</p>
			</form>

			<p class="description">
				<?php
				printf(
					/* translators: %d: total number of records */
					esc_html( _n( '%d record', '%d records', $total, 'quiz-certify' ) ),
					(int) $total
				);
				?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'quiz-certify' ); ?></th>
						<th><?php esc_html_e( 'Name', 'quiz-certify' ); ?></th>
						<th><?php esc_html_e( 'Email', 'quiz-certify' ); ?></th>
						<th><?php esc_html_e( 'Quiz', 'quiz-certify' ); ?></th>
						<th><?php esc_html_e( 'Score', 'quiz-certify' ); ?></th>
						<th><?php esc_html_e( 'Result', 'quiz-certify' ); ?></th>
						<th><?php esc_html_e( 'Certificate', 'quiz-certify' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No records yet.', 'quiz-certify' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( $row->user_name ); ?></td>
								<td><?php echo esc_html( $row->user_email ?? '' ); ?></td>
								<td><?php echo esc_html( get_the_title( (int) $row->quiz_id ) ); ?></td>
								<td><?php echo esc_html( $row->score . ' / ' . $row->total . ' (' . $row->percentage . '%)' ); ?></td>
								<td>
									<?php if ( (int) $row->passed ) : ?>
										<span style="color:#2f7d52;font-weight:600;"><?php esc_html_e( 'Passed', 'quiz-certify' ); ?></span>
									<?php else : ?>
										<span style="color:#b4452f;font-weight:600;"><?php esc_html_e( 'Failed', 'quiz-certify' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									$token = isset( $row->cert_token ) ? $row->cert_token : '';
									if ( (int) $row->passed && '' !== $token ) :
										// The certificate page opens print-ready; "Save as PDF" downloads it.
										$cert_url = add_query_arg( 'qc_cert', $token, home_url( '/' ) );
										?>
										<a href="<?php echo esc_url( $cert_url ); ?>" target="_blank" rel="noopener" class="button button-small">
											<?php esc_html_e( 'View / Download', 'quiz-certify' ); ?>
										</a>
									<?php else : ?>
										<span style="color:#8a8f90;">&mdash;</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					// paginate_links builds the numbered pager and keeps our filter in the URL.
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Stream all matching records as a CSV download.
	 *
	 * Hooked to admin_post_qc_export_results. We verify capability and the nonce before
	 * sending a single byte, then output CSV directly and exit.
	 */
	public static function export_csv() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to export records.', 'quiz-certify' ) );
		}

		// check_admin_referer dies if the nonce is missing or invalid.
		check_admin_referer( 'qc_export_results' );

		global $wpdb;
		$table       = $wpdb->prefix . 'quiz_certify_results';
		$quiz_filter = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0;

		if ( $quiz_filter ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE quiz_id = %d ORDER BY created_at DESC", $quiz_filter )
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
		}

		// Tell the browser this is a file download.
		$filename = 'quiz-results-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		// Header row.
		fputcsv(
			$out,
			array( 'Date', 'Name', 'Email', 'Quiz', 'Score', 'Total', 'Percentage', 'Result', 'Certificate URL' )
		);

		foreach ( (array) $rows as $row ) {
			$token    = isset( $row->cert_token ) ? $row->cert_token : '';
			$cert_url = ( (int) $row->passed && '' !== $token )
				? add_query_arg( 'qc_cert', $token, home_url( '/' ) )
				: '';
			fputcsv(
				$out,
				array(
					$row->created_at,
					$row->user_name,
					$row->user_email ?? '',
					get_the_title( (int) $row->quiz_id ),
					$row->score,
					$row->total,
					$row->percentage,
					( (int) $row->passed ) ? 'Passed' : 'Failed',
					$cert_url,
				)
			);
		}

		fclose( $out );
		exit;
	}
}
