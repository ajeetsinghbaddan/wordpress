<?php
/**
 * Main loader. Connects every class to the right WordPress hooks.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quiz_Certify {

	public static function init() {
		// Keep the DB schema current for installs that upgraded from an older version.
		// We call this DIRECTLY rather than via add_action('plugins_loaded', …): adding
		// a callback to plugins_loaded from within plugins_loaded does not reliably fire,
		// which is why the user_email column was never created on upgraded sites. The
		// check inside is a cheap, cached option read, so this is effectively free once
		// the schema is up to date.
		Quiz_Certify_Activator::maybe_upgrade();

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		// Post type + certificate endpoint.
		add_action( 'init', array( 'Quiz_Certify_Post_Types', 'register' ) );
		add_filter( 'query_vars', array( 'Quiz_Certify_Certificate', 'register_query_var' ) );
		add_action( 'template_redirect', array( 'Quiz_Certify_Certificate', 'maybe_render' ) );

		// Admin: meta boxes, save, and admin assets.
		add_action( 'add_meta_boxes', array( 'Quiz_Certify_Meta_Boxes', 'add' ) );
		add_action( 'save_post_qc_quiz', array( 'Quiz_Certify_Meta_Boxes', 'save' ) );
		add_action( 'admin_enqueue_scripts', array( 'Quiz_Certify_Meta_Boxes', 'enqueue_admin' ) );

		// Front end: register assets once, then the shortcode/block enqueue them on demand.
		add_action( 'wp_enqueue_scripts', array( 'Quiz_Certify_Shortcode', 'register_assets' ) );
		add_action( 'init', array( 'Quiz_Certify_Shortcode', 'register' ) );

		// Quiz listing: the [quiz_certify_list] grid and its AJAX quiz loader.
		add_action( 'init', array( 'Quiz_Certify_List', 'register' ) );

		// Gutenberg block (wraps the shortcode so block themes and the editor treat it
		// as a first-class block). Elementor users can use its native Shortcode widget.
		add_action( 'init', array( 'Quiz_Certify_Block', 'register' ) );

		// Native Elementor widgets (inert unless Elementor is active).
		Quiz_Certify_Elementor::register();

		// AJAX grading endpoint.
		add_action( 'init', array( 'Quiz_Certify_Ajax', 'register' ) );

		// Student records: admin list page + CSV export.
		add_action( 'admin_menu', array( 'Quiz_Certify_Results', 'add_menu' ) );
		add_action( 'admin_post_qc_export_results', array( 'Quiz_Certify_Results', 'export_csv' ) );
	}

	public static function load_textdomain() {
		load_plugin_textdomain(
			'quiz-certify',
			false,
			dirname( plugin_basename( QUIZ_CERTIFY_PATH . 'quiz-certify.php' ) ) . '/languages'
		);
	}
}
