<?php
/**
 * Admin meta boxes: the question editor and quiz settings.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Meta_Boxes {

	/**
	 * Register the meta boxes that appear on the quiz edit screen.
	 */
	public static function add() {
		add_meta_box(
			'qc_questions',
			__( 'Questions', 'quiz-certify' ),
			array( __CLASS__, 'render_questions' ),
			'qc_quiz',
			'normal',
			'high'
		);

		add_meta_box(
			'qc_settings',
			__( 'Quiz Settings', 'quiz-certify' ),
			array( __CLASS__, 'render_settings' ),
			'qc_quiz',
			'side',
			'default'
		);
	}

	/**
	 * Render the repeatable question editor.
	 *
	 * Each question has: text, a type (single or multiple correct answers), a set of
	 * options, and which option indexes are correct. We read the saved array and print
	 * one block per question; JavaScript (quiz-admin.js) clones a hidden template to add
	 * more. Every value printed into an attribute is escaped to prevent stored XSS.
	 *
	 * @param WP_Post $post The quiz being edited.
	 */
	public static function render_questions( $post ) {
		// A nonce ties this form to this user + action. On save we verify it so a forged
		// request from another site cannot silently write questions (CSRF protection).
		wp_nonce_field( 'qc_save_questions', 'qc_questions_nonce' );

		$questions = get_post_meta( $post->ID, '_qc_questions', true );
		if ( ! is_array( $questions ) ) {
			$questions = array();
		}
		?>
		<div id="qc-questions-wrap" class="qc-questions-wrap">
			<?php
			if ( empty( $questions ) ) {
				// Start a brand-new quiz with one empty question for convenience.
				$questions = array( self::empty_question() );
			}
			foreach ( $questions as $index => $q ) {
				self::render_single_question( $index, $q );
			}
			?>
		</div>

		<p>
			<button type="button" class="button button-secondary" id="qc-add-question">
				<?php esc_html_e( '+ Add Question', 'quiz-certify' ); ?>
			</button>
		</p>

		<?php // A hidden template the JS clones. __INDEX__ is swapped for a real number on add. ?>
		<script type="text/template" id="qc-question-template">
			<?php self::render_single_question( '__INDEX__', self::empty_question() ); ?>
		</script>
		<?php
	}

	/**
	 * The shape of one empty question.
	 *
	 * @return array
	 */
	private static function empty_question() {
		return array(
			'text'    => '',
			'type'    => 'single',
			'options' => array( '', '', '', '' ),
			'correct' => array(),
		);
	}

	/**
	 * Render a single question block.
	 *
	 * @param int|string $index Numeric index, or __INDEX__ placeholder for the template.
	 * @param array      $q     The question data.
	 */
	private static function render_single_question( $index, $q ) {
		$text    = isset( $q['text'] ) ? $q['text'] : '';
		$type    = isset( $q['type'] ) && 'multiple' === $q['type'] ? 'multiple' : 'single';
		$options = isset( $q['options'] ) && is_array( $q['options'] ) ? $q['options'] : array( '', '', '', '' );
		$correct = isset( $q['correct'] ) && is_array( $q['correct'] ) ? array_map( 'intval', $q['correct'] ) : array();
		?>
		<div class="qc-question" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="qc-question-head">
				<strong class="qc-question-label"><?php esc_html_e( 'Question', 'quiz-certify' ); ?></strong>
				<button type="button" class="button-link qc-remove-question" aria-label="<?php esc_attr_e( 'Remove question', 'quiz-certify' ); ?>">&times;</button>
			</div>

			<p>
				<textarea
					name="qc_questions[<?php echo esc_attr( $index ); ?>][text]"
					class="widefat"
					rows="2"
					placeholder="<?php esc_attr_e( 'Enter the question…', 'quiz-certify' ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
			</p>

			<p>
				<label>
					<?php esc_html_e( 'Answer type:', 'quiz-certify' ); ?>
					<select name="qc_questions[<?php echo esc_attr( $index ); ?>][type]" class="qc-answer-type">
						<option value="single" <?php selected( $type, 'single' ); ?>><?php esc_html_e( 'One correct answer', 'quiz-certify' ); ?></option>
						<option value="multiple" <?php selected( $type, 'multiple' ); ?>><?php esc_html_e( 'Multiple correct answers', 'quiz-certify' ); ?></option>
					</select>
				</label>
			</p>

			<div class="qc-options">
				<?php
				foreach ( $options as $opt_index => $opt_value ) {
					$is_correct = in_array( (int) $opt_index, $correct, true );
					?>
					<div class="qc-option">
						<input
							type="checkbox"
							class="qc-correct-toggle"
							name="qc_questions[<?php echo esc_attr( $index ); ?>][correct][]"
							value="<?php echo esc_attr( $opt_index ); ?>"
							<?php checked( $is_correct ); ?> />
						<input
							type="text"
							class="regular-text"
							name="qc_questions[<?php echo esc_attr( $index ); ?>][options][]"
							value="<?php echo esc_attr( $opt_value ); ?>"
							placeholder="<?php esc_attr_e( 'Answer option', 'quiz-certify' ); ?>" />
					</div>
					<?php
				}
				?>
			</div>
			<p class="description"><?php esc_html_e( 'Tick the checkbox next to each correct option.', 'quiz-certify' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the settings side box: passing score and certificate options.
	 *
	 * @param WP_Post $post The quiz being edited.
	 */
	public static function render_settings( $post ) {
		// We reuse the same nonce field name in the save handler, so a second nonce
		// here is not strictly required; but a dedicated one keeps the boxes independent.
		wp_nonce_field( 'qc_save_settings', 'qc_settings_nonce' );

		$pass     = get_post_meta( $post->ID, '_qc_pass_percentage', true );
		$pass     = '' === $pass ? 70 : (int) $pass;
		$cert_on  = (int) get_post_meta( $post->ID, '_qc_certificate_enabled', true );
		$cert_sub = get_post_meta( $post->ID, '_qc_certificate_subtitle', true );
		?>
		<p>
			<label for="qc_pass_percentage"><strong><?php esc_html_e( 'Passing score (%)', 'quiz-certify' ); ?></strong></label><br>
			<input type="number" id="qc_pass_percentage" name="qc_pass_percentage" min="0" max="100" value="<?php echo esc_attr( $pass ); ?>" class="small-text" />
		</p>

		<p>
			<label>
				<input type="checkbox" name="qc_certificate_enabled" value="1" <?php checked( $cert_on, 1 ); ?> />
				<?php esc_html_e( 'Offer a certificate on pass', 'quiz-certify' ); ?>
			</label>
		</p>

		<p>
			<label for="qc_certificate_subtitle"><strong><?php esc_html_e( 'Certificate subtitle', 'quiz-certify' ); ?></strong></label><br>
			<input type="text" id="qc_certificate_subtitle" name="qc_certificate_subtitle" value="<?php echo esc_attr( $cert_sub ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Completed the JavaScript Basics course', 'quiz-certify' ); ?>" />
		</p>

		<p>
			<label for="qc_shortcode"><strong><?php esc_html_e( 'Shortcode', 'quiz-certify' ); ?></strong></label><br>
			<input type="text" id="qc_shortcode" readonly class="widefat" value="[quiz_certify id=&quot;<?php echo esc_attr( $post->ID ); ?>&quot;]" onclick="this.select();" />
			<span class="description"><?php esc_html_e( 'Paste this into any page or post.', 'quiz-certify' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Save handler for both meta boxes.
	 *
	 * This is the security-critical method. The order of checks matters:
	 *   1. Skip autosaves (WordPress fires save_post during autosave with no form data).
	 *   2. Verify the nonce — proves the request came from our own edit screen.
	 *   3. Verify capability — proves THIS user is allowed to edit THIS quiz.
	 *   4. Only then read input, and sanitize every field before storing it.
	 *
	 * @param int $post_id The quiz post ID.
	 */
	public static function save( $post_id ) {
		// 1. Ignore autosaves and bulk-edit revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// 2. Nonce check. wp_verify_nonce returns false if missing/expired/forged.
		if ( ! isset( $_POST['qc_questions_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['qc_questions_nonce'] ), 'qc_save_questions' ) ) {
			return;
		}

		// 3. Capability check, scoped to this exact post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// --- Save questions ---
		$clean_questions = array();

		if ( isset( $_POST['qc_questions'] ) && is_array( $_POST['qc_questions'] ) ) {
			// Note: we do NOT unslash-then-trust. We rebuild a clean array field by field.
			$raw_questions = wp_unslash( $_POST['qc_questions'] );

			foreach ( $raw_questions as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}

				$text = isset( $raw['text'] ) ? sanitize_textarea_field( $raw['text'] ) : '';

				// Skip fully empty rows so deleted questions do not linger.
				if ( '' === trim( $text ) ) {
					continue;
				}

				$type = isset( $raw['type'] ) && 'multiple' === $raw['type'] ? 'multiple' : 'single';

				$options = array();
				if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
					foreach ( $raw['options'] as $opt ) {
						$options[] = sanitize_text_field( $opt );
					}
				}

				// Correct answers arrive as option indexes. Cast to int and keep only
				// those that point at an option that actually exists.
				$correct = array();
				if ( isset( $raw['correct'] ) && is_array( $raw['correct'] ) ) {
					foreach ( $raw['correct'] as $c ) {
						$c = (int) $c;
						if ( isset( $options[ $c ] ) ) {
							$correct[] = $c;
						}
					}
				}

				$clean_questions[] = array(
					'text'    => $text,
					'type'    => $type,
					'options' => array_values( $options ),
					'correct' => array_values( array_unique( $correct ) ),
				);
			}
		}

		// update_post_meta serializes the array safely for storage.
		update_post_meta( $post_id, '_qc_questions', $clean_questions );

		// --- Save settings ---
		$pass = isset( $_POST['qc_pass_percentage'] ) ? (int) $_POST['qc_pass_percentage'] : 70;
		$pass = max( 0, min( 100, $pass ) ); // Clamp to a valid percentage.
		update_post_meta( $post_id, '_qc_pass_percentage', $pass );

		$cert_on = isset( $_POST['qc_certificate_enabled'] ) ? 1 : 0;
		update_post_meta( $post_id, '_qc_certificate_enabled', $cert_on );

		$cert_sub = isset( $_POST['qc_certificate_subtitle'] )
			? sanitize_text_field( wp_unslash( $_POST['qc_certificate_subtitle'] ) )
			: '';
		update_post_meta( $post_id, '_qc_certificate_subtitle', $cert_sub );
	}

	/**
	 * Load the admin CSS/JS only on the quiz edit screen.
	 *
	 * @param string $hook The current admin page.
	 */
	public static function enqueue_admin( $hook ) {
		// Limit loading to post.php / post-new.php, and only for our post type, so we
		// do not slow down the rest of wp-admin.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'qc_quiz' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'quiz-certify-admin',
			QUIZ_CERTIFY_URL . 'assets/css/quiz-admin.css',
			array(),
			QUIZ_CERTIFY_VERSION
		);

		wp_enqueue_script(
			'quiz-certify-admin',
			QUIZ_CERTIFY_URL . 'assets/js/quiz-admin.js',
			array(),
			QUIZ_CERTIFY_VERSION,
			true
		);
	}
}
