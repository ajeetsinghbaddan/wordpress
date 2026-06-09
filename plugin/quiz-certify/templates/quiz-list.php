<?php
/**
 * Quiz listing template (the card grid).
 *
 * Available variables:
 *   WP_Post[] $quizzes
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="qc-quiz-list">

	<?php if ( empty( $quizzes ) ) : ?>

		<p class="qc-list-empty"><?php esc_html_e( 'No quizzes are available yet.', 'quiz-certify' ); ?></p>

	<?php else : ?>

		<div class="qc-list-grid">
			<?php foreach ( $quizzes as $quiz ) : ?>
				<?php
				// All meta below was primed in one batched query by get_posts, so these
				// reads hit the object cache rather than the database.
				$questions = get_post_meta( $quiz->ID, '_qc_questions', true );
				$count     = is_array( $questions ) ? count( $questions ) : 0;
				$pass      = (int) get_post_meta( $quiz->ID, '_qc_pass_percentage', true );
				$pass      = $pass ? $pass : 70;
				$has_cert  = (int) get_post_meta( $quiz->ID, '_qc_certificate_enabled', true );

				// The href is a real, shareable URL. JavaScript intercepts the click to
				// load the quiz in place; without JS the link still works (full reload).
				$href = esc_url( add_query_arg( 'quiz', $quiz->ID ) );
				?>
				<article class="qc-list-card">
					<h3 class="qc-list-card-title"><?php echo esc_html( get_the_title( $quiz ) ); ?></h3>

					<p class="qc-list-card-meta">
						<span class="qc-meta-pill">
							<?php
							printf(
								/* translators: %d: number of questions */
								esc_html( _n( '%d question', '%d questions', $count, 'quiz-certify' ) ),
								(int) $count
							);
							?>
						</span>
						<span class="qc-meta-pill">
							<?php
							/* translators: %d: passing percentage */
							printf( esc_html__( 'Pass %d%%', 'quiz-certify' ), (int) $pass );
							?>
						</span>
						<?php if ( $has_cert ) : ?>
							<span class="qc-meta-pill qc-meta-cert"><?php esc_html_e( 'Certificate', 'quiz-certify' ); ?></span>
						<?php endif; ?>
					</p>

					<a class="qc-list-start wp-element-button button" href="<?php echo $href; ?>" data-quiz-id="<?php echo esc_attr( $quiz->ID ); ?>">
						<?php esc_html_e( 'Start quiz', 'quiz-certify' ); ?>
					</a>
				</article>
			<?php endforeach; ?>
		</div>

		<?php // The chosen quiz is injected here by JavaScript; empty until then. ?>
		<div class="qc-list-quiz" hidden aria-live="polite"></div>

	<?php endif; ?>
</div>
