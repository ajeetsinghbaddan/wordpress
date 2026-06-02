<?php
defined( 'ABSPATH' ) || exit;

class ITSC_DB {

    /* ------------------------------------------------------------------ */
    /* Schema                                                               */
    /* ------------------------------------------------------------------ */

    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Flows table (top-level services, e.g. "Web development")
        dbDelta( "CREATE TABLE {$wpdb->prefix}itsc_flows (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(255)    NOT NULL,
            sort_order  SMALLINT        NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        // Questions table (belongs to a flow, ordered by step)
        dbDelta( "CREATE TABLE {$wpdb->prefix}itsc_questions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flow_id     BIGINT UNSIGNED NOT NULL,
            step        SMALLINT        NOT NULL DEFAULT 1,
            question    TEXT            NOT NULL,
            sort_order  SMALLINT        NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY flow_step (flow_id, step)
        ) $charset;" );

        // Options table (answers per question)
        dbDelta( "CREATE TABLE {$wpdb->prefix}itsc_options (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT UNSIGNED NOT NULL,
            label       VARCHAR(255)    NOT NULL,
            sort_order  SMALLINT        NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY question_id (question_id)
        ) $charset;" );

        // Leads table
        dbDelta( "CREATE TABLE {$wpdb->prefix}itsc_leads (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name   VARCHAR(255)    NOT NULL,
            email       VARCHAR(255)    NOT NULL,
            phone       VARCHAR(50)     NOT NULL,
            message     TEXT,
            answers     LONGTEXT,
            ip_address  VARCHAR(45),
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        update_option( 'itsc_db_version', ITSC_VERSION );

        // Only seed on first install
        if ( ! get_option( 'itsc_seeded' ) ) {
            self::seed();
            update_option( 'itsc_seeded', 1 );
        }
    }

    public static function deactivate() {}

    /* ------------------------------------------------------------------ */
    /* Seed default data matching the spec                                 */
    /* ------------------------------------------------------------------ */

    private static function seed() {
        $flows = [
            [
                'title' => 'Web development',
                'questions' => [
                    [
                        'question' => 'Great choice. What kind of website do you need?',
                        'options'  => [ 'E-commerce store', 'Corporate or business site', 'Portfolio or blog', 'Web application or SaaS' ],
                    ],
                    [
                        'question' => 'Are you starting fresh or working with an existing site?',
                        'options'  => [ 'Brand new build', 'Redesign existing site', 'Add new features', 'Ongoing maintenance' ],
                    ],
                    [
                        'question' => 'What is your expected timeline?',
                        'options'  => [ 'Urgent, under 1 month', '1 to 3 months', '3 to 6 months', 'Flexible' ],
                    ],
                    [
                        'question' => 'What is your approximate budget range?',
                        'options'  => [ 'Under $5,000', '$5,000 to $15,000', '$15,000 to $50,000', '$50,000 and above' ],
                    ],
                ],
            ],
            [
                'title' => 'Mobile app development',
                'questions' => [
                    [
                        'question' => 'Awesome. Which platform are you targeting?',
                        'options'  => [ 'iOS only', 'Android only', 'Both, native apps', 'Cross platform (React Native or Flutter)' ],
                    ],
                    [
                        'question' => 'What stage is your app idea at?',
                        'options'  => [ 'Just an idea', 'I have wireframes', 'Designs are ready', 'Existing app needs updates' ],
                    ],
                    [
                        'question' => 'When do you need it launched?',
                        'options'  => [ 'Urgent, under 1 month', '1 to 3 months', '3 to 6 months', 'Flexible' ],
                    ],
                    [
                        'question' => 'What budget have you set aside?',
                        'options'  => [ 'Under $10,000', '$10,000 to $30,000', '$30,000 to $75,000', '$75,000 and above' ],
                    ],
                ],
            ],
            [
                'title' => 'Cloud services',
                'questions' => [
                    [
                        'question' => 'Do you have a preferred cloud provider?',
                        'options'  => [ 'AWS', 'Microsoft Azure', 'Google Cloud', 'I need a recommendation' ],
                    ],
                    [
                        'question' => 'Which service do you need the most?',
                        'options'  => [ 'Migration to cloud', 'Fresh cloud setup', 'Cost or performance optimization', 'Ongoing management' ],
                    ],
                    [
                        'question' => 'What does your current setup look like?',
                        'options'  => [ 'Entirely on premise', 'Hybrid, some cloud already', 'Fully on cloud already', 'Just starting out' ],
                    ],
                    [
                        'question' => 'What is your expected monthly cloud spend?',
                        'options'  => [ 'Under $2,000 per month', '$2,000 to $10,000 per month', '$10,000 to $50,000 per month', '$50,000 and above per month' ],
                    ],
                ],
            ],
            [
                'title' => 'IT support and consulting',
                'questions' => [
                    [
                        'question' => 'What type of support coverage do you need?',
                        'options'  => [ '24/7 support', 'Business hours only', 'On demand or ad hoc', 'One time project' ],
                    ],
                    [
                        'question' => 'How large is your team?',
                        'options'  => [ '1 to 10 employees', '11 to 50 employees', '51 to 200 employees', '200 or more employees' ],
                    ],
                    [
                        'question' => 'Which area needs the most help?',
                        'options'  => [ 'Network and infrastructure', 'Cybersecurity', 'Hardware and devices', 'Software and applications' ],
                    ],
                    [
                        'question' => 'What is your monthly IT budget?',
                        'options'  => [ 'Under $1,000 per month', '$1,000 to $5,000 per month', '$5,000 to $20,000 per month', '$20,000 and above per month' ],
                    ],
                ],
            ],
        ];

        global $wpdb;
        $sort = 0;
        foreach ( $flows as $flow ) {
            $wpdb->insert( "{$wpdb->prefix}itsc_flows", [
                'title'      => $flow['title'],
                'sort_order' => $sort++,
            ] );
            $flow_id = $wpdb->insert_id;
            foreach ( $flow['questions'] as $step => $q ) {
                $wpdb->insert( "{$wpdb->prefix}itsc_questions", [
                    'flow_id'    => $flow_id,
                    'step'       => $step + 1,
                    'question'   => $q['question'],
                    'sort_order' => $step,
                ] );
                $q_id = $wpdb->insert_id;
                foreach ( $q['options'] as $oi => $label ) {
                    $wpdb->insert( "{$wpdb->prefix}itsc_options", [
                        'question_id' => $q_id,
                        'label'       => $label,
                        'sort_order'  => $oi,
                    ] );
                }
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Public read helpers                                                  */
    /* ------------------------------------------------------------------ */

    public static function get_flows() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}itsc_flows ORDER BY sort_order ASC, id ASC"
        );
    }

    public static function get_flow( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}itsc_flows WHERE id = %d", $id
        ) );
    }

    public static function get_questions( $flow_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}itsc_questions WHERE flow_id = %d ORDER BY step ASC, sort_order ASC", $flow_id
        ) );
    }

    public static function get_question( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}itsc_questions WHERE id = %d", $id
        ) );
    }

    public static function get_options( $question_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}itsc_options WHERE question_id = %d ORDER BY sort_order ASC, id ASC", $question_id
        ) );
    }

    /** Return the full chatbot config as a nested array (for JS localisation). */
    public static function get_chatbot_config() {
        $flows = self::get_flows();
        $config = [];
        foreach ( $flows as $flow ) {
            $entry = [
                'id'        => (int) $flow->id,
                'title'     => $flow->title,
                'questions' => [],
            ];
            foreach ( self::get_questions( $flow->id ) as $q ) {
                $options = array_map( function( $o ) {
                    return [ 'id' => (int) $o->id, 'label' => $o->label ];
                }, self::get_options( $q->id ) );

                $entry['questions'][] = [
                    'id'       => (int) $q->id,
                    'step'     => (int) $q->step,
                    'question' => $q->question,
                    'options'  => $options,
                ];
            }
            $config[] = $entry;
        }
        return $config;
    }

    public static function get_leads( $per_page = 20, $page = 1 ) {
        global $wpdb;
        $offset = ( $page - 1 ) * $per_page;
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}itsc_leads ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}itsc_leads" );
        return [ 'rows' => $rows, 'total' => $total ];
    }

    public static function get_lead( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}itsc_leads WHERE id = %d", $id
        ) );
    }
}
