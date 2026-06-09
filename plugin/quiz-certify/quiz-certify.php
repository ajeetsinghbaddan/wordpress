<?php
/**
 * Plugin Name:       Quiz Certify
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/quiz-certify
 * Description:       Create multiple quizzes and let users print a certificate when they pass. Stores student records.
 * Version:           1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quiz-certify
 * Domain Path:       /languages
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bumping this version does two jobs: it cache-busts CSS/JS, and it triggers the
// database upgrade check (so existing installs gain the new student-email column).
define( 'QUIZ_CERTIFY_VERSION', '1.3.0' );
define( 'QUIZ_CERTIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUIZ_CERTIFY_URL', plugin_dir_url( __FILE__ ) );

require_once QUIZ_CERTIFY_PATH . 'includes/class-activator.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-post-types.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-meta-boxes.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-shortcode.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-list.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-block.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-elementor.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-ajax.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-certificate.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-results.php';
require_once QUIZ_CERTIFY_PATH . 'includes/class-quiz-certify.php';

register_activation_hook( __FILE__, array( 'Quiz_Certify_Activator', 'activate' ) );

add_action( 'plugins_loaded', array( 'Quiz_Certify', 'init' ) );
