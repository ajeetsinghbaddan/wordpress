<?php
defined( 'ABSPATH' ) || exit;

class ITSC_Admin {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menus' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function add_menus() {
        add_menu_page(
            'IT Chatbot',
            'IT Chatbot',
            'manage_options',
            'itsc',
            [ __CLASS__, 'page_flows' ],
            'dashicons-format-chat',
            56
        );

        add_submenu_page( 'itsc', 'Flows & Questions', 'Flows & Questions', 'manage_options', 'itsc',            [ __CLASS__, 'page_flows' ] );
        add_submenu_page( 'itsc', 'Leads',             'Leads',             'manage_options', 'itsc-leads',      [ __CLASS__, 'page_leads' ] );
        add_submenu_page( 'itsc', 'Settings',          'Settings',          'manage_options', 'itsc-settings',   [ __CLASS__, 'page_settings' ] );
    }

    public static function enqueue( $hook ) {
        if ( strpos( $hook, 'itsc' ) === false ) {
            return;
        }
        wp_enqueue_style(  'itsc-admin', ITSC_PLUGIN_URL . 'admin/admin.css', [],           ITSC_VERSION );
        wp_enqueue_script( 'itsc-admin', ITSC_PLUGIN_URL . 'admin/admin.js',  [ 'jquery' ], ITSC_VERSION, true );
        wp_localize_script( 'itsc-admin', 'ITSC_Admin', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'itsc_admin_nonce' ),
            'flows'   => ITSC_DB::get_chatbot_config(),
        ] );
    }

    /* ------------------------------------------------------------------ */
    /* Page: Flows & Questions                                              */
    /* ------------------------------------------------------------------ */

    public static function page_flows() {
        $flows = ITSC_DB::get_flows();
        ?>
        <div class="wrap itsc-wrap">
            <h1>Flows &amp; Questions
                <button class="button button-primary itsc-btn-add-flow" data-id="0">+ Add Flow</button>
            </h1>

            <div class="itsc-layout">
                <!-- Sidebar: flow list -->
                <div class="itsc-sidebar">
                    <ul id="itsc-flow-list">
                        <?php foreach ( $flows as $flow ) : ?>
                        <li class="itsc-flow-item" data-id="<?php echo esc_attr( $flow->id ); ?>">
                            <span class="itsc-flow-name"><?php echo esc_html( $flow->title ); ?></span>
                            <span class="itsc-flow-actions">
                                <a href="#" class="itsc-edit-flow" data-id="<?php echo esc_attr( $flow->id ); ?>" data-title="<?php echo esc_attr( $flow->title ); ?>">Edit</a>
                                <a href="#" class="itsc-delete-flow itsc-danger" data-id="<?php echo esc_attr( $flow->id ); ?>">Delete</a>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Main: question editor -->
                <div class="itsc-main">
                    <div id="itsc-editor-placeholder" class="itsc-placeholder">
                        <p>← Select a flow to edit its questions</p>
                    </div>
                    <div id="itsc-editor" style="display:none;">
                        <div class="itsc-editor-header">
                            <h2 id="itsc-editor-title">Questions</h2>
                            <button class="button itsc-btn-add-question">+ Add Question</button>
                        </div>
                        <div id="itsc-questions-container"></div>
                    </div>
                </div>
            </div>

            <!-- Flow modal -->
            <div id="itsc-modal-flow" class="itsc-modal" style="display:none;">
                <div class="itsc-modal-box">
                    <h2 id="itsc-modal-flow-heading">Add Flow</h2>
                    <input type="hidden" id="itsc-modal-flow-id" value="0">
                    <label>Flow Title
                        <input type="text" id="itsc-modal-flow-title" class="regular-text" placeholder="e.g. Web development">
                    </label>
                    <div class="itsc-modal-actions">
                        <button class="button button-primary" id="itsc-modal-flow-save">Save</button>
                        <button class="button itsc-modal-close">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- Question modal -->
            <div id="itsc-modal-question" class="itsc-modal" style="display:none;">
                <div class="itsc-modal-box itsc-modal-wide">
                    <h2 id="itsc-modal-q-heading">Add Question</h2>
                    <input type="hidden" id="itsc-modal-q-id"      value="0">
                    <input type="hidden" id="itsc-modal-q-flow-id" value="0">
                    <label>Question Text
                        <textarea id="itsc-modal-q-text" rows="3" class="large-text"></textarea>
                    </label>
                    <label>Step Number <small>(determines order in flow)</small>
                        <input type="number" id="itsc-modal-q-step" min="1" value="1" style="width:80px;">
                    </label>
                    <hr>
                    <h3>Answer Options <button class="button itsc-btn-add-option" style="margin-left:8px;">+ Add Option</button></h3>
                    <ul id="itsc-modal-options-list"></ul>
                    <div class="itsc-modal-actions">
                        <button class="button button-primary" id="itsc-modal-q-save">Save Question</button>
                        <button class="button itsc-modal-close">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Page: Leads                                                          */
    /* ------------------------------------------------------------------ */

    public static function page_leads() {
        $page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $result  = ITSC_DB::get_leads( 20, $page );
        $leads   = $result['rows'];
        $total   = $result['total'];
        $pages   = ceil( $total / 20 );
        ?>
        <div class="wrap itsc-wrap">
            <h1>Leads <span class="itsc-badge"><?php echo esc_html( $total ); ?></span></h1>

            <?php if ( empty( $leads ) ) : ?>
                <p>No leads yet. Place the <code>[it_chatbot]</code> shortcode on a page to start collecting.</p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped itsc-leads-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Phone</th><th>Answers</th><th>Date</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $leads as $lead ) :
                        $answers = json_decode( $lead->answers, true );
                    ?>
                    <tr id="itsc-lead-<?php echo esc_attr( $lead->id ); ?>">
                        <td><?php echo esc_html( $lead->full_name ); ?></td>
                        <td><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td>
                        <td><?php echo esc_html( $lead->phone ); ?></td>
                        <td>
                            <?php if ( $answers ) :
                                foreach ( $answers as $q => $a ) :
                            ?>
                                <div class="itsc-answer-row"><strong><?php echo esc_html( $q ); ?>:</strong> <?php echo esc_html( $a ); ?></div>
                            <?php   endforeach; endif; ?>
                            <?php if ( $lead->message ) : ?>
                                <div class="itsc-answer-row"><strong>Note:</strong> <?php echo esc_html( $lead->message ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $lead->created_at ) ) ); ?></td>
                        <td>
                            <a href="#" class="itsc-delete-lead itsc-danger" data-id="<?php echo esc_attr( $lead->id ); ?>">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php echo paginate_links( [
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $pages,
                    ] ); ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Page: Settings                                                       */
    /* ------------------------------------------------------------------ */

    public static function page_settings() {
        if ( isset( $_POST['itsc_save_settings'] ) ) {
            check_admin_referer( 'itsc_settings_save' );
            update_option( 'itsc_welcome_message',    sanitize_textarea_field( wp_unslash( $_POST['itsc_welcome_message']    ?? '' ) ) );
            update_option( 'itsc_notification_email', sanitize_email(          wp_unslash( $_POST['itsc_notification_email'] ?? '' ) ) );
            update_option( 'itsc_floating_widget',    isset( $_POST['itsc_floating_widget'] ) ? 1 : 0 );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
        $welcome  = get_option( 'itsc_welcome_message', 'Hi there! Welcome. Which IT service are you interested in today?' );
        $notify   = get_option( 'itsc_notification_email', get_option( 'admin_email' ) );
        $floating = (bool) get_option( 'itsc_floating_widget', 1 );
        ?>
        <div class="wrap itsc-wrap">
            <h1>IT Chatbot Settings</h1>
            <form method="post">
                <?php wp_nonce_field( 'itsc_settings_save' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="itsc_floating_widget">Floating Widget</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="itsc_floating_widget" id="itsc_floating_widget" value="1" <?php checked( $floating ); ?>>
                                Show chatbot as a floating launcher on every page (bottom-right corner)
                            </label>
                            <p class="description">When disabled, use the <code>[it_chatbot]</code> shortcode to embed it manually on specific pages.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="itsc_welcome_message">Welcome Message</label></th>
                        <td><textarea name="itsc_welcome_message" id="itsc_welcome_message" rows="3" class="large-text"><?php echo esc_textarea( $welcome ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="itsc_notification_email">Lead Notification Email</label></th>
                        <td><input type="email" name="itsc_notification_email" id="itsc_notification_email" value="<?php echo esc_attr( $notify ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Shortcode</th>
                        <td><code>[it_chatbot]</code> — paste this on any page or post for an inline embed.</td>
                    </tr>
                </table>
                <p><input type="submit" name="itsc_save_settings" class="button button-primary" value="Save Settings"></p>
            </form>
        </div>
        <?php
    }
}
