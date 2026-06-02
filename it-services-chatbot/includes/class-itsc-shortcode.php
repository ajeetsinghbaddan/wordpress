<?php
defined( 'ABSPATH' ) || exit;

class ITSC_Shortcode {

    public static function init() {
        add_shortcode( 'it_chatbot', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'wp_footer',          [ __CLASS__, 'inject_floating_widget' ] );
    }

    /**
     * Always enqueue when the floating widget is active, or when the
     * shortcode is present on the current page.
     */
    public static function enqueue() {
        global $post;

        $floating_on = (bool) get_option( 'itsc_floating_widget', 1 );
        $has_shortcode = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'it_chatbot' );

        if ( ! $floating_on && ! $has_shortcode ) {
            return;
        }

        wp_enqueue_style(
            'itsc-chatbot',
            ITSC_PLUGIN_URL . 'public/css/chatbot.css',
            [],
            ITSC_VERSION
        );

        wp_enqueue_script(
            'itsc-chatbot',
            ITSC_PLUGIN_URL . 'public/js/chatbot.js',
            [ 'jquery' ],
            ITSC_VERSION,
            true
        );

        wp_localize_script( 'itsc-chatbot', 'ITSC', [
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'itsc_public_nonce' ),
            'flows'    => ITSC_DB::get_chatbot_config(),
            'floating' => $floating_on ? '1' : '0',
            'welcome'  => get_option( 'itsc_welcome_message', 'Hi there! Welcome. Which IT service are you interested in today?' ),
        ] );
    }

    /**
     * Print the floating launcher + panel HTML in wp_footer.
     * Only rendered when the floating widget setting is on.
     */
    public static function inject_floating_widget() {
        if ( ! get_option( 'itsc_floating_widget', 1 ) ) {
            return;
        }
        ?>
        <div id="itsc-floating-wrap" class="itsc-floating-wrap" aria-label="IT Services Chat">
            <!-- Slide-up panel -->
            <div id="itsc-panel" class="itsc-panel" role="dialog" aria-modal="true" aria-label="IT Services Chatbot" hidden>
                <div class="itsc-panel-header">
                    <div class="itsc-panel-header-info">
                        <span class="itsc-panel-avatar" aria-hidden="true">🤖</span>
                        <div>
                            <strong>IT Services</strong>
                            <span class="itsc-online-dot" aria-hidden="true"></span>
                            <small>Online</small>
                        </div>
                    </div>
                    <button class="itsc-panel-close" id="itsc-panel-close" aria-label="Close chat">&times;</button>
                </div>
                <div class="itsc-panel-body">
                    <?php include ITSC_PLUGIN_DIR . 'templates/chatbot.php'; ?>
                </div>
            </div>

            <!-- Launcher button -->
            <button class="itsc-launcher" id="itsc-launcher" aria-expanded="false" aria-controls="itsc-panel" aria-label="Open IT Services chat">
                <span class="itsc-launcher-icon itsc-icon-chat" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </span>
                <span class="itsc-launcher-icon itsc-icon-close" aria-hidden="true" style="display:none;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </span>
                <span class="itsc-launcher-badge" id="itsc-launcher-badge" aria-label="1 new message">1</span>
            </button>
        </div>
        <?php
    }

    /** Shortcode — inline embed (still works independently). */
    public static function render( $atts ) {
        ob_start();
        include ITSC_PLUGIN_DIR . 'templates/chatbot.php';
        return ob_get_clean();
    }
}
