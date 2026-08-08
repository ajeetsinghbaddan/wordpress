<?php
/**
 * Admin settings: API key, models, widget options.
 *
 * Uses the WordPress Settings API, which gives us nonce protection,
 * capability checks and sanitization hooks for free.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSC_Settings {

	const OPTION_KEY = 'gsc_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Central accessor with safe defaults, so the rest of the plugin
	 * never has to guess whether an option exists.
	 */
	public static function get( $key ) {
		$defaults = array(
			'api_key'          => '',
			'site_model'       => 'llama-3.3-70b-versatile',
			'web_model'        => 'groq/compound-mini',
			'bot_name'         => 'Site Assistant',
			'accent_color'     => '#1d4ed8',
			'enabled'          => 1,
			'rate_limit'       => 10,   // requests per minute per visitor
			'max_context_docs' => 4,    // how many site posts to feed the model
		);
		$settings = wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public function add_menu() {
		// 'manage_options' = admins only. Editors/authors never see the API key.
		add_options_page(
			__( 'Groq Site Chatbot', 'groq-site-chatbot' ),
			__( 'Groq Chatbot', 'groq-site-chatbot' ),
			'manage_options',
			'gsc-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'gsc_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Every field is sanitized before it touches the database.
	 * Note the API-key trick: if the masked placeholder comes back
	 * unchanged, we keep the previously stored key instead of saving
	 * the mask string.
	 */
	public function sanitize( $input ) {
		$old   = get_option( self::OPTION_KEY, array() );
		$clean = array();

		$submitted_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		if ( '' === $submitted_key || preg_match( '/^\*+/', $submitted_key ) ) {
			$clean['api_key'] = isset( $old['api_key'] ) ? $old['api_key'] : '';
		} else {
			$clean['api_key'] = $submitted_key;
		}

		$clean['site_model']       = sanitize_text_field( $input['site_model'] ?? 'llama-3.3-70b-versatile' );
		$clean['web_model']        = sanitize_text_field( $input['web_model'] ?? 'groq/compound-mini' );
		$clean['bot_name']         = sanitize_text_field( $input['bot_name'] ?? 'Site Assistant' );
		$clean['accent_color']     = sanitize_hex_color( $input['accent_color'] ?? '#1d4ed8' ) ?: '#1d4ed8';
		$clean['enabled']          = empty( $input['enabled'] ) ? 0 : 1;
		$clean['rate_limit']       = min( 60, max( 1, absint( $input['rate_limit'] ?? 10 ) ) );
		$clean['max_context_docs'] = min( 8, max( 1, absint( $input['max_context_docs'] ?? 4 ) ) );

		return $clean;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_key = self::get( 'api_key' );
		// Never echo the real key back into the form. Show a mask so the
		// key can't be read from the admin screen or browser dev tools.
		$masked = $api_key ? str_repeat( '*', 12 ) . substr( $api_key, -4 ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Groq Site Chatbot', 'groq-site-chatbot' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gsc_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gsc_api_key"><?php esc_html_e( 'Groq API key', 'groq-site-chatbot' ); ?></label></th>
						<td>
							<input type="password" id="gsc_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
								value="<?php echo esc_attr( $masked ); ?>" class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Stored server-side only; never sent to visitors. Leave the masked value untouched to keep the current key.', 'groq-site-chatbot' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_site_model"><?php esc_html_e( 'Model for site answers', 'groq-site-chatbot' ); ?></label></th>
						<td><input type="text" id="gsc_site_model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[site_model]"
							value="<?php echo esc_attr( self::get( 'site_model' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_web_model"><?php esc_html_e( 'Fallback web-search model', 'groq-site-chatbot' ); ?></label></th>
						<td>
							<input type="text" id="gsc_web_model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[web_model]"
								value="<?php echo esc_attr( self::get( 'web_model' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'A Groq model with built-in web search (e.g. groq/compound-mini).', 'groq-site-chatbot' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_bot_name"><?php esc_html_e( 'Bot name', 'groq-site-chatbot' ); ?></label></th>
						<td><input type="text" id="gsc_bot_name" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bot_name]"
							value="<?php echo esc_attr( self::get( 'bot_name' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_accent"><?php esc_html_e( 'Accent color', 'groq-site-chatbot' ); ?></label></th>
						<td><input type="color" id="gsc_accent" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[accent_color]"
							value="<?php echo esc_attr( self::get( 'accent_color' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_rate"><?php esc_html_e( 'Rate limit (messages / minute / visitor)', 'groq-site-chatbot' ); ?></label></th>
						<td><input type="number" min="1" max="60" id="gsc_rate" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate_limit]"
							value="<?php echo esc_attr( self::get( 'rate_limit' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_docs"><?php esc_html_e( 'Site pages used as context', 'groq-site-chatbot' ); ?></label></th>
						<td><input type="number" min="1" max="8" id="gsc_docs" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_context_docs]"
							value="<?php echo esc_attr( self::get( 'max_context_docs' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable widget', 'groq-site-chatbot' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1"
							<?php checked( self::get( 'enabled' ), 1 ); ?> /> <?php esc_html_e( 'Show the chat bubble on the site', 'groq-site-chatbot' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
