<?php
/**
 * Renders the floating chat widget and loads its assets — only on the
 * public site, and only when the widget is enabled and a key exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSC_Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ) );
	}

	private function should_show() {
		return GSC_Settings::get( 'enabled' )
			&& '' !== GSC_Settings::get( 'api_key' )
			&& ! is_admin();
	}

	public function enqueue() {
		if ( ! $this->should_show() ) {
			return; // don't ship any bytes on sites/pages that don't need them
		}

		wp_enqueue_style( 'gsc-chatbot', GSC_PLUGIN_URL . 'assets/chatbot.css', array(), GSC_VERSION );
		wp_enqueue_script( 'gsc-chatbot', GSC_PLUGIN_URL . 'assets/chatbot.js', array(), GSC_VERSION, true );

		// Pass server data to JS. Note what is NOT here: the API key.
		// The browser only ever talks to our own REST endpoint.
		wp_localize_script(
			'gsc-chatbot',
			'gscConfig',
			array(
				'endpoint' => esc_url_raw( rest_url( 'gsc/v1/chat' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'botName'  => GSC_Settings::get( 'bot_name' ),
			)
		);

		// Inject the admin-chosen accent color as a CSS variable.
		$accent = GSC_Settings::get( 'accent_color' );
		wp_add_inline_style( 'gsc-chatbot', ':root{--gsc-accent:' . esc_attr( $accent ) . ';}' );
	}

	public function render_widget() {
		if ( ! $this->should_show() ) {
			return;
		}
		$bot_name = GSC_Settings::get( 'bot_name' );
		?>
		<div id="gsc-root">
			<button id="gsc-toggle" type="button" aria-label="<?php esc_attr_e( 'Open chat', 'groq-site-chatbot' ); ?>" aria-expanded="false">
				<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<path d="M21 12a8 8 0 0 1-8 8H5l-2 2V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8z"/>
				</svg>
			</button>
			<div id="gsc-panel" hidden>
				<div id="gsc-header">
					<span id="gsc-title"><?php echo esc_html( $bot_name ); ?></span>
					<button id="gsc-close" type="button" aria-label="<?php esc_attr_e( 'Close chat', 'groq-site-chatbot' ); ?>">&times;</button>
				</div>
				<div id="gsc-messages" role="log" aria-live="polite"></div>
				<form id="gsc-form" autocomplete="off">
					<label for="gsc-input" class="screen-reader-text"><?php esc_html_e( 'Your question', 'groq-site-chatbot' ); ?></label>
					<input id="gsc-input" type="text" maxlength="1000"
						placeholder="<?php esc_attr_e( 'Ask a question…', 'groq-site-chatbot' ); ?>" />
					<button id="gsc-send" type="submit" aria-label="<?php esc_attr_e( 'Send', 'groq-site-chatbot' ); ?>">➤</button>
				</form>
			</div>
		</div>
		<?php
	}
}
