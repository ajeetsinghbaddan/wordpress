<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCW_Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ) );
	}

	private function should_load() {
		if ( is_admin() ) {
			return false;
		}

		$s = wcw_get_settings();

		if ( empty( $s['enabled'] ) || empty( $s['phone'] ) ) {
			return false;
		}

		if ( empty( $s['show_on_mobile'] ) && wp_is_mobile() ) {
			return false;
		}

		return true;
	}

	public function enqueue_assets() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'wcw-widget',
			WCW_PLUGIN_URL . 'assets/css/widget.css',
			array(),
			WCW_VERSION
		);

		wp_enqueue_script(
			'wcw-widget',
			WCW_PLUGIN_URL . 'assets/js/widget.js',
			array(),
			WCW_VERSION,
			true
		);

		$s = wcw_get_settings();

		wp_localize_script(
			'wcw-widget',
			'wcwData',
			array(
				'phone'          => preg_replace( '/\D/', '', $s['phone'] ),
				'defaultMessage' => $s['default_message'],
			)
		);
	}

	public function render_widget() {
		if ( ! $this->should_load() ) {
			return;
		}

		$s       = wcw_get_settings();
		$pos     = ( 'left' === $s['position'] ) ? 'wcw-left' : 'wcw-right';
		$initial = mb_substr( trim( $s['agent_name'] ), 0, 1 );
		?>
		<div id="wcw-root" class="<?php echo esc_attr( $pos ); ?>">

			<div id="wcw-box" class="wcw-box" role="dialog" aria-label="<?php esc_attr_e( 'WhatsApp chat', 'wa-chat-widget' ); ?>" aria-hidden="true" hidden>
				<div class="wcw-header">
					<span class="wcw-avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
					<div class="wcw-header-text">
						<span class="wcw-name"><?php echo esc_html( $s['agent_name'] ); ?></span>
						<span class="wcw-status"><?php echo esc_html( $s['agent_status'] ); ?></span>
					</div>
					<button type="button" id="wcw-close" class="wcw-close" aria-label="<?php esc_attr_e( 'Close chat', 'wa-chat-widget' ); ?>">&times;</button>
				</div>

				<div class="wcw-body">
					<div class="wcw-bubble">
						<?php echo esc_html( $s['welcome_text'] ); ?>
						<span class="wcw-time"><?php echo esc_html( date_i18n( get_option( 'time_format' ) ) ); ?></span>
					</div>
				</div>

				<div class="wcw-footer">
					<input type="text" id="wcw-input" class="wcw-input" placeholder="<?php esc_attr_e( 'Type a message…', 'wa-chat-widget' ); ?>" maxlength="500" autocomplete="off" />
					<button type="button" id="wcw-send" class="wcw-send" aria-label="<?php esc_attr_e( 'Send on WhatsApp', 'wa-chat-widget' ); ?>">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2z"/></svg>
					</button>
				</div>
			</div>

			<button type="button" id="wcw-toggle" class="wcw-toggle" aria-label="<?php esc_attr_e( 'Open WhatsApp chat', 'wa-chat-widget' ); ?>" aria-expanded="false">
				<svg class="wcw-icon-chat" viewBox="0 0 32 32" width="32" height="32" fill="currentColor" aria-hidden="true"><path d="M16 3C9.4 3 4 8.27 4 14.77c0 2.3.68 4.45 1.86 6.27L4.2 27.8l6.97-1.6a12.3 12.3 0 0 0 4.83.97c6.6 0 12-5.27 12-11.77S22.6 3 16 3zm5.94 16.36c-.25.7-1.47 1.34-2.03 1.39-.55.05-1.06.25-3.58-.74-3.03-1.2-4.95-4.28-5.1-4.48-.15-.2-1.22-1.62-1.22-3.1 0-1.47.77-2.19 1.05-2.49.27-.3.6-.37.8-.37l.57.01c.18.01.43-.07.67.51.25.6.84 2.06.91 2.21.07.15.12.33.02.53-.1.2-.15.32-.3.5l-.45.52c-.15.15-.3.31-.13.61.17.3.76 1.26 1.64 2.04 1.13 1 2.07 1.32 2.37 1.47.3.15.47.12.65-.07.17-.2.74-.87.94-1.17.2-.3.4-.25.67-.15.27.1 1.74.82 2.04.97.3.15.5.22.57.35.07.12.07.72-.18 1.46z"/></svg>
				<span class="wcw-icon-close" aria-hidden="true">&times;</span>
			</button>

		</div>
		<?php
	}
}
