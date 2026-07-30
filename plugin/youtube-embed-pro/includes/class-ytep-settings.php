<?php
/**
 * Settings → YouTube Embed Pro.
 *
 * Stores a list of channels as one option: an array of
 * array( 'label' => 'My Channel', 'id' => 'UCxxxxxxxxxxxxxxxxxxxxxx' ).
 *
 * Uses the Settings API, which means WordPress itself handles the nonce
 * check, the capability check on options.php, and saving — our job is only
 * to declare the option, sanitise it, and print the form fields.
 *
 * @package YouTube_Embed_Pro
 */

defined( 'ABSPATH' ) || exit;

class YTEP_Settings {

	const OPTION = 'ytep_channels';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function add_page() {
		// manage_options restricts the page to administrators.
		add_options_page(
			__( 'YouTube Embed Pro', 'ytep' ),
			__( 'YouTube Embed Pro', 'ytep' ),
			'manage_options',
			'ytep-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			'ytep_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_channels' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'ytep_channels_section',
			__( 'Channels', 'ytep' ),
			array( __CLASS__, 'render_section_intro' ),
			'ytep-settings'
		);

		add_settings_field(
			'ytep_channels_field',
			__( 'Saved channels', 'ytep' ),
			array( __CLASS__, 'render_field' ),
			'ytep-settings',
			'ytep_channels_section'
		);
	}

	/**
	 * Read the saved channels, always as a clean array.
	 *
	 * @return array[] Each item: array( 'label' => string, 'id' => string ).
	 */
	public static function get_channels() {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	/**
	 * Accept a channel ID or a channel URL and return the bare "UC…" ID.
	 * Delegates to the parser so there is a single source of truth for
	 * what a valid channel ID looks like.
	 *
	 * @param string $value Raw value.
	 * @return string '' when nothing valid was found.
	 */
	public static function extract_channel_id( $value ) {
		return YTEP_Parser::extract_channel_id( $value );
	}

	/**
	 * Sanitize the textarea submission into the stored array.
	 *
	 * The form posts one big textarea (name="ytep_channels_raw") because a
	 * plain textarea needs no JavaScript for add/remove rows and there is
	 * nothing dynamic to get wrong. Each line: Label | ID-or-URL.
	 *
	 * @param mixed $value Ignored; we read the raw textarea from the POST.
	 * @return array
	 */
	public static function sanitize_channels( $value ) {
		// The Settings API has already verified the nonce and capability
		// before this callback runs, so reading the POST field here is safe.
		$raw = isset( $_POST['ytep_channels_raw'] ) ? wp_unslash( $_POST['ytep_channels_raw'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$channels = array();
		$seen     = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts  = array_map( 'trim', explode( '|', $line, 2 ) );
			$label  = count( $parts ) === 2 ? $parts[0] : '';
			$id_raw = count( $parts ) === 2 ? $parts[1] : $parts[0];

			$id = self::extract_channel_id( $id_raw );

			if ( '' === $id ) {
				add_settings_error(
					self::OPTION,
					'ytep_bad_channel',
					sprintf(
						/* translators: %s: the rejected line. */
						__( 'Skipped "%s" — use a channel ID starting with UC, or a youtube.com/channel/UC… URL. Handles like @name cannot be embedded without the YouTube API.', 'ytep' ),
						esc_html( $line )
					)
				);
				continue;
			}

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$channels[] = array(
				'label' => sanitize_text_field( $label ? $label : $id ),
				'id'    => $id,
			);
		}

		return $channels;
	}

	public static function render_section_intro() {
		echo '<p>' . esc_html__( 'Saved channels appear in the block as a "Channel uploads" choice, which embeds the channel’s latest videos as a playlist.', 'ytep' ) . '</p>';
	}

	public static function render_field() {
		$lines = array();
		foreach ( self::get_channels() as $channel ) {
			$lines[] = $channel['label'] . ' | ' . $channel['id'];
		}
		?>
		<textarea name="ytep_channels_raw" rows="6" cols="60" class="large-text code" placeholder="<?php echo esc_attr__( 'My Channel | UCxxxxxxxxxxxxxxxxxxxxxx', 'ytep' ); ?>"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One channel per line, as: Label | Channel ID or channel URL. Find the ID on the channel page under “Share channel → Copy channel ID”.', 'ytep' ); ?>
		</p>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				// Prints the nonce and option-group fields the API will verify.
				settings_fields( 'ytep_settings' );
				do_settings_sections( 'ytep-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
