<?php
/**
 * Elementor widget classes.
 *
 * IMPORTANT: this file is only required from inside the 'elementor/widgets/register'
 * callback, i.e. only when Elementor is active. That is why it can safely extend
 * \Elementor\Widget_Base — the parent class is loaded at that point.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A native Elementor widget for embedding a single quiz, with a quiz picker in the panel.
 */
class QC_Elementor_Quiz extends \Elementor\Widget_Base {

	public function get_name() {
		return 'quiz_certify_quiz';
	}

	public function get_title() {
		return __( 'Quiz', 'quiz-certify' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'quiz', 'certificate', 'test', 'exam' );
	}

	/**
	 * Build the panel controls: a dropdown of published quizzes.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'qc_section',
			array( 'label' => __( 'Quiz', 'quiz-certify' ) )
		);

		// Reuse the same quiz list helper the Gutenberg block uses.
		$options = array( '' => __( '— Select a quiz —', 'quiz-certify' ) );
		foreach ( Quiz_Certify_Shortcode::get_quiz_options() as $opt ) {
			$options[ $opt['value'] ] = $opt['label'];
		}

		$this->add_control(
			'quiz_id',
			array(
				'label'   => __( 'Select quiz', 'quiz-certify' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $options,
				'default' => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Output the quiz via the shortcode, so all rendering stays in one place.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$quiz_id  = isset( $settings['quiz_id'] ) ? absint( $settings['quiz_id'] ) : 0;

		if ( ! $quiz_id ) {
			echo '<p>' . esc_html__( 'Select a quiz in the widget settings.', 'quiz-certify' ) . '</p>';
			return;
		}

		// do_shortcode runs the same validated, asset-loading, templated path as everywhere else.
		echo do_shortcode( sprintf( '[quiz_certify id="%d"]', $quiz_id ) );
	}
}

/**
 * A native Elementor widget for the quiz listing grid.
 */
class QC_Elementor_Quiz_List extends \Elementor\Widget_Base {

	public function get_name() {
		return 'quiz_certify_list';
	}

	public function get_title() {
		return __( 'Quiz List', 'quiz-certify' );
	}

	public function get_icon() {
		return 'eicon-posts-grid';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'quiz', 'quizzes', 'list', 'grid' );
	}

	protected function render() {
		echo do_shortcode( '[quiz_certify_list]' );
	}
}
