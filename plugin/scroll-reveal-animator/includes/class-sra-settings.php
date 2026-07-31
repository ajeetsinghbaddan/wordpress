<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRA_Settings {

	const OPTION_KEY = 'sra_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function defaults() {
		return array(
			'duration'       => 700,
			'distance'       => 40,
			'easing'         => 'ease-out',
			'once'           => 1,
			'auto_selectors' => '',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function allowed_easings() {
		return array( 'ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear' );
	}

	public static function allowed_animations() {
		return array( 'fade-in', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in', 'zoom-out' );
	}

	public static function add_menu() {
		add_options_page(
			__( 'Scroll Reveal Animator', 'scroll-reveal-animator' ),
			__( 'Scroll Reveal', 'scroll-reveal-animator' ),
			'manage_options',
			'scroll-reveal-animator',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'sra_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$clean    = array();

		$duration          = isset( $input['duration'] ) ? absint( $input['duration'] ) : $defaults['duration'];
		$clean['duration'] = min( max( $duration, 100 ), 5000 );

		$distance          = isset( $input['distance'] ) ? absint( $input['distance'] ) : $defaults['distance'];
		$clean['distance'] = min( max( $distance, 0 ), 500 );

		$easing          = isset( $input['easing'] ) ? sanitize_text_field( $input['easing'] ) : $defaults['easing'];
		$clean['easing'] = in_array( $easing, self::allowed_easings(), true ) ? $easing : $defaults['easing'];

		$clean['once'] = empty( $input['once'] ) ? 0 : 1;

		$selectors = isset( $input['auto_selectors'] ) ? sanitize_textarea_field( $input['auto_selectors'] ) : '';
		$selectors = preg_replace( '/[^a-zA-Z0-9\s\.\#\-\_\>\:\[\]\=\"\'\,\*\(\)\n]/', '', $selectors );
		$clean['auto_selectors'] = $selectors;

		return $clean;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'scroll-reveal-animator' ) );
		}

		$opts = self::get();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Scroll Reveal Animator', 'scroll-reveal-animator' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'sra_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sra_duration"><?php esc_html_e( 'Animation duration (ms)', 'scroll-reveal-animator' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[duration]" id="sra_duration" type="number" min="100" max="5000" step="50" value="<?php echo esc_attr( $opts['duration'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'How long each reveal takes. 100–5000.', 'scroll-reveal-animator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sra_distance"><?php esc_html_e( 'Travel distance (px)', 'scroll-reveal-animator' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[distance]" id="sra_distance" type="number" min="0" max="500" step="5" value="<?php echo esc_attr( $opts['distance'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'How far elements slide during fade-up / down / left / right.', 'scroll-reveal-animator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sra_easing"><?php esc_html_e( 'Easing', 'scroll-reveal-animator' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[easing]" id="sra_easing">
								<?php foreach ( self::allowed_easings() as $easing ) : ?>
									<option value="<?php echo esc_attr( $easing ); ?>" <?php selected( $opts['easing'], $easing ); ?>><?php echo esc_html( $easing ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Animate once', 'scroll-reveal-animator' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[once]" type="checkbox" value="1" <?php checked( $opts['once'], 1 ); ?>>
								<?php esc_html_e( 'Reveal each element only the first time it enters the viewport.', 'scroll-reveal-animator' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sra_auto_selectors"><?php esc_html_e( 'Auto-animate selectors', 'scroll-reveal-animator' ); ?></label>
						</th>
						<td>
							<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_selectors]" id="sra_auto_selectors" rows="4" class="large-text code" placeholder=".entry-content h2&#10;.elementor-widget-image"><?php echo esc_textarea( $opts['auto_selectors'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One CSS selector per line. Matching elements get a fade-up reveal automatically — no editing content required. Leave empty to disable.', 'scroll-reveal-animator' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'How to use', 'scroll-reveal-animator' ); ?></h2>
			<p><?php esc_html_e( 'Gutenberg: select any block and use the "Scroll Reveal" panel in the sidebar.', 'scroll-reveal-animator' ); ?></p>
			<p><?php esc_html_e( 'Elementor / any builder: add a CSS class to any element, e.g. sra-fade-up, sra-fade-left, sra-zoom-in. Optional delay: add data attribute via class sra-delay-200 (100–1000, steps of 100).', 'scroll-reveal-animator' ); ?></p>
			<p><?php esc_html_e( 'Shortcode: [scroll_reveal animation="fade-up" delay="200"]Your content[/scroll_reveal]', 'scroll-reveal-animator' ); ?></p>
		</div>
		<?php
	}
}
