<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSM_Metabox {

	const NONCE_ACTION = 'ssm_save_schema';
	const NONCE_NAME   = 'ssm_schema_nonce';
	const META_SCHEMA  = '_ssm_custom_schema';
	const META_DISABLE = '_ssm_disable_auto';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	public function add_metabox() {
		add_meta_box(
			'ssm-schema-box',
			__( 'Schema Markup (Manual)', 'simple-schema-manager' ),
			array( $this, 'render' ),
			array( 'post', 'page' ),
			'normal',
			'default'
		);
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$custom_schema = get_post_meta( $post->ID, self::META_SCHEMA, true );
		$disable_auto  = get_post_meta( $post->ID, self::META_DISABLE, true );
		?>
		<p>
			<label for="ssm_custom_schema">
				<?php esc_html_e( 'Paste valid JSON-LD here (without the script tag). It will be printed in the head of this page.', 'simple-schema-manager' ); ?>
			</label>
		</p>
		<textarea id="ssm_custom_schema" name="ssm_custom_schema" class="large-text code" rows="8"><?php echo esc_textarea( $custom_schema ); ?></textarea>
		<p>
			<label>
				<input type="checkbox" name="ssm_disable_auto" value="1" <?php checked( $disable_auto, '1' ); ?>>
				<?php esc_html_e( 'Disable automatic schema for this content', 'simple-schema-manager' ); ?>
			</label>
		</p>
		<?php
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'unfiltered_html' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$disable_auto = isset( $_POST['ssm_disable_auto'] ) ? '1' : '';
		update_post_meta( $post_id, self::META_DISABLE, $disable_auto );

		$raw_schema = isset( $_POST['ssm_custom_schema'] ) ? wp_unslash( $_POST['ssm_custom_schema'] ) : '';
		$raw_schema = trim( $raw_schema );

		if ( '' === $raw_schema ) {
			delete_post_meta( $post_id, self::META_SCHEMA );
			return;
		}

		$decoded = json_decode( $raw_schema, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			delete_post_meta( $post_id, self::META_SCHEMA );
			return;
		}

		$normalized = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		update_post_meta( $post_id, self::META_SCHEMA, $normalized );
	}
}
