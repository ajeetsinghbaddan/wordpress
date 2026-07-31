<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRA_Editor {

	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
	}

	public static function enqueue_editor_assets() {
		wp_enqueue_script(
			'sra-editor',
			SRA_PLUGIN_URL . 'assets/js/sra-editor.js',
			array( 'wp-hooks', 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-compose', 'wp-i18n' ),
			SRA_VERSION,
			true
		);

		wp_add_inline_script(
			'sra-editor',
			'window.SRA_ANIMATIONS = ' . wp_json_encode( SRA_Settings::allowed_animations() ) . ';',
			'before'
		);
	}
}
