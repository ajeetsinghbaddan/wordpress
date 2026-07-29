<?php
/**
 * The "flipbook" content type and its settings fields.
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the flipbook post type.
 *
 * show_in_rest is deliberately false. That forces the classic editor for this
 * screen, which matters because the block editor submits meta boxes through a
 * background request where a plain <input type="file"> is unreliable. A stable
 * upload is worth more here than a block editing surface for a post type whose
 * whole content is one PDF.
 */
function fbs_register_post_type() {
	$labels = array(
		'name'               => __( 'Flipbooks', 'flipbook-studio' ),
		'singular_name'      => __( 'Flipbook', 'flipbook-studio' ),
		'add_new'            => __( 'Add Flipbook', 'flipbook-studio' ),
		'add_new_item'       => __( 'Add Flipbook', 'flipbook-studio' ),
		'edit_item'          => __( 'Edit Flipbook', 'flipbook-studio' ),
		'new_item'           => __( 'New Flipbook', 'flipbook-studio' ),
		'view_item'          => __( 'View Flipbook', 'flipbook-studio' ),
		'search_items'       => __( 'Search Flipbooks', 'flipbook-studio' ),
		'not_found'          => __( 'No flipbooks yet. Add one to get started.', 'flipbook-studio' ),
		'not_found_in_trash' => __( 'Nothing in the trash.', 'flipbook-studio' ),
		'menu_name'          => __( 'Flipbooks', 'flipbook-studio' ),
	);

	register_post_type(
		FBS_POST_TYPE,
		array(
			'labels'          => $labels,
			'public'          => true,
			'show_in_rest'    => false,
			'has_archive'     => false,
			'menu_icon'       => 'dashicons-book-alt',
			'menu_position'   => 21,
			'supports'        => array( 'title', 'editor', 'thumbnail', 'author', 'revisions' ),
			'rewrite'         => array( 'slug' => 'flipbook', 'with_front' => false ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		)
	);
}
add_action( 'init', 'fbs_register_post_type' );

/**
 * The full list of per-flipbook settings.
 *
 * Keeping them in one array means the metabox, the save routine and the
 * uninstall cleanup all read from the same source, so a new option can never
 * be half-wired. Each entry declares how its value is cleaned on the way in.
 *
 * @return array
 */
function fbs_meta_fields() {
	return array(
		'_fbs_allow_download'   => array( 'type' => 'bool',  'default' => 0 ),
		'_fbs_allow_print'      => array( 'type' => 'bool',  'default' => 0 ),
		'_fbs_require_login'    => array( 'type' => 'bool',  'default' => 0 ),
		'_fbs_sound'            => array( 'type' => 'bool',  'default' => 1 ),
		'_fbs_single_page'      => array( 'type' => 'bool',  'default' => 0 ),
		'_fbs_expires'          => array( 'type' => 'date',  'default' => '' ),
		'_fbs_preview_pages'    => array( 'type' => 'int',   'default' => 0 ),
		'_fbs_start_page'       => array( 'type' => 'int',   'default' => 1 ),
		'_fbs_height'           => array( 'type' => 'int',   'default' => 640 ),
		'_fbs_watermark'        => array( 'type' => 'text',  'default' => '' ),
		'_fbs_allowed_domains'  => array( 'type' => 'lines', 'default' => '' ),
		'_fbs_theme'            => array( 'type' => 'enum',  'default' => 'ink', 'options' => array( 'ink', 'paper', 'slate' ) ),
	);
}

/**
 * Cleans one incoming meta value according to its declared type.
 *
 * Sanitising centrally rather than at each call site is what keeps a stray
 * $_POST value from ever reaching the database in its raw form.
 *
 * @param string $key   Meta key.
 * @param mixed  $value Raw submitted value.
 * @return mixed
 */
function fbs_sanitize_meta( $key, $value ) {
	$fields = fbs_meta_fields();
	$field  = isset( $fields[ $key ] ) ? $fields[ $key ] : array( 'type' => 'text' );

	switch ( $field['type'] ) {
		case 'bool':
			return $value ? 1 : 0;

		case 'int':
			return max( 0, (int) $value );

		case 'date':
			// A datetime-local field submits "2026-08-01T18:30". Storing it in
			// MySQL format means every later comparison speaks one language.
			$value = sanitize_text_field( str_replace( 'T', ' ', $value ) );
			$time  = $value ? strtotime( $value ) : false;
			return $time ? gmdate( 'Y-m-d H:i:s', $time ) : '';

		case 'enum':
			return in_array( $value, $field['options'], true ) ? $value : $field['default'];

		case 'lines':
			$lines = preg_split( '/[\r\n,]+/', (string) $value );
			$clean = array();
			foreach ( $lines as $line ) {
				$line = sanitize_text_field( trim( $line ) );
				if ( '' !== $line ) {
					$clean[] = $line;
				}
			}
			return implode( "\n", $clean );

		default:
			return sanitize_text_field( $value );
	}
}

/**
 * Reads a flipbook setting, falling back to the declared default.
 *
 * @param int    $book_id Flipbook post ID.
 * @param string $key     Meta key.
 * @return mixed
 */
function fbs_get_meta( $book_id, $key ) {
	$fields  = fbs_meta_fields();
	$default = isset( $fields[ $key ]['default'] ) ? $fields[ $key ]['default'] : '';
	$value   = get_post_meta( $book_id, $key, true );

	return ( '' === $value || null === $value ) ? $default : $value;
}

/**
 * Removes the stored PDF when its flipbook is permanently deleted.
 *
 * Without this, deleting a post would leave orphaned PDFs on disk forever.
 *
 * @param int $post_id Post being deleted.
 */
function fbs_cleanup_on_delete( $post_id ) {
	if ( FBS_POST_TYPE === get_post_type( $post_id ) ) {
		fbs_delete_stored_file( $post_id );
		fbs_delete_analytics_for( $post_id );
	}
}
add_action( 'before_delete_post', 'fbs_cleanup_on_delete' );
