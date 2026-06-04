<?php
/**
 * Adds and saves the custom meta box fields for each testimonial.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Meta_Boxes
 *
 * Handles the "Testimonial Details" meta box: the client name, role/company,
 * star rating, and client photo (via the Media Library). The testimonial body
 * text itself uses the built-in post editor (the CPT 'editor' support).
 *
 * The save routine is where most input-side security lives:
 *   1. Nonce verification (proves the request came from our form).
 *   2. Capability check (proves the user is allowed to edit THIS post).
 *   3. Sanitisation of every field before it touches the database.
 */
class ASB_TS_Meta_Boxes {

	/**
	 * Meta keys. We prefix every key with "_asb_ts_". The leading underscore
	 * marks them "protected" so they don't show in the default Custom Fields UI,
	 * and the prefix prevents clashes with other plugins' meta.
	 */
	const META_NAME    = '_asb_ts_client_name';
	const META_ROLE    = '_asb_ts_client_role';
	const META_RATING  = '_asb_ts_rating';
	const META_PHOTO   = '_asb_ts_photo_id';

	/**
	 * Nonce action + field name used by this meta box's form.
	 */
	const NONCE_ACTION = 'asb_ts_save_meta';
	const NONCE_FIELD  = 'asb_ts_meta_nonce';

	/**
	 * Wire up the hooks:
	 * - 'add_meta_boxes' to register the box.
	 * - 'save_post_{type}' to persist the data (the typed variant only fires for
	 *   our CPT, so we don't need to re-check the post type ourselves).
	 * - 'admin_enqueue_scripts' to load the WP media uploader + our admin JS only
	 *   on the testimonial editor screen.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . ASB_TS_CPT::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register the meta box on the testimonial edit screen.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'asb_ts_details',                                          // Unique HTML id.
			__( 'Testimonial Details', 'asb-testimonials-showcase' ),  // Box title.
			array( $this, 'render_meta_box' ),                         // Callback that prints the fields.
			ASB_TS_CPT::POST_TYPE,                                     // Only on the testimonial CPT.
			'normal',                                                  // Context (main column).
			'high'                                                     // Priority.
		);
	}

	/**
	 * Load the Media Library scripts and our small helper script on the editor
	 * screen only. wp_enqueue_media() pulls in the JS that powers the standard
	 * "Select / Upload Media" modal so our photo button can reuse it.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only on the add/edit post screens.
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// And only for our post type.
		$screen = get_current_screen();
		if ( ! $screen || ASB_TS_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media(); // The Media Library uploader JS/CSS.

		wp_enqueue_style(
			'asb-ts-admin',
			ASB_TS_URL . 'admin/css/asb-admin.css',
			array(),
			ASB_TS_VERSION
		);

		wp_enqueue_script(
			'asb-ts-admin-media',
			ASB_TS_URL . 'admin/js/asb-admin-media.js',
			array( 'jquery' ),
			ASB_TS_VERSION,
			true // Load in the footer.
		);

		// Pass translated UI strings to JS safely (no hard-coded English in JS).
		wp_localize_script(
			'asb-ts-admin-media',
			'asbTsMedia',
			array(
				'title'  => __( 'Select or upload a client photo', 'asb-testimonials-showcase' ),
				'button' => __( 'Use this photo', 'asb-testimonials-showcase' ),
			)
		);
	}

	/**
	 * Print the meta box HTML.
	 *
	 * Note how every value we echo is run through an escaping function at the
	 * point of output (esc_attr / esc_textarea / esc_url). Even though this data
	 * was sanitised on save, we escape again on output as defence in depth.
	 *
	 * @param WP_Post $post The post being edited.
	 */
	public function render_meta_box( $post ) {
		// Output the nonce field. When the form is submitted this hidden field
		// is sent back and verified to prove the request is legitimate (CSRF).
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		// Read existing values. get_post_meta(..., true) returns a single value.
		$name     = get_post_meta( $post->ID, self::META_NAME, true );
		$role     = get_post_meta( $post->ID, self::META_ROLE, true );
		$rating   = (int) get_post_meta( $post->ID, self::META_RATING, true );
		$photo_id = absint( get_post_meta( $post->ID, self::META_PHOTO, true ) );
		$photo_src = $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
		?>
		<div class="asb-ts-fields">
			<p>
				<label for="asb_ts_client_name"><strong><?php esc_html_e( 'Client / Author name', 'asb-testimonials-showcase' ); ?></strong></label><br />
				<input type="text" id="asb_ts_client_name" name="asb_ts_client_name"
					class="widefat" value="<?php echo esc_attr( $name ); ?>" />
			</p>

			<p>
				<label for="asb_ts_client_role"><strong><?php esc_html_e( 'Role / Company', 'asb-testimonials-showcase' ); ?></strong></label><br />
				<input type="text" id="asb_ts_client_role" name="asb_ts_client_role"
					class="widefat" value="<?php echo esc_attr( $role ); ?>" />
			</p>

			<p>
				<label for="asb_ts_rating"><strong><?php esc_html_e( 'Star rating (1–5)', 'asb-testimonials-showcase' ); ?></strong></label><br />
				<select id="asb_ts_rating" name="asb_ts_rating">
					<?php
					// Build a simple 0–5 dropdown; 0 means "no rating shown".
					for ( $i = 0; $i <= 5; $i++ ) {
						printf(
							'<option value="%1$d" %2$s>%3$s</option>',
							esc_attr( $i ),
							selected( $rating, $i, false ), // selected() echoes selected="selected" when equal.
							0 === $i ? esc_html__( 'No rating', 'asb-testimonials-showcase' ) : esc_html( $i )
						);
					}
					?>
				</select>
			</p>

			<p>
				<strong><?php esc_html_e( 'Client photo', 'asb-testimonials-showcase' ); ?></strong><br />
				<span class="asb-ts-photo-preview">
					<?php if ( $photo_src ) : ?>
						<img src="<?php echo esc_url( $photo_src ); ?>" alt="" />
					<?php endif; ?>
				</span><br />
				<!-- Hidden field stores the attachment ID; JS fills it from the media modal. -->
				<input type="hidden" id="asb_ts_photo_id" name="asb_ts_photo_id" value="<?php echo esc_attr( $photo_id ); ?>" />
				<button type="button" class="button asb-ts-photo-upload"><?php esc_html_e( 'Select photo', 'asb-testimonials-showcase' ); ?></button>
				<button type="button" class="button asb-ts-photo-remove" <?php echo $photo_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'asb-testimonials-showcase' ); ?></button>
			</p>
			<p class="description">
				<?php esc_html_e( 'The testimonial quote itself goes in the main editor above.', 'asb-testimonials-showcase' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the meta box data securely.
	 *
	 * The order of guard clauses is deliberate and is the heart of write-side
	 * security. We bail out early unless ALL of these are true:
	 *   - It's not an autosave (autosaves don't carry our form data).
	 *   - The nonce is present and valid (request really came from our form).
	 *   - The current user can edit THIS specific post.
	 * Only then do we sanitise and store each field.
	 *
	 * @param int     $post_id The post being saved.
	 * @param WP_Post $post    The post object.
	 */
	public function save_meta( $post_id, $post ) {
		// 1. Skip autosaves — WordPress autosaves don't include our fields, and
		// proceeding would wipe existing values.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// 2. Verify the nonce. wp_verify_nonce returns false if the nonce field
		// is missing, forged or expired — in which case we refuse to save.
		// We unslash + sanitize the raw value before checking it.
		if (
			! isset( $_POST[ self::NONCE_FIELD ] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ),
				self::NONCE_ACTION
			)
		) {
			return;
		}

		// 3. Capability check: the user must be allowed to edit this exact post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		/*
		 * 4. Sanitise + save each field. Every value comes from $_POST, which is
		 * fundamentally untrusted, so each one is unslashed and passed through
		 * the appropriate sanitiser for its data type:
		 *   - text       -> sanitize_text_field()
		 *   - integer    -> absint() (forces a non-negative integer)
		 *   - rating     -> absint() then clamped to the 0–5 range
		 *   - attachment -> absint() then validated to be a real image attachment
		 */

		// Client name (plain text).
		$name = isset( $_POST['asb_ts_client_name'] )
			? sanitize_text_field( wp_unslash( $_POST['asb_ts_client_name'] ) )
			: '';
		update_post_meta( $post_id, self::META_NAME, $name );

		// Role / company (plain text).
		$role = isset( $_POST['asb_ts_client_role'] )
			? sanitize_text_field( wp_unslash( $_POST['asb_ts_client_role'] ) )
			: '';
		update_post_meta( $post_id, self::META_ROLE, $role );

		// Rating: cast to int, then clamp to 0–5 so out-of-range values can't be stored.
		$rating = isset( $_POST['asb_ts_rating'] ) ? absint( wp_unslash( $_POST['asb_ts_rating'] ) ) : 0;
		$rating = min( 5, max( 0, $rating ) );
		update_post_meta( $post_id, self::META_RATING, $rating );

		// Photo attachment ID: cast to int, then confirm it is genuinely an
		// image attachment in this site before trusting it. This stops an
		// attacker from pointing the field at an arbitrary attachment/post ID.
		$photo_id = isset( $_POST['asb_ts_photo_id'] ) ? absint( wp_unslash( $_POST['asb_ts_photo_id'] ) ) : 0;
		if ( $photo_id > 0 ) {
			$mime = get_post_mime_type( $photo_id );
			if ( 'attachment' !== get_post_type( $photo_id ) || strpos( (string) $mime, 'image/' ) !== 0 ) {
				$photo_id = 0; // Not a valid image attachment — discard it.
			}
		}
		update_post_meta( $post_id, self::META_PHOTO, $photo_id );
	}
}
