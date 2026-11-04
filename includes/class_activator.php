<?php
defined( 'ABSPATH' ) || exit;

class AAM_Activator {

    public static function activate() {
        self::create_tables();
        self::save_version();
        self::seed_defaults();
    }

    private static function seed_defaults() {
        $defaults = [
            'aam_provider'                     => 'openrouter',
            'aam_model_name'                   => '',
            'aam_chatbot_name'                 => 'Arrodes Assistant',
            'aam_welcome_message'              => 'Hi! How can I help you today?',
            'aam_bubble_text'                  => 'Ask Assistant',
            'aam_avatar_icon'                  => '💬',
            'aam_primary_color'                => '#14bda9',
            'aam_theme_style'                  => 'light',
            'aam_widget_position'              => 'bottom-right',
            'aam_chat_width'                   => '380px',
            'aam_chat_height'                  => '520px',
            'aam_launcher_animation'           => 'pulse',
            'aam_suggested_questions'          => "What are your top products?\nDo you have any discount codes?\nTell me about shipping & returns",
            'aam_site_type'                    => 'general',
            'aam_pricing_plans'                => '',
            'aam_key_pages'                    => '',
            'aam_faqs_knowledge'               => '',
            'aam_product_recommendations_mode' => 'auto',
            'aam_strict_grounding'             => '1',
            'aam_wc_enable'                    => '1',
            'aam_wc_max_products'              => 30,
            'aam_auto_index'                   => '1',
            'aam_search_limit'                 => 8,
            'aam_chatbot_enabled'              => '1',
            'aam_rate_limit_count'             => 30,
            'aam_rate_limit_window'            => 10,
            'aam_session_ttl'                  => 30,
            'aam_max_tokens'                   => 500,
            'aam_embedding_provider'           => 'auto',
            'aam_embedding_api_key'            => '',
            'aam_similarity_threshold'         => '0.50',
            'aam_enable_query_expansion'       => '1',
        ];

        foreach ( $defaults as $opt_key => $opt_val ) {
            if ( get_option( $opt_key ) === false ) {
                add_option( $opt_key, $opt_val );
            }
        }
    }

    private static function create_tables() {
        global $wpdb;

        $table   = $wpdb->prefix . 'aam_chat_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            response TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY session_id_idx (session_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Create Content Index and Semantic Chunks tables
        if ( class_exists( 'AAM_Indexer' ) ) {
            AAM_Indexer::create_table();
            AAM_Indexer::create_chunks_table();
        }
    }

    private static function save_version() {
        add_option( 'aam_version', AAM_VERSION );
    }

}
