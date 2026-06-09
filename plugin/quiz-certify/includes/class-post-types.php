<?php
/**
 * Registers the Quiz custom post type.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify_Post_Types {

	/**
	 * Register the custom post type that represents one quiz.
	 *
	 * A CPT gives us the whole admin UI for free: a list table, create/edit screens,
	 * capabilities, and revisions. Each quiz is one "post" of type qc_quiz; its
	 * questions and settings are attached as post meta (handled in the meta-boxes class).
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Quizzes', 'quiz-certify' ),
			'singular_name'      => __( 'Quiz', 'quiz-certify' ),
			'add_new'            => __( 'Add New Quiz', 'quiz-certify' ),
			'add_new_item'       => __( 'Add New Quiz', 'quiz-certify' ),
			'edit_item'          => __( 'Edit Quiz', 'quiz-certify' ),
			'new_item'           => __( 'New Quiz', 'quiz-certify' ),
			'view_item'          => __( 'View Quiz', 'quiz-certify' ),
			'search_items'       => __( 'Search Quizzes', 'quiz-certify' ),
			'not_found'          => __( 'No quizzes found', 'quiz-certify' ),
			'menu_name'          => __( 'Quizzes', 'quiz-certify' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false, // Not browsable on the front end on its own...
			'show_ui'            => true,  // ...but fully editable in wp-admin.
			'show_in_menu'       => true,
			'menu_icon'          => 'dashicons-forms',
			'menu_position'      => 25,
			'supports'           => array( 'title' ), // Title only; questions live in a meta box.
			'capability_type'    => 'post',           // Reuse the standard post capabilities.
			'map_meta_cap'       => true,
			'has_archive'        => false,
			'show_in_rest'       => false,            // We are not using the block editor for this.
		);

		register_post_type( 'qc_quiz', $args );
	}
}
