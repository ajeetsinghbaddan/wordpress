<?php
defined( 'ABSPATH' ) || exit;

class ITSC_Ajax {

    public static function init() {
        // Public (no login required)
        add_action( 'wp_ajax_nopriv_itsc_submit_lead', [ __CLASS__, 'submit_lead' ] );
        add_action( 'wp_ajax_itsc_submit_lead',        [ __CLASS__, 'submit_lead' ] );

        // Admin-only endpoints (manage flows/questions/options/leads)
        $admin_actions = [
            'itsc_save_flow',     'itsc_delete_flow',
            'itsc_save_question', 'itsc_delete_question',
            'itsc_save_option',   'itsc_delete_option',
            'itsc_reorder',       'itsc_delete_lead',
            'itsc_get_flow_data',
        ];
        foreach ( $admin_actions as $action ) {
            add_action( "wp_ajax_{$action}", [ __CLASS__, str_replace( 'itsc_', '', $action ) ] );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helper: send JSON and die                                            */
    /* ------------------------------------------------------------------ */

    private static function ok( $data = [] ) {
        wp_send_json_success( $data );
    }

    private static function err( $msg, $code = 400 ) {
        wp_send_json_error( [ 'message' => $msg ], $code );
    }

    private static function verify_admin_nonce( $action = 'itsc_admin_nonce' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            self::err( 'Forbidden', 403 );
        }
        if ( ! check_ajax_referer( $action, 'nonce', false ) ) {
            self::err( 'Invalid nonce', 403 );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Public: submit lead                                                  */
    /* ------------------------------------------------------------------ */

    public static function submit_lead() {
        check_ajax_referer( 'itsc_public_nonce', 'nonce' );

        $name    = sanitize_text_field( wp_unslash( $_POST['full_name']   ?? '' ) );
        $email   = sanitize_email(      wp_unslash( $_POST['email']       ?? '' ) );
        $phone   = sanitize_text_field( wp_unslash( $_POST['phone']       ?? '' ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $raw_answers = wp_unslash( $_POST['answers'] ?? '' );

        // Validate required fields
        if ( empty( $name ) ) {
            self::err( 'Full name is required.' );
        }
        if ( ! is_email( $email ) ) {
            self::err( 'A valid email address is required.' );
        }
        if ( empty( $phone ) || ! preg_match( '/^[+\d\s\-().]{6,20}$/', $phone ) ) {
            self::err( 'A valid phone number is required.' );
        }

        // Sanitise answers JSON
        $answers_json = '';
        if ( ! empty( $raw_answers ) ) {
            $decoded = json_decode( stripslashes( $raw_answers ), true );
            if ( is_array( $decoded ) ) {
                // Strip tags from every value
                array_walk_recursive( $decoded, function( &$v ) {
                    $v = sanitize_text_field( $v );
                } );
                $answers_json = wp_json_encode( $decoded );
            }
        }

        global $wpdb;
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}itsc_leads",
            [
                'full_name'  => $name,
                'email'      => $email,
                'phone'      => $phone,
                'message'    => $message,
                'answers'    => $answers_json,
                'ip_address' => self::get_ip(),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            self::err( 'Could not save your request. Please try again.' );
        }

        // Optional admin notification
        $admin_email = get_option( 'admin_email' );
        $subject     = sprintf( '[%s] New IT Services Lead: %s', get_bloginfo( 'name' ), $name );
        $body        = "Name:  {$name}\nEmail: {$email}\nPhone: {$phone}\nMessage: {$message}\n\nAnswers:\n" . print_r( json_decode( $answers_json, true ), true );
        wp_mail( $admin_email, $subject, $body );

        self::ok( [ 'message' => "Thanks {$name}, our team will contact you at {$email} shortly." ] );
    }

    /* ------------------------------------------------------------------ */
    /* Admin: flows                                                         */
    /* ------------------------------------------------------------------ */

    public static function save_flow() {
        self::verify_admin_nonce();

        $id    = absint( $_POST['id']    ?? 0 );
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $sort  = absint( $_POST['sort_order'] ?? 0 );

        if ( empty( $title ) ) {
            self::err( 'Flow title is required.' );
        }

        global $wpdb;
        $data   = [ 'title' => $title, 'sort_order' => $sort ];
        $format = [ '%s', '%d' ];

        if ( $id ) {
            $wpdb->update( "{$wpdb->prefix}itsc_flows", $data, [ 'id' => $id ], $format, [ '%d' ] );
        } else {
            $wpdb->insert( "{$wpdb->prefix}itsc_flows", $data, $format );
            $id = $wpdb->insert_id;
        }
        self::ok( [ 'id' => $id ] );
    }

    public static function delete_flow() {
        self::verify_admin_nonce();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) { self::err( 'Invalid ID.' ); }

        global $wpdb;

        // Cascade: options → questions → flow
        $question_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}itsc_questions WHERE flow_id = %d", $id
        ) );
        if ( $question_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}itsc_options WHERE question_id IN ($placeholders)",
                ...$question_ids
            ) );
        }
        $wpdb->delete( "{$wpdb->prefix}itsc_questions", [ 'flow_id' => $id ], [ '%d' ] );
        $wpdb->delete( "{$wpdb->prefix}itsc_flows",     [ 'id'      => $id ], [ '%d' ] );
        self::ok();
    }

    /* ------------------------------------------------------------------ */
    /* Admin: questions                                                     */
    /* ------------------------------------------------------------------ */

    public static function save_question() {
        self::verify_admin_nonce();

        $id       = absint( $_POST['id']         ?? 0 );
        $flow_id  = absint( $_POST['flow_id']    ?? 0 );
        $step     = absint( $_POST['step']       ?? 1 );
        $question = sanitize_textarea_field( wp_unslash( $_POST['question'] ?? '' ) );
        $sort     = absint( $_POST['sort_order'] ?? 0 );

        if ( ! $flow_id )     { self::err( 'Flow ID required.' ); }
        if ( empty( $question ) ) { self::err( 'Question text required.' ); }

        global $wpdb;
        $data   = [ 'flow_id' => $flow_id, 'step' => $step, 'question' => $question, 'sort_order' => $sort ];
        $format = [ '%d', '%d', '%s', '%d' ];

        if ( $id ) {
            $wpdb->update( "{$wpdb->prefix}itsc_questions", $data, [ 'id' => $id ], $format, [ '%d' ] );
        } else {
            $wpdb->insert( "{$wpdb->prefix}itsc_questions", $data, $format );
            $id = $wpdb->insert_id;
        }
        self::ok( [ 'id' => $id ] );
    }

    public static function delete_question() {
        self::verify_admin_nonce();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) { self::err( 'Invalid ID.' ); }

        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}itsc_options",   [ 'question_id' => $id ], [ '%d' ] );
        $wpdb->delete( "{$wpdb->prefix}itsc_questions", [ 'id'          => $id ], [ '%d' ] );
        self::ok();
    }

    /* ------------------------------------------------------------------ */
    /* Admin: options                                                       */
    /* ------------------------------------------------------------------ */

    public static function save_option() {
        self::verify_admin_nonce();

        $id          = absint( $_POST['id']          ?? 0 );
        $question_id = absint( $_POST['question_id'] ?? 0 );
        $label       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
        $sort        = absint( $_POST['sort_order']  ?? 0 );

        if ( ! $question_id ) { self::err( 'Question ID required.' ); }
        if ( empty( $label ) ) { self::err( 'Option label required.' ); }

        global $wpdb;
        $data   = [ 'question_id' => $question_id, 'label' => $label, 'sort_order' => $sort ];
        $format = [ '%d', '%s', '%d' ];

        if ( $id ) {
            $wpdb->update( "{$wpdb->prefix}itsc_options", $data, [ 'id' => $id ], $format, [ '%d' ] );
        } else {
            $wpdb->insert( "{$wpdb->prefix}itsc_options", $data, $format );
            $id = $wpdb->insert_id;
        }
        self::ok( [ 'id' => $id ] );
    }

    public static function delete_option() {
        self::verify_admin_nonce();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) { self::err( 'Invalid ID.' ); }

        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}itsc_options", [ 'id' => $id ], [ '%d' ] );
        self::ok();
    }

    /* ------------------------------------------------------------------ */
    /* Admin: get full flow data (for editor)                              */
    /* ------------------------------------------------------------------ */

    public static function get_flow_data() {
        self::verify_admin_nonce();
        $id = absint( $_POST['flow_id'] ?? 0 );
        if ( ! $id ) { self::err( 'Invalid ID.' ); }

        $flow      = ITSC_DB::get_flow( $id );
        $questions = ITSC_DB::get_questions( $id );
        foreach ( $questions as &$q ) {
            $q->options = ITSC_DB::get_options( $q->id );
        }
        self::ok( [ 'flow' => $flow, 'questions' => $questions ] );
    }

    /* ------------------------------------------------------------------ */
    /* Admin: reorder (drag & drop)                                        */
    /* ------------------------------------------------------------------ */

    public static function reorder() {
        self::verify_admin_nonce();

        $type = sanitize_key( $_POST['type'] ?? '' );
        $ids  = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );

        if ( ! in_array( $type, [ 'flows', 'questions', 'options' ], true ) ) {
            self::err( 'Invalid type.' );
        }

        global $wpdb;
        $table_map = [
            'flows'     => "{$wpdb->prefix}itsc_flows",
            'questions' => "{$wpdb->prefix}itsc_questions",
            'options'   => "{$wpdb->prefix}itsc_options",
        ];
        $table = $table_map[ $type ];
        foreach ( $ids as $sort => $id ) {
            $wpdb->update( $table, [ 'sort_order' => $sort ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        }
        self::ok();
    }

    /* ------------------------------------------------------------------ */
    /* Admin: leads                                                         */
    /* ------------------------------------------------------------------ */

    public static function delete_lead() {
        self::verify_admin_nonce();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) { self::err( 'Invalid ID.' ); }

        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}itsc_leads", [ 'id' => $id ], [ '%d' ] );
        self::ok();
    }

    /* ------------------------------------------------------------------ */
    /* Utility                                                              */
    /* ------------------------------------------------------------------ */

    private static function get_ip() {
        $keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
                // Take first IP if comma-separated
                $ip = trim( explode( ',', $ip )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
