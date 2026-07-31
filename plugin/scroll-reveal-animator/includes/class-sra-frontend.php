<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRA_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_shortcode( 'scroll_reveal', array( __CLASS__, 'shortcode' ) );
	}

	public static function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}

		$opts = SRA_Settings::get();

		wp_enqueue_style(
			'sra-frontend',
			SRA_PLUGIN_URL . 'assets/css/sra-frontend.css',
			array(),
			SRA_VERSION
		);

		$duration = absint( $opts['duration'] );
		$distance = absint( $opts['distance'] );
		$easing   = in_array( $opts['easing'], SRA_Settings::allowed_easings(), true ) ? $opts['easing'] : 'ease-out';

		$inline_css = sprintf(
			':root{--sra-duration:%dms;--sra-distance:%dpx;--sra-easing:%s;}',
			$duration,
			$distance,
			$easing
		);
		wp_add_inline_style( 'sra-frontend', $inline_css );

		wp_enqueue_script(
			'sra-frontend',
			SRA_PLUGIN_URL . 'assets/js/sra-frontend.js',
			array(),
			SRA_VERSION,
			true
		);

		$selectors = array();
		if ( '' !== trim( $opts['auto_selectors'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $opts['auto_selectors'] );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$selectors[] = $line;
				}
			}
		}

		$config = array(
			'once'          => (bool) $opts['once'],
			'autoSelectors' => $selectors,
			'threshold'     => 0.15,
		);

		wp_add_inline_script(
			'sra-frontend',
			'window.SRA_CONFIG = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	public static function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'animation' => 'fade-up',
				'delay'     => 0,
				'tag'       => 'div',
			),
			$atts,
			'scroll_reveal'
		);

		$animation = in_array( $atts['animation'], SRA_Settings::allowed_animations(), true )
			? $atts['animation']
			: 'fade-up';

		$delay = absint( $atts['delay'] );
		$delay = min( $delay, 3000 );

		$allowed_tags = array( 'div', 'section', 'span', 'p', 'aside', 'article' );
		$tag          = in_array( strtolower( $atts['tag'] ), $allowed_tags, true ) ? strtolower( $atts['tag'] ) : 'div';

		return sprintf(
			'<%1$s class="sra-%2$s" data-sra-delay="%3$d">%4$s</%1$s>',
			esc_attr( $tag ),
			esc_attr( $animation ),
			$delay,
			do_shortcode( wp_kses_post( $content ) )
		);
	}
}
