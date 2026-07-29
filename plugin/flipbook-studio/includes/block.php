<?php
/**
 * The Flipbook block.
 *
 * This is a *dynamic* block: the editor stores only attributes (which book,
 * which overrides) and the server renders the real markup at view time through
 * the exact same fbs_render_reader() the shortcode uses. One rendering path
 * means the block and the shortcode can never drift apart, and access rules
 * are always evaluated fresh per request instead of being baked into saved
 * post content.
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block and its editor assets.
 *
 * The editor script is written in plain JavaScript against wp.element, so the
 * plugin needs no build step. The dependency list here is what makes
 * wp.blocks, wp.components etc. exist by the time the script runs.
 */
function fbs_register_block() {
	wp_register_script(
		'fbs-block-editor',
		FBS_URL . 'assets/js/block-editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'wp-i18n' ),
		FBS_VERSION,
		true
	);

	wp_register_style(
		'fbs-block-editor',
		FBS_URL . 'assets/css/block-editor.css',
		array(),
		FBS_VERSION
	);

	register_block_type(
		'flipbook-studio/flipbook',
		array(
			'api_version'     => 3,
			'title'           => __( 'Flipbook', 'flipbook-studio' ),
			'description'     => __( 'Embed a PDF flipbook.', 'flipbook-studio' ),
			'category'        => 'media',
			'icon'            => 'book-alt',
			'keywords'        => array( 'pdf', 'flipbook', 'book', 'catalogue' ),
			'editor_script'   => 'fbs-block-editor',
			'editor_style'    => 'fbs-block-editor',
			'render_callback' => 'fbs_render_block',
			'supports'        => array(
				'align'   => array( 'wide', 'full' ),
				'anchor'  => true,
				'html'    => false,
			),
			'attributes'      => array(
				'bookId'  => array( 'type' => 'number', 'default' => 0 ),
				// 0 / empty string mean "use whatever the flipbook itself is
				// configured with", so the block only overrides when asked to.
				'height'  => array( 'type' => 'number', 'default' => 0 ),
				'theme'   => array( 'type' => 'string', 'default' => '' ),
				'page'    => array( 'type' => 'number', 'default' => 0 ),
				'toolbar' => array( 'type' => 'boolean', 'default' => true ),
			),
		)
	);
}
add_action( 'init', 'fbs_register_block' );

/**
 * Renders the block on the front end.
 *
 * get_block_wrapper_attributes() is what carries the editor's own classes
 * (alignwide, alignfull, custom class, anchor id) onto the output. Skipping it
 * would silently break the alignment controls.
 *
 * @param array $attributes Saved block attributes.
 * @return string
 */
function fbs_render_block( $attributes ) {
	$book_id = isset( $attributes['bookId'] ) ? (int) $attributes['bookId'] : 0;

	if ( ! $book_id ) {
		return '';
	}

	$atts = array( 'id' => $book_id );

	if ( ! empty( $attributes['height'] ) ) {
		$atts['height'] = (int) $attributes['height'];
	}

	if ( ! empty( $attributes['theme'] ) ) {
		$atts['theme'] = sanitize_key( $attributes['theme'] );
	}

	if ( ! empty( $attributes['page'] ) ) {
		$atts['page'] = (int) $attributes['page'];
	}

	if ( isset( $attributes['toolbar'] ) && ! $attributes['toolbar'] ) {
		$atts['toolbar'] = 'no';
	}

	$wrapper = function_exists( 'get_block_wrapper_attributes' )
		? get_block_wrapper_attributes( array( 'class' => 'fbs-block' ) )
		: 'class="fbs-block"';

	return '<div ' . $wrapper . '>' . fbs_render_reader( $book_id, $atts ) . '</div>';
}

/**
 * A purpose-built list route for the block's picker.
 *
 * The flipbook post type is deliberately kept out of the core REST API (see
 * post-type.php), so the block cannot use the standard /wp/v2/ listing. This
 * route exists instead, exposes only what the picker needs — id, title,
 * whether a PDF is attached — and only to users who can edit posts. Visitors
 * get a 403 and learn nothing about what flipbooks exist.
 *
 * @return void
 */
function fbs_register_list_route() {
	register_rest_route(
		'flipbook/v1',
		'/list',
		array(
			'methods'             => 'GET',
			'callback'            => 'fbs_rest_list',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'fbs_register_list_route' );

/**
 * Returns every flipbook the current user could embed.
 *
 * @return WP_REST_Response
 */
function fbs_rest_list() {
	$posts = get_posts(
		array(
			'post_type'      => FBS_POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$books = array();

	foreach ( $posts as $post ) {
		$books[] = array(
			'id'      => $post->ID,
			'title'   => $post->post_title ? $post->post_title : __( '(no title)', 'flipbook-studio' ),
			'status'  => $post->post_status,
			'hasFile' => (bool) fbs_resolve_path( get_post_meta( $post->ID, '_fbs_file', true ) ),
			'edit'    => get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	return new WP_REST_Response( $books, 200 );
}
