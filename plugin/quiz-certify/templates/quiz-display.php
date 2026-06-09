<?php
/**
 * Front-end quiz template.
 *
 * Available variables (set by the shortcode class):
 *   int   $quiz_id
 *   array $questions
 *
 * Name and email are both always collected and required. SECURITY NOTE: only question
 * text and option labels are printed — the 'correct' indexes are never output, so the
 * answer key never reaches the browser.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$quiz_title = get_the_title( $quiz_id );
?>
<div class="qc-quiz" data-quiz-id="<?php echo esc_attr( $quiz_id ); ?>">

	<form class="qc-quiz-form" novalidate>
		<header class="qc-quiz-header">
			<h2 class="qc-quiz-title"><?php echo esc_html( $quiz_title ); ?></h2>
			<p class="qc-quiz-meta">
				<?php
				printf(
					/* translators: %d: number of questions */
					esc_html( _n( '%d question', '%d questions', count( $questions ), 'quiz-certify' ) ),
					count( $questions )
				);
				?>
			</p>
		</header>

		<div class="qc-field qc-field-name">
			<label for="qc-name-<?php echo esc_attr( $quiz_id ); ?>">
				<?php esc_html_e( 'Your name', 'quiz-certify' ); ?> <span class="qc-required" aria-hidden="true">*</span>
			</label>
			<input
				type="text"
				id="qc-name-<?php echo esc_attr( $quiz_id ); ?>"
				class="qc-name-input"
				required
				autocomplete="name"
				placeholder="<?php esc_attr_e( 'Enter your full name', 'quiz-certify' ); ?>" />
		</div>

		<div class="qc-field qc-field-email">
			<label for="qc-email-<?php echo esc_attr( $quiz_id ); ?>">
				<?php esc_html_e( 'Your email', 'quiz-certify' ); ?> <span class="qc-required" aria-hidden="true">*</span>
			</label>
			<input
				type="email"
				id="qc-email-<?php echo esc_attr( $quiz_id ); ?>"
				class="qc-email-input"
				required
				autocomplete="email"
				placeholder="<?php esc_attr_e( 'you@example.com', 'quiz-certify' ); ?>" />
		</div>

		<ol class="qc-questions">
			<?php foreach ( $questions as $q_index => $question ) : ?>
				<?php
				$type    = isset( $question['type'] ) && 'multiple' === $question['type'] ? 'multiple' : 'single';
				$options = isset( $question['options'] ) && is_array( $question['options'] ) ? $question['options'] : array();
				$input   = ( 'multiple' === $type ) ? 'checkbox' : 'radio';
				$group   = 'qc_q_' . $quiz_id . '_' . $q_index;
				?>
				<li class="qc-question-item" data-question="<?php echo esc_attr( $q_index ); ?>">
					<p class="qc-question-text">
						<?php echo esc_html( $question['text'] ); ?>
						<?php if ( 'multiple' === $type ) : ?>
							<span class="qc-hint"><?php esc_html_e( '(select all that apply)', 'quiz-certify' ); ?></span>
						<?php endif; ?>
					</p>

					<ul class="qc-options-list">
						<?php foreach ( $options as $opt_index => $opt_label ) : ?>
							<?php
							if ( '' === trim( (string) $opt_label ) ) {
								continue;
							}
							$opt_id = $group . '_' . $opt_index;
							?>
							<li class="qc-option-item">
								<label for="<?php echo esc_attr( $opt_id ); ?>">
									<input
										type="<?php echo esc_attr( $input ); ?>"
										id="<?php echo esc_attr( $opt_id ); ?>"
										name="<?php echo esc_attr( $group ); ?>"
										data-question="<?php echo esc_attr( $q_index ); ?>"
										value="<?php echo esc_attr( $opt_index ); ?>" />
									<span><?php echo esc_html( $opt_label ); ?></span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
		</ol>

		<div class="qc-actions">
			<?php // wp-element-button is the class block themes use to style buttons, so this
			// inherits the theme's button look. "button" helps classic themes do the same. ?>
			<button type="submit" class="qc-submit-btn wp-element-button button">
				<?php esc_html_e( 'Submit answers', 'quiz-certify' ); ?>
			</button>
		</div>

		<p class="qc-form-error" role="alert" aria-live="polite"></p>
	</form>

	<div class="qc-result" hidden aria-live="polite"></div>
</div>
