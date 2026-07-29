<?php
/**
 * Everything that only runs inside wp-admin.
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the enctype attribute to the post form.
 *
 * A form without enctype="multipart/form-data" silently drops file uploads,
 * so this one line is what makes the PDF field work at all.
 */
function fbs_form_enctype() {
	if ( FBS_POST_TYPE === get_post_type() ) {
		echo ' enctype="multipart/form-data"';
	}
}
add_action( 'post_edit_form_tag', 'fbs_form_enctype' );

/**
 * Registers the three edit-screen panels.
 */
function fbs_add_meta_boxes() {
	add_meta_box( 'fbs-file', __( 'PDF file', 'flipbook-studio' ), 'fbs_render_file_box', FBS_POST_TYPE, 'normal', 'high' );
	add_meta_box( 'fbs-embed', __( 'Embed this flipbook', 'flipbook-studio' ), 'fbs_render_embed_box', FBS_POST_TYPE, 'side', 'default' );
	add_meta_box( 'fbs-settings', __( 'Reader settings', 'flipbook-studio' ), 'fbs_render_settings_box', FBS_POST_TYPE, 'normal', 'default' );
	add_meta_box( 'fbs-stats', __( 'Reading activity', 'flipbook-studio' ), 'fbs_render_stats_box', FBS_POST_TYPE, 'side', 'low' );
}
add_action( 'add_meta_boxes_' . FBS_POST_TYPE, 'fbs_add_meta_boxes' );

/**
 * Upload panel.
 *
 * @param WP_Post $post Current flipbook.
 */
function fbs_render_file_box( $post ) {
	// The nonce is the proof that this form was really rendered by us for this
	// user. Without it, another site could POST to post.php on the user's
	// behalf while they are logged in (CSRF).
	wp_nonce_field( 'fbs_save_' . $post->ID, 'fbs_nonce' );

	$relative = get_post_meta( $post->ID, '_fbs_file', true );
	$has_file = (bool) fbs_resolve_path( $relative );
	$name     = get_post_meta( $post->ID, '_fbs_filename', true );
	$size     = (int) get_post_meta( $post->ID, '_fbs_filesize', true );
	$max      = (int) fbs_setting( 'max_upload_mb', 64 );
	?>
	<div class="fbs-box">
		<?php if ( $has_file ) : ?>
			<p class="fbs-file-current">
				<span class="dashicons dashicons-media-document"></span>
				<strong><?php echo esc_html( $name ? $name : basename( $relative ) ); ?></strong>
				<span class="fbs-muted"><?php echo esc_html( size_format( $size ) ); ?></span>
			</p>
			<p class="fbs-muted">
				<?php esc_html_e( 'Stored privately outside the media library. It has no public URL of its own.', 'flipbook-studio' ); ?>
			</p>
			<p>
				<label>
					<input type="checkbox" name="fbs_remove_file" value="1">
					<?php esc_html_e( 'Remove this PDF when I update', 'flipbook-studio' ); ?>
				</label>
			</p>
			<p><label for="fbs_pdf"><strong><?php esc_html_e( 'Replace with a different PDF', 'flipbook-studio' ); ?></strong></label></p>
		<?php else : ?>
			<p><label for="fbs_pdf"><strong><?php esc_html_e( 'Choose a PDF to publish', 'flipbook-studio' ); ?></strong></label></p>
		<?php endif; ?>

		<input type="file" id="fbs_pdf" name="fbs_pdf" accept="application/pdf,.pdf">
		<p class="fbs-muted">
			<?php
			printf(
				/* translators: %s: maximum upload size in megabytes. */
				esc_html__( 'PDF only, up to %s MB. Publish the flipbook to make it readable.', 'flipbook-studio' ),
				esc_html( number_format_i18n( $max ) )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Embed panel: shortcode, block-friendly snippet and direct link.
 *
 * @param WP_Post $post Current flipbook.
 */
function fbs_render_embed_box( $post ) {
	$shortcode = '[flipbook id="' . (int) $post->ID . '"]';
	?>
	<p class="fbs-muted"><?php esc_html_e( 'Paste this into any page or post.', 'flipbook-studio' ); ?></p>
	<p><input type="text" class="widefat code fbs-copy" readonly value="<?php echo esc_attr( $shortcode ); ?>"></p>
	<p class="fbs-muted"><?php esc_html_e( 'Or link straight to its own page.', 'flipbook-studio' ); ?></p>
	<p><input type="text" class="widefat code fbs-copy" readonly value="<?php echo esc_url( get_permalink( $post ) ); ?>"></p>
	<p class="fbs-muted"><?php esc_html_e( 'Click a field to copy it.', 'flipbook-studio' ); ?></p>
	<?php
}

/**
 * Reader settings panel.
 *
 * @param WP_Post $post Current flipbook.
 */
function fbs_render_settings_box( $post ) {
	$id           = $post->ID;
	$has_password = (bool) get_post_meta( $id, '_fbs_password', true );
	?>
	<div class="fbs-box fbs-grid">

		<section>
			<h4><?php esc_html_e( 'Appearance', 'flipbook-studio' ); ?></h4>

			<p>
				<label for="fbs_theme"><?php esc_html_e( 'Theme', 'flipbook-studio' ); ?></label><br>
				<select name="_fbs_theme" id="fbs_theme">
					<?php
					$themes = array(
						'ink'   => __( 'Ink — dark reading room', 'flipbook-studio' ),
						'paper' => __( 'Paper — light and quiet', 'flipbook-studio' ),
						'slate' => __( 'Slate — neutral grey', 'flipbook-studio' ),
					);
					foreach ( $themes as $value => $label ) {
						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( $value ),
							selected( fbs_get_meta( $id, '_fbs_theme' ), $value, false ),
							esc_html( $label )
						);
					}
					?>
				</select>
			</p>

			<p>
				<label for="fbs_height"><?php esc_html_e( 'Reader height (px)', 'flipbook-studio' ); ?></label><br>
				<input type="number" min="320" max="1600" step="10" id="fbs_height" name="_fbs_height"
					value="<?php echo esc_attr( fbs_get_meta( $id, '_fbs_height' ) ); ?>">
			</p>

			<p>
				<label for="fbs_start_page"><?php esc_html_e( 'Open on page', 'flipbook-studio' ); ?></label><br>
				<input type="number" min="1" step="1" id="fbs_start_page" name="_fbs_start_page"
					value="<?php echo esc_attr( fbs_get_meta( $id, '_fbs_start_page' ) ); ?>">
			</p>

			<p><label><input type="checkbox" name="_fbs_sound" value="1" <?php checked( fbs_get_meta( $id, '_fbs_sound' ), 1 ); ?>>
				<?php esc_html_e( 'Page-turn sound (readers can mute it)', 'flipbook-studio' ); ?></label></p>

			<p><label><input type="checkbox" name="_fbs_single_page" value="1" <?php checked( fbs_get_meta( $id, '_fbs_single_page' ), 1 ); ?>>
				<?php esc_html_e( 'Always show one page at a time', 'flipbook-studio' ); ?></label></p>
		</section>

		<section>
			<h4><?php esc_html_e( 'Who can read it', 'flipbook-studio' ); ?></h4>

			<p>
				<label for="fbs_password"><?php esc_html_e( 'Password', 'flipbook-studio' ); ?></label><br>
				<input type="text" id="fbs_password" name="fbs_password" class="widefat" autocomplete="off"
					placeholder="<?php echo $has_password ? esc_attr__( 'Password is set — type here to change it', 'flipbook-studio' ) : esc_attr__( 'Leave empty for no password', 'flipbook-studio' ); ?>">
			</p>
			<?php if ( $has_password ) : ?>
				<p><label><input type="checkbox" name="fbs_clear_password" value="1">
					<?php esc_html_e( 'Remove the password', 'flipbook-studio' ); ?></label></p>
			<?php endif; ?>

			<p><label><input type="checkbox" name="_fbs_require_login" value="1" <?php checked( fbs_get_meta( $id, '_fbs_require_login' ), 1 ); ?>>
				<?php esc_html_e( 'Only signed-in users', 'flipbook-studio' ); ?></label></p>

			<p>
				<label for="fbs_expires"><?php esc_html_e( 'Stop working after', 'flipbook-studio' ); ?></label><br>
				<input type="datetime-local" id="fbs_expires" name="_fbs_expires"
					value="<?php echo esc_attr( fbs_expires_for_input( fbs_get_meta( $id, '_fbs_expires' ) ) ); ?>">
			</p>

			<p>
				<label for="fbs_domains"><?php esc_html_e( 'Only embed on these domains', 'flipbook-studio' ); ?></label><br>
				<textarea id="fbs_domains" name="_fbs_allowed_domains" rows="3" class="widefat"
					placeholder="example.com&#10;client.example.org"><?php echo esc_textarea( fbs_get_meta( $id, '_fbs_allowed_domains' ) ); ?></textarea>
				<span class="fbs-muted"><?php esc_html_e( 'One per line. Empty means anywhere.', 'flipbook-studio' ); ?></span>
			</p>
		</section>

		<section>
			<h4><?php esc_html_e( 'How much they get', 'flipbook-studio' ); ?></h4>

			<p>
				<label for="fbs_preview"><?php esc_html_e( 'Free preview pages', 'flipbook-studio' ); ?></label><br>
				<input type="number" min="0" step="1" id="fbs_preview" name="_fbs_preview_pages"
					value="<?php echo esc_attr( fbs_get_meta( $id, '_fbs_preview_pages' ) ); ?>">
				<span class="fbs-muted"><?php esc_html_e( '0 shows the whole book.', 'flipbook-studio' ); ?></span>
			</p>

			<p><label><input type="checkbox" name="_fbs_allow_download" value="1" <?php checked( fbs_get_meta( $id, '_fbs_allow_download' ), 1 ); ?>>
				<?php esc_html_e( 'Show a download button', 'flipbook-studio' ); ?></label></p>

			<p><label><input type="checkbox" name="_fbs_allow_print" value="1" <?php checked( fbs_get_meta( $id, '_fbs_allow_print' ), 1 ); ?>>
				<?php esc_html_e( 'Show a print button', 'flipbook-studio' ); ?></label></p>

			<p>
				<label for="fbs_watermark"><?php esc_html_e( 'Watermark text', 'flipbook-studio' ); ?></label><br>
				<input type="text" id="fbs_watermark" name="_fbs_watermark" class="widefat"
					value="<?php echo esc_attr( fbs_get_meta( $id, '_fbs_watermark' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Confidential — {user} — {date}', 'flipbook-studio' ); ?>">
				<span class="fbs-muted"><?php esc_html_e( '{user}, {email} and {date} are filled in per reader.', 'flipbook-studio' ); ?></span>
			</p>
		</section>
	</div>

	<p class="fbs-note">
		<?php esc_html_e( 'Download, print and watermark controls discourage casual copying. Anyone determined enough can still screenshot a page — the checks that actually hold are the password, expiry, login and domain rules above, because those run on the server.', 'flipbook-studio' ); ?>
	</p>
	<?php
}

/**
 * Converts a stored expiry to the format a datetime-local input accepts.
 *
 * The input element rejects a value carrying seconds, so a stored
 * "2026-08-01 18:30:00" has to become "2026-08-01T18:30" or the browser
 * silently shows an empty field.
 *
 * @param string $value Stored expiry.
 * @return string
 */
function fbs_expires_for_input( $value ) {
	$time = $value ? strtotime( $value ) : false;
	return $time ? gmdate( 'Y-m-d\TH:i', $time ) : '';
}

/**
 * Reading activity panel.
 *
 * @param WP_Post $post Current flipbook.
 */
function fbs_render_stats_box( $post ) {
	if ( ! fbs_setting( 'analytics', 1 ) ) {
		echo '<p class="fbs-muted">' . esc_html__( 'Analytics are switched off in Flipbook settings.', 'flipbook-studio' ) . '</p>';
		return;
	}

	$stats = fbs_get_stats( $post->ID );
	?>
	<p>
		<strong><?php echo esc_html( number_format_i18n( $stats['sessions'] ) ); ?></strong> <?php esc_html_e( 'readers', 'flipbook-studio' ); ?><br>
		<strong><?php echo esc_html( number_format_i18n( $stats['views'] ) ); ?></strong> <?php esc_html_e( 'pages turned', 'flipbook-studio' ); ?>
	</p>
	<?php if ( $stats['top'] ) : ?>
		<p class="fbs-muted"><?php esc_html_e( 'Most-read pages', 'flipbook-studio' ); ?></p>
		<ol class="fbs-toplist">
			<?php foreach ( $stats['top'] as $row ) : ?>
				<li>
					<?php
					printf(
						/* translators: 1: page number, 2: view count. */
						esc_html__( 'Page %1$s — %2$s views', 'flipbook-studio' ),
						esc_html( number_format_i18n( $row->page ) ),
						esc_html( number_format_i18n( $row->hits ) )
					);
					?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php else : ?>
		<p class="fbs-muted"><?php esc_html_e( 'No reads recorded yet.', 'flipbook-studio' ); ?></p>
	<?php endif; ?>
	<?php
}

/**
 * Saves everything the edit screen submitted.
 *
 * The four guards at the top are the standard WordPress order: skip autosaves,
 * confirm the request came from our form, confirm the post type, and confirm
 * this particular user is allowed to edit this particular post. Skipping any
 * one of them turns the handler into an open write endpoint.
 *
 * @param int $post_id Post being saved.
 */
function fbs_save_post( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! isset( $_POST['fbs_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fbs_nonce'] ) ), 'fbs_save_' . $post_id ) ) {
		return;
	}

	if ( FBS_POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( fbs_meta_fields() as $key => $field ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_post_meta( $post_id, $key, fbs_sanitize_meta( $key, $raw ) );
	}

	// Passwords are hashed with WordPress's own hasher, so the plain text is
	// never stored and a database leak does not hand over the password.
	if ( ! empty( $_POST['fbs_clear_password'] ) ) {
		delete_post_meta( $post_id, '_fbs_password' );
	} elseif ( ! empty( $_POST['fbs_password'] ) ) {
		$plain = (string) wp_unslash( $_POST['fbs_password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_post_meta( $post_id, '_fbs_password', wp_hash_password( $plain ) );
	}

	if ( ! empty( $_POST['fbs_remove_file'] ) ) {
		fbs_delete_stored_file( $post_id );
	}

	if ( ! empty( $_FILES['fbs_pdf']['name'] ) ) {
		$stored = fbs_store_upload( $_FILES['fbs_pdf'], $post_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( is_wp_error( $stored ) ) {
			set_transient( 'fbs_notice_' . get_current_user_id(), $stored->get_error_message(), 60 );
		} else {
			fbs_delete_stored_file( $post_id );
			update_post_meta( $post_id, '_fbs_file', $stored['path'] );
			update_post_meta( $post_id, '_fbs_filename', $stored['name'] );
			update_post_meta( $post_id, '_fbs_filesize', $stored['size'] );
		}
	}
}
add_action( 'save_post_' . FBS_POST_TYPE, 'fbs_save_post' );

/**
 * Shows upload errors saved during the last save.
 */
function fbs_admin_notices() {
	$key    = 'fbs_notice_' . get_current_user_id();
	$notice = get_transient( $key );

	if ( $notice ) {
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $notice ) );
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && FBS_POST_TYPE === $screen->post_type && ! is_writable( dirname( fbs_protected_dir() ) ) ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'The uploads folder is not writable, so PDFs cannot be saved. Fix the permissions on wp-content/uploads.', 'flipbook-studio' )
		);
	}
}
add_action( 'admin_notices', 'fbs_admin_notices' );

/**
 * Extra columns on the flipbook list table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function fbs_list_columns( $columns ) {
	$insert = array(
		'fbs_file'      => __( 'PDF', 'flipbook-studio' ),
		'fbs_access'    => __( 'Access', 'flipbook-studio' ),
		'fbs_views'     => __( 'Reads', 'flipbook-studio' ),
		'fbs_shortcode' => __( 'Shortcode', 'flipbook-studio' ),
	);

	$date = isset( $columns['date'] ) ? array( 'date' => $columns['date'] ) : array();
	unset( $columns['date'] );

	return array_merge( $columns, $insert, $date );
}
add_filter( 'manage_' . FBS_POST_TYPE . '_posts_columns', 'fbs_list_columns' );

/**
 * Fills the extra list columns.
 *
 * @param string $column  Column key.
 * @param int    $post_id Row post ID.
 */
function fbs_list_column_content( $column, $post_id ) {
	switch ( $column ) {
		case 'fbs_file':
			$name = get_post_meta( $post_id, '_fbs_filename', true );
			echo $name
				? esc_html( $name )
				: '<span class="fbs-warn">' . esc_html__( 'No PDF yet', 'flipbook-studio' ) . '</span>';
			break;

		case 'fbs_access':
			$flags = array();
			if ( get_post_meta( $post_id, '_fbs_password', true ) ) {
				$flags[] = __( 'Password', 'flipbook-studio' );
			}
			if ( fbs_get_meta( $post_id, '_fbs_require_login' ) ) {
				$flags[] = __( 'Sign-in', 'flipbook-studio' );
			}
			if ( fbs_get_meta( $post_id, '_fbs_expires' ) ) {
				$flags[] = __( 'Expires', 'flipbook-studio' );
			}
			if ( fbs_get_meta( $post_id, '_fbs_allowed_domains' ) ) {
				$flags[] = __( 'Domain-locked', 'flipbook-studio' );
			}
			echo esc_html( $flags ? implode( ', ', $flags ) : __( 'Open', 'flipbook-studio' ) );
			break;

		case 'fbs_views':
			$stats = fbs_get_stats( $post_id );
			echo esc_html( number_format_i18n( $stats['views'] ) );
			break;

		case 'fbs_shortcode':
			printf( '<code>[flipbook id="%d"]</code>', (int) $post_id );
			break;
	}
}
add_action( 'manage_' . FBS_POST_TYPE . '_posts_custom_column', 'fbs_list_column_content', 10, 2 );

/**
 * Adds the settings screen under the Flipbooks menu.
 */
function fbs_settings_menu() {
	add_submenu_page(
		'edit.php?post_type=' . FBS_POST_TYPE,
		__( 'Flipbook settings', 'flipbook-studio' ),
		__( 'Settings', 'flipbook-studio' ),
		'manage_options',
		'fbs-settings',
		'fbs_render_settings_page'
	);
}
add_action( 'admin_menu', 'fbs_settings_menu' );

/**
 * Registers the settings group so WordPress handles saving and nonces for us.
 */
function fbs_register_settings() {
	register_setting(
		'fbs_settings_group',
		'fbs_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'fbs_sanitize_settings',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'fbs_register_settings' );

/**
 * Cleans the site-wide settings.
 *
 * @param array $input Raw submitted settings.
 * @return array
 */
function fbs_sanitize_settings( $input ) {
	return array(
		'max_upload_mb'   => min( 512, max( 1, (int) ( $input['max_upload_mb'] ?? 64 ) ) ),
		'token_ttl'       => min( DAY_IN_SECONDS, max( 60, (int) ( $input['token_ttl'] ?? 900 ) ) ),
		'bind_to_ip'      => empty( $input['bind_to_ip'] ) ? 0 : 1,
		'analytics'       => empty( $input['analytics'] ) ? 0 : 1,
		'default_theme'   => in_array( $input['default_theme'] ?? '', array( 'ink', 'paper', 'slate' ), true ) ? $input['default_theme'] : 'ink',
		'delete_on_purge' => empty( $input['delete_on_purge'] ) ? 0 : 1,
	);
}

/**
 * The settings screen, including a live self-test of the private folder.
 */
function fbs_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'flipbook-studio' ) );
	}

	$exposed = fbs_protected_dir_is_exposed();
	?>
	<div class="wrap fbs-settings">
		<h1><?php esc_html_e( 'Flipbook settings', 'flipbook-studio' ); ?></h1>

		<div class="fbs-health <?php echo $exposed ? 'is-bad' : 'is-good'; ?>">
			<?php if ( $exposed ) : ?>
				<h2><?php esc_html_e( 'Your PDF folder is reachable from the web', 'flipbook-studio' ); ?></h2>
				<p><?php esc_html_e( 'A request to the private folder returned a file instead of an error. On nginx the deny rules this plugin writes are ignored, so add this to your server block and reload nginx:', 'flipbook-studio' ); ?></p>
				<pre><code>location ~* /wp-content/uploads/flipbook-protected/ {
    deny all;
    return 403;
}</code></pre>
			<?php else : ?>
				<h2><?php esc_html_e( 'Private storage is working', 'flipbook-studio' ); ?></h2>
				<p><?php esc_html_e( 'Direct requests to the PDF folder are being refused. Files are only readable through the signed reader endpoint.', 'flipbook-studio' ); ?></p>
			<?php endif; ?>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'fbs_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fbs_max"><?php esc_html_e( 'Largest PDF (MB)', 'flipbook-studio' ); ?></label></th>
					<td>
						<input type="number" id="fbs_max" name="fbs_settings[max_upload_mb]" min="1" max="512"
							value="<?php echo esc_attr( fbs_setting( 'max_upload_mb', 64 ) ); ?>">
						<p class="description">
							<?php
							printf(
								/* translators: %s: server upload limit. */
								esc_html__( 'Your server currently accepts uploads up to %s.', 'flipbook-studio' ),
								esc_html( size_format( wp_max_upload_size() ) )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fbs_ttl"><?php esc_html_e( 'Reader link lifetime (seconds)', 'flipbook-studio' ); ?></label></th>
					<td>
						<input type="number" id="fbs_ttl" name="fbs_settings[token_ttl]" min="60" max="86400"
							value="<?php echo esc_attr( fbs_setting( 'token_ttl', 900 ) ); ?>">
						<p class="description"><?php esc_html_e( 'How long a signed file link stays valid. Shorter is safer; the reader requests a fresh one whenever it needs to.', 'flipbook-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Strict link binding', 'flipbook-studio' ); ?></th>
					<td>
						<label><input type="checkbox" name="fbs_settings[bind_to_ip]" value="1" <?php checked( fbs_setting( 'bind_to_ip', 0 ), 1 ); ?>>
							<?php esc_html_e( 'Also tie reader links to the visitor IP address', 'flipbook-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Harder to share a stolen link, but readers on mobile networks may be interrupted when their IP changes.', 'flipbook-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reading activity', 'flipbook-studio' ); ?></th>
					<td>
						<label><input type="checkbox" name="fbs_settings[analytics]" value="1" <?php checked( fbs_setting( 'analytics', 1 ), 1 ); ?>>
							<?php esc_html_e( 'Record which pages get read', 'flipbook-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Stores a page number, a random session id and a salted hash of the IP. No raw addresses and no cookies.', 'flipbook-studio' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fbs_theme_default"><?php esc_html_e( 'Default theme', 'flipbook-studio' ); ?></label></th>
					<td>
						<select id="fbs_theme_default" name="fbs_settings[default_theme]">
							<option value="ink" <?php selected( fbs_setting( 'default_theme' ), 'ink' ); ?>><?php esc_html_e( 'Ink', 'flipbook-studio' ); ?></option>
							<option value="paper" <?php selected( fbs_setting( 'default_theme' ), 'paper' ); ?>><?php esc_html_e( 'Paper', 'flipbook-studio' ); ?></option>
							<option value="slate" <?php selected( fbs_setting( 'default_theme' ), 'slate' ); ?>><?php esc_html_e( 'Slate', 'flipbook-studio' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When the plugin is deleted', 'flipbook-studio' ); ?></th>
					<td>
						<label><input type="checkbox" name="fbs_settings[delete_on_purge]" value="1" <?php checked( fbs_setting( 'delete_on_purge', 0 ), 1 ); ?>>
							<?php esc_html_e( 'Also delete stored PDFs, settings and reading history', 'flipbook-studio' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default so an accidental delete does not destroy your files.', 'flipbook-studio' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'flipbook-studio' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Shortcode reference', 'flipbook-studio' ); ?></h2>
		<table class="widefat striped fbs-ref">
			<tbody>
				<tr><td><code>[flipbook id="12"]</code></td><td><?php esc_html_e( 'Embed flipbook 12 with its own saved settings.', 'flipbook-studio' ); ?></td></tr>
				<tr><td><code>[flipbook id="12" height="800"]</code></td><td><?php esc_html_e( 'Override the reader height for this one embed.', 'flipbook-studio' ); ?></td></tr>
				<tr><td><code>[flipbook id="12" theme="paper"]</code></td><td><?php esc_html_e( 'Override the theme: ink, paper or slate.', 'flipbook-studio' ); ?></td></tr>
				<tr><td><code>[flipbook id="12" page="4"]</code></td><td><?php esc_html_e( 'Open on a specific page.', 'flipbook-studio' ); ?></td></tr>
				<tr><td><code>[flipbook id="12" toolbar="no"]</code></td><td><?php esc_html_e( 'Hide the toolbar for a bare, decorative embed.', 'flipbook-studio' ); ?></td></tr>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Asks the site whether the private folder is actually private.
 *
 * Writing deny rules is not the same as proving they work, so this makes a
 * real loopback HTTP request to a canary file and reports what came back.
 * The result is cached for a day to keep the settings page fast.
 *
 * @return bool True when the folder is readable from outside.
 */
function fbs_protected_dir_is_exposed() {
	$cached = get_transient( 'fbs_dir_exposed' );
	if ( false !== $cached ) {
		return (bool) $cached;
	}

	fbs_prepare_protected_dir();

	$canary = fbs_protected_dir() . '/canary.txt';
	fbs_write_file( $canary, 'flipbook-studio-canary' );

	$uploads  = wp_upload_dir();
	$url      = trailingslashit( $uploads['baseurl'] ) . 'flipbook-protected/canary.txt';
	$response = wp_remote_get( $url, array( 'timeout' => 5, 'sslverify' => false ) );

	$exposed = ! is_wp_error( $response )
		&& 200 === wp_remote_retrieve_response_code( $response )
		&& false !== strpos( wp_remote_retrieve_body( $response ), 'flipbook-studio-canary' );

	set_transient( 'fbs_dir_exposed', $exposed ? 1 : 0, DAY_IN_SECONDS );

	return $exposed;
}

/**
 * Loads admin styles and scripts only on flipbook screens.
 *
 * @param string $hook Current admin page.
 */
function fbs_admin_assets( $hook ) {
	$screen = get_current_screen();
	$ours   = ( $screen && FBS_POST_TYPE === $screen->post_type ) || 'flipbook_page_fbs-settings' === $hook;

	if ( ! $ours ) {
		return;
	}

	wp_enqueue_style( 'fbs-admin', FBS_URL . 'assets/css/admin.css', array(), FBS_VERSION );
	wp_enqueue_script( 'fbs-admin', FBS_URL . 'assets/js/admin.js', array(), FBS_VERSION, true );
	wp_localize_script( 'fbs-admin', 'fbsAdmin', array( 'copied' => __( 'Copied', 'flipbook-studio' ) ) );
}
add_action( 'admin_enqueue_scripts', 'fbs_admin_assets' );
