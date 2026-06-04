<?php
/**
 * Registers the `testimonial` Custom Post Type and `testimonial_category` taxonomy.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_CPT
 *
 * Why a CPT instead of a custom DB table? WordPress already gives us a fully
 * featured editor UI, revisions, capabilities, search and a queryable data
 * store for "posts". By declaring testimonials as a post type we inherit all of
 * that for free and avoid hand-writing SQL — which is also safer.
 */
class ASB_TS_CPT {

	/**
	 * The post type key. Kept short but prefixed-by-context ("testimonial") and
	 * referenced everywhere via this constant so a future rename is one edit.
	 */
	const POST_TYPE = 'testimonial';

	/**
	 * The taxonomy key used to group testimonials into categories.
	 */
	const TAXONOMY = 'testimonial_category';

	/**
	 * Hook registration into WordPress' 'init' action.
	 *
	 * CPTs and taxonomies MUST be registered on 'init' (not earlier) — that is
	 * the point in the load cycle where WordPress is ready to accept them.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
	}

	/**
	 * Register the `testimonial` post type.
	 *
	 * Key argument choices and the reasoning behind them:
	 * - 'public' => false + 'show_ui' => true: testimonials are managed in the
	 *   admin but are NOT meant to be browsed as standalone front-end pages.
	 *   They are surfaced through our layouts, shortcode, block and widget.
	 * - 'show_in_rest' => true: REQUIRED for the block editor / Gutenberg and
	 *   the REST API to see this post type.
	 * - 'supports': 'title' (used as an internal label), 'editor' (the rich
	 *   testimonial text), 'thumbnail' (we also offer a dedicated photo field),
	 *   'custom-fields' so meta is exposed.
	 * - 'capability_type' => 'post': testimonials use the same capabilities as
	 *   normal posts, so any Editor/Author can manage them per WP roles.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Testimonials', 'Post type general name', 'asb-testimonials-showcase' ),
			'singular_name'         => _x( 'Testimonial', 'Post type singular name', 'asb-testimonials-showcase' ),
			'menu_name'             => _x( 'Testimonials', 'Admin Menu text', 'asb-testimonials-showcase' ),
			'add_new'               => __( 'Add New', 'asb-testimonials-showcase' ),
			'add_new_item'          => __( 'Add New Testimonial', 'asb-testimonials-showcase' ),
			'edit_item'             => __( 'Edit Testimonial', 'asb-testimonials-showcase' ),
			'new_item'              => __( 'New Testimonial', 'asb-testimonials-showcase' ),
			'view_item'             => __( 'View Testimonial', 'asb-testimonials-showcase' ),
			'search_items'          => __( 'Search Testimonials', 'asb-testimonials-showcase' ),
			'not_found'             => __( 'No testimonials found.', 'asb-testimonials-showcase' ),
			'not_found_in_trash'    => __( 'No testimonials found in Trash.', 'asb-testimonials-showcase' ),
			'all_items'             => __( 'All Testimonials', 'asb-testimonials-showcase' ),
			'featured_image'        => __( 'Client Photo (fallback)', 'asb-testimonials-showcase' ),
			'set_featured_image'    => __( 'Set client photo', 'asb-testimonials-showcase' ),
			'remove_featured_image' => __( 'Remove client photo', 'asb-testimonials-showcase' ),
			'item_published'        => __( 'Testimonial published.', 'asb-testimonials-showcase' ),
			'item_updated'          => __( 'Testimonial updated.', 'asb-testimonials-showcase' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,           // Not a public single page by default.
			'publicly_queryable' => false,           // Cannot be queried on the front end directly.
			'show_ui'            => true,            // But DO show the admin management UI.
			'show_in_menu'       => true,
			'show_in_rest'       => true,            // Needed for the block editor + REST.
			'menu_icon'          => 'dashicons-format-quote',
			'menu_position'      => 25,
			'hierarchical'       => false,
			'has_archive'        => false,
			'rewrite'            => false,           // No front-end permalinks needed.
			'capability_type'    => 'post',
			'map_meta_cap'       => true,            // Map our checks to real post capabilities.
			'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
		);

		/**
		 * Filter the CPT args so advanced users can tweak registration without
		 * editing the plugin (e.g. make it publicly queryable). Prefixed hook
		 * name avoids collisions.
		 */
		$args = apply_filters( 'asb_ts_post_type_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register the hierarchical `testimonial_category` taxonomy.
	 *
	 * 'hierarchical' => true makes it behave like categories (with parent/child
	 * and checkboxes) rather than free-form tags. 'show_in_rest' => true again
	 * exposes it to the block editor so our block's category dropdown works.
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'              => _x( 'Testimonial Categories', 'taxonomy general name', 'asb-testimonials-showcase' ),
			'singular_name'     => _x( 'Testimonial Category', 'taxonomy singular name', 'asb-testimonials-showcase' ),
			'search_items'      => __( 'Search Categories', 'asb-testimonials-showcase' ),
			'all_items'         => __( 'All Categories', 'asb-testimonials-showcase' ),
			'parent_item'       => __( 'Parent Category', 'asb-testimonials-showcase' ),
			'parent_item_colon' => __( 'Parent Category:', 'asb-testimonials-showcase' ),
			'edit_item'         => __( 'Edit Category', 'asb-testimonials-showcase' ),
			'update_item'       => __( 'Update Category', 'asb-testimonials-showcase' ),
			'add_new_item'      => __( 'Add New Category', 'asb-testimonials-showcase' ),
			'new_item_name'     => __( 'New Category Name', 'asb-testimonials-showcase' ),
			'menu_name'         => __( 'Categories', 'asb-testimonials-showcase' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,   // Show the category column in the testimonial list table.
			'show_in_rest'      => true,   // Needed for the block editor.
			'rewrite'           => false,
		);

		$args = apply_filters( 'asb_ts_taxonomy_args', $args );

		register_taxonomy( self::TAXONOMY, array( self::POST_TYPE ), $args );
	}
}
