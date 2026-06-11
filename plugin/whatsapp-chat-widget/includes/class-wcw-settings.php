<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCW_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter(
			'plugin_action_links_whatsapp-chat-widget/whatsapp-chat-widget.php',
			array( $this, 'add_settings_link' )
		);
	}

	public function add_menu_page() {
		add_options_page(
			__( 'WhatsApp Chat Widget', 'wa-chat-widget' ),
			__( 'WhatsApp Chat', 'wa-chat-widget' ),
			'manage_options',
			'wcw-settings',
			array( $this, 'render_page' )
		);
	}

	public function add_settings_link( $links ) {
		$url = admin_url( 'options-general.php?page=wcw-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wa-chat-widget' ) . '</a>' );
		return $links;
	}

	public function register_settings() {
		register_setting(
			'wcw_settings_group',
			WCW_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$clean    = array();
		$existing = wcw_get_settings();

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$clean['enabled']        = empty( $input['enabled'] ) ? 0 : 1;
		$clean['show_on_mobile'] = empty( $input['show_on_mobile'] ) ? 0 : 1;

		$phone = isset( $input['phone'] ) ? preg_replace( '/\D/', '', $input['phone'] ) : '';
		if ( '' !== $phone && ( strlen( $phone ) < 7 || strlen( $phone ) > 15 ) ) {
			add_settings_error(
				'wcw_settings_group',
				'wcw_invalid_phone',
				__( 'Phone number must be 7–15 digits in international format (e.g. 919876543210).', 'wa-chat-widget' )
			);
			$phone = $existing['phone'];
		}
		$clean['phone'] = $phone;

		$clean['default_message'] = isset( $input['default_message'] ) ? sanitize_textarea_field( $input['default_message'] ) : '';
		$clean['agent_name']      = isset( $input['agent_name'] ) ? sanitize_text_field( $input['agent_name'] ) : '';
		$clean['agent_status']    = isset( $input['agent_status'] ) ? sanitize_text_field( $input['agent_status'] ) : '';
		$clean['welcome_text']    = isset( $input['welcome_text'] ) ? sanitize_textarea_field( $input['welcome_text'] ) : '';

		$clean['position'] = ( isset( $input['position'] ) && 'left' === $input['position'] ) ? 'left' : 'right';

		return $clean;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = wcw_get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WhatsApp Chat Widget', 'wa-chat-widget' ); ?></h1>

			<?php settings_errors( 'wcw_settings_group' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wcw_settings_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable widget', 'wa-chat-widget' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> />
								<?php esc_html_e( 'Show the chat button on the site', 'wa-chat-widget' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wcw_phone"><?php esc_html_e( 'WhatsApp number', 'wa-chat-widget' ); ?></label>
						</th>
						<td>
							<input type="text" id="wcw_phone" class="regular-text" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[phone]" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="919876543210" inputmode="numeric" />
							<p class="description"><?php esc_html_e( 'International format, digits only — country code + number, no + sign or spaces.', 'wa-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wcw_default_message"><?php esc_html_e( 'Pre-filled message', 'wa-chat-widget' ); ?></label>
						</th>
						<td>
							<textarea id="wcw_default_message" class="large-text" rows="2" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[default_message]"><?php echo esc_textarea( $s['default_message'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Used when the visitor clicks Send without typing anything.', 'wa-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wcw_agent_name"><?php esc_html_e( 'Agent name', 'wa-chat-widget' ); ?></label>
						</th>
						<td>
							<input type="text" id="wcw_agent_name" class="regular-text" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[agent_name]" value="<?php echo esc_attr( $s['agent_name'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wcw_agent_status"><?php esc_html_e( 'Status line', 'wa-chat-widget' ); ?></label>
						</th>
						<td>
							<input type="text" id="wcw_agent_status" class="regular-text" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[agent_status]" value="<?php echo esc_attr( $s['agent_status'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wcw_welcome_text"><?php esc_html_e( 'Welcome bubble', 'wa-chat-widget' ); ?></label>
						</th>
						<td>
							<textarea id="wcw_welcome_text" class="large-text" rows="2" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[welcome_text]"><?php echo esc_textarea( $s['welcome_text'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown as the first message inside the chat box.', 'wa-chat-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Position', 'wa-chat-widget' ); ?></th>
						<td>
							<label style="margin-right:16px;">
								<input type="radio" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[position]" value="right" <?php checked( $s['position'], 'right' ); ?> />
								<?php esc_html_e( 'Bottom right', 'wa-chat-widget' ); ?>
							</label>
							<label>
								<input type="radio" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[position]" value="left" <?php checked( $s['position'], 'left' ); ?> />
								<?php esc_html_e( 'Bottom left', 'wa-chat-widget' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mobile', 'wa-chat-widget' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( WCW_OPTION_KEY ); ?>[show_on_mobile]" value="1" <?php checked( $s['show_on_mobile'], 1 ); ?> />
								<?php esc_html_e( 'Show on mobile devices', 'wa-chat-widget' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
