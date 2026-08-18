<?php
/**
 * Front-end submission form.
 *
 * Override this by copying it to:
 *   wp-content/themes/your-theme/guest-post-submissions/form.php
 *
 * Available variables:
 *   @var array $atts      Shortcode attributes.
 *   @var array $errors    Error message strings.
 *   @var array $old       Previously submitted values.
 *   @var bool  $submitted Whether we just came back from a successful send.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

$gps_timestamp   = GPS_Form::timestamp_pair();
$gps_allowed_cat = array_map( 'intval', (array) GPS_Settings::get( 'allowed_categories' ) );
$gps_consent     = GPS_Settings::get( 'consent_text' );

if ( '' === $gps_consent ) {
	$gps_consent = __( 'I confirm this is my own original work and I grant permission to publish it.', 'guest-post-submissions' );
}
?>

<div class="gps-form-wrap" id="gps-form">

	<?php if ( $submitted ) : ?>

		<?php
		/*
		 * role="status" makes screen readers announce this without stealing
		 * focus. A success message that only exists visually is invisible to
		 * a blind user who just pressed Submit.
		 */
		?>
		<div class="gps-alert gps-alert--success" role="status">
			<h2 class="gps-alert__title"><?php esc_html_e( 'Thanks — your post is with our editors', 'guest-post-submissions' ); ?></h2>
			<p><?php esc_html_e( 'We read every submission. You will hear from us by email once someone has looked at it.', 'guest-post-submissions' ); ?></p>
		</div>

	<?php else : ?>

		<?php if ( $atts['title'] ) : ?>
			<h2 class="gps-form__heading"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( $atts['intro'] ) : ?>
			<p class="gps-form__intro"><?php echo esc_html( $atts['intro'] ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $errors ) ) : ?>
			<?php
			/*
			 * role="alert" is deliberately stronger than role="status": errors
			 * SHOULD interrupt, because the user needs to act.
			 */
			?>
			<div class="gps-alert gps-alert--error" role="alert" tabindex="-1">
				<h2 class="gps-alert__title"><?php esc_html_e( 'Fix these before sending', 'guest-post-submissions' ); ?></h2>
				<ul class="gps-alert__list">
					<?php foreach ( $errors as $gps_error ) : ?>
						<li><?php echo esc_html( $gps_error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php
		/*
		 * The form posts to admin-post.php, not to itself. enctype is required
		 * for the file input -- without it $_FILES arrives empty and the
		 * failure is silent, which is a genuinely annoying bug to chase.
		 */
		?>
		<form
			class="gps-form"
			method="post"
			enctype="multipart/form-data"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			novalidate
		>
			<?php
			// The action name that routes to our handler.
			?>
			<input type="hidden" name="action" value="gps_submit_post" />

			<?php
			// Where to return the visitor. Validated server-side against the
			// site's own host, so editing it cannot cause an open redirect.
			?>
			<input type="hidden" name="gps_redirect" value="<?php echo esc_url( get_permalink() ); ?>" />

			<?php
			// Signed render time for the bot time-trap.
			?>
			<input type="hidden" name="gps_ts" value="<?php echo esc_attr( $gps_timestamp['time'] ); ?>" />
			<input type="hidden" name="gps_ts_hash" value="<?php echo esc_attr( $gps_timestamp['hash'] ); ?>" />

			<?php
			/*
			 * The CSRF nonce. wp_nonce_field also prints _wp_http_referer.
			 * Third argument false = do not print the referer field twice.
			 */
			wp_nonce_field( GPS_Form::NONCE_ACT, GPS_Form::NONCE_NAME );
			?>

			<?php
			/*
			 * HONEYPOT. Hidden from humans three ways: off-screen via CSS,
			 * aria-hidden so screen readers skip it, tabindex="-1" so it is
			 * unreachable by keyboard. autocomplete="off" stops browsers
			 * helpfully filling it for a real user, which would get them
			 * silently blocked.
			 */
			?>
			<div class="gps-hp" aria-hidden="true">
				<label for="gps_website"><?php esc_html_e( 'Leave this field empty', 'guest-post-submissions' ); ?></label>
				<input type="text" id="gps_website" name="gps_website" value="" tabindex="-1" autocomplete="off" />
			</div>

			<fieldset class="gps-fieldset">
				<legend class="gps-legend"><?php esc_html_e( 'About you', 'guest-post-submissions' ); ?></legend>

				<p class="gps-field">
					<label class="gps-label" for="gps_author_name">
						<?php esc_html_e( 'Name', 'guest-post-submissions' ); ?>
						<span class="gps-required" aria-hidden="true">*</span>
					</label>
					<input
						class="gps-input"
						type="text"
						id="gps_author_name"
						name="gps_author_name"
						maxlength="100"
						required
						autocomplete="name"
						value="<?php echo esc_attr( GPS_Form::old( $old, 'gps_author_name' ) ); ?>"
					/>
				</p>

				<p class="gps-field">
					<label class="gps-label" for="gps_author_email">
						<?php esc_html_e( 'Email', 'guest-post-submissions' ); ?>
						<span class="gps-required" aria-hidden="true">*</span>
					</label>
					<input
						class="gps-input"
						type="email"
						id="gps_author_email"
						name="gps_author_email"
						required
						autocomplete="email"
						aria-describedby="gps_author_email_help"
						value="<?php echo esc_attr( GPS_Form::old( $old, 'gps_author_email' ) ); ?>"
					/>
					<span class="gps-help" id="gps_author_email_help">
						<?php esc_html_e( 'Only used to tell you what we decided. Never published.', 'guest-post-submissions' ); ?>
					</span>
				</p>

				<p class="gps-field">
					<label class="gps-label" for="gps_author_url"><?php esc_html_e( 'Website', 'guest-post-submissions' ); ?></label>
					<input
						class="gps-input"
						type="url"
						id="gps_author_url"
						name="gps_author_url"
						placeholder="https://"
						autocomplete="url"
						value="<?php echo esc_attr( GPS_Form::old( $old, 'gps_author_url' ) ); ?>"
					/>
				</p>

				<p class="gps-field">
					<label class="gps-label" for="gps_author_bio"><?php esc_html_e( 'Short bio', 'guest-post-submissions' ); ?></label>
					<textarea
						class="gps-input gps-textarea gps-textarea--short"
						id="gps_author_bio"
						name="gps_author_bio"
						rows="3"
						maxlength="600"
					><?php echo esc_textarea( GPS_Form::old( $old, 'gps_author_bio' ) ); ?></textarea>
				</p>
			</fieldset>

			<fieldset class="gps-fieldset">
				<legend class="gps-legend"><?php esc_html_e( 'Your post', 'guest-post-submissions' ); ?></legend>

				<p class="gps-field">
					<label class="gps-label" for="gps_title">
						<?php esc_html_e( 'Title', 'guest-post-submissions' ); ?>
						<span class="gps-required" aria-hidden="true">*</span>
					</label>
					<input
						class="gps-input"
						type="text"
						id="gps_title"
						name="gps_title"
						maxlength="180"
						required
						value="<?php echo esc_attr( GPS_Form::old( $old, 'gps_title' ) ); ?>"
					/>
				</p>

				<p class="gps-field">
					<label class="gps-label" for="gps_content">
						<?php esc_html_e( 'Body', 'guest-post-submissions' ); ?>
						<span class="gps-required" aria-hidden="true">*</span>
					</label>
					<textarea
						class="gps-input gps-textarea"
						id="gps_content"
						name="gps_content"
						rows="18"
						required
						aria-describedby="gps_content_help"
					><?php
					/*
					 * esc_textarea(), not esc_html(). It encodes the value for
					 * the *content* of a textarea specifically. Using
					 * esc_attr() here would be wrong; using nothing would let a
					 * crafted draft close the textarea and inject markup.
					 */
					echo esc_textarea( GPS_Form::old( $old, 'gps_content' ) );
					?></textarea>
					<span class="gps-help" id="gps_content_help">
						<?php
						printf(
							/* translators: 1: minimum words, 2: maximum words */
							esc_html__( 'Between %1$s and %2$s words. Basic formatting is allowed: bold, italics, links, lists and headings.', 'guest-post-submissions' ),
							esc_html( number_format_i18n( (int) GPS_Settings::get( 'min_words' ) ) ),
							esc_html( number_format_i18n( (int) GPS_Settings::get( 'max_words' ) ) )
						);
						?>
						<span class="gps-counter" data-gps-counter aria-live="polite"></span>
					</span>
				</p>

				<p class="gps-field">
					<label class="gps-label" for="gps_category"><?php esc_html_e( 'Category', 'guest-post-submissions' ); ?></label>
					<?php
					/*
					 * wp_dropdown_categories escapes its own output. We restrict
					 * 'include' to the allowlist so the markup and the
					 * server-side check agree -- but the server-side check in
					 * GPS_Submission_Handler is what actually enforces it.
					 */
					wp_dropdown_categories(
						array(
							'name'             => 'gps_category',
							'id'               => 'gps_category',
							'class'            => 'gps-input gps-select',
							'hide_empty'       => false,
							'selected'         => (int) GPS_Form::old( $old, 'gps_category' ),
							'include'          => ! empty( $gps_allowed_cat ) ? $gps_allowed_cat : '',
							'show_option_none' => __( 'Choose a category', 'guest-post-submissions' ),
							'option_none_value' => 0,
						)
					);
					?>
				</p>

				<?php if ( GPS_Settings::get( 'allow_tags' ) ) : ?>
					<p class="gps-field">
						<label class="gps-label" for="gps_tags"><?php esc_html_e( 'Tags', 'guest-post-submissions' ); ?></label>
						<input
							class="gps-input"
							type="text"
							id="gps_tags"
							name="gps_tags"
							aria-describedby="gps_tags_help"
							value="<?php echo esc_attr( GPS_Form::old( $old, 'gps_tags' ) ); ?>"
						/>
						<span class="gps-help" id="gps_tags_help">
							<?php
							printf(
								/* translators: %s: maximum number of tags */
								esc_html__( 'Separate with commas. Up to %s.', 'guest-post-submissions' ),
								esc_html( number_format_i18n( (int) GPS_Settings::get( 'max_tags' ) ) )
							);
							?>
						</span>
					</p>
				<?php endif; ?>

				<?php if ( GPS_Settings::get( 'allow_image' ) ) : ?>
					<p class="gps-field">
						<label class="gps-label" for="gps_image"><?php esc_html_e( 'Featured image', 'guest-post-submissions' ); ?></label>
						<input
							class="gps-file"
							type="file"
							id="gps_image"
							name="gps_image"
							accept="image/jpeg,image/png,image/gif,image/webp"
							aria-describedby="gps_image_help"
						/>
						<span class="gps-help" id="gps_image_help">
							<?php
							printf(
								/* translators: %s: maximum file size in KB */
								esc_html__( 'JPG, PNG, GIF or WebP, up to %s KB.', 'guest-post-submissions' ),
								esc_html( number_format_i18n( (int) GPS_Settings::get( 'max_image_kb' ) ) )
							);
							?>
						</span>
					</p>
				<?php endif; ?>
			</fieldset>

			<?php if ( GPS_Settings::get( 'require_consent' ) ) : ?>
				<p class="gps-field gps-field--check">
					<label class="gps-check">
						<input
							type="checkbox"
							name="gps_consent"
							value="1"
							required
							<?php checked( 1, (int) GPS_Form::old( $old, 'gps_consent' ) ); ?>
						/>
						<span><?php echo esc_html( $gps_consent ); ?></span>
					</label>
				</p>
			<?php endif; ?>

			<p class="gps-submit">
				<button type="submit" class="gps-button">
					<?php esc_html_e( 'Send for review', 'guest-post-submissions' ); ?>
				</button>
			</p>
		</form>

	<?php endif; ?>
</div>
