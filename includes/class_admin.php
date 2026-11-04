<?php
defined( 'ABSPATH' ) || exit;

class AAM_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Phase 5 AJAX Handlers
        add_action( 'wp_ajax_aam_reindex_all', [ $this, 'ajax_reindex_all' ] );
        add_action( 'wp_ajax_aam_test_search', [ $this, 'ajax_test_search' ] );
        add_action( 'wp_ajax_aam_get_site_pages', [ $this, 'ajax_get_site_pages' ] );
        add_action( 'wp_ajax_aam_clear_unanswered_log', [ $this, 'ajax_clear_unanswered_log' ] );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'arrodes-ai-manager' ) === false ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style( 'aam-admin-style', AAM_URL . 'assets/admin.css', [], AAM_VERSION );
        wp_enqueue_script( 'aam-admin-script', AAM_URL . 'assets/admin.js', [ 'jquery' ], AAM_VERSION, true );
        wp_localize_script( 'aam-admin-script', 'aam_admin_vars', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'aam_admin_nonce' ),
        ] );
    }

    public function register_menu() {
        add_menu_page(
            esc_html__( 'Arrodes AI Manager', 'arrodes-ai-manager' ),
            esc_html__( 'Arrodes AI Manager', 'arrodes-ai-manager' ),
            'manage_options',
            'arrodes-ai-manager',
            [ $this, 'render_page' ],
            'dashicons-bot',
            80
        );
    }

    public function register_settings() {
        // AI Provider Settings
        register_setting( 'aam_settings_group', 'aam_provider', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'openrouter',
        ] );

        register_setting( 'aam_settings_group', 'aam_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_model_name', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_custom_endpoint', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ] );

        // Site Profile, Business Truth & Real Plans
        register_setting( 'aam_settings_group', 'aam_site_type', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'general',
        ] );

        register_setting( 'aam_settings_group', 'aam_pricing_plans', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_key_pages', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_faqs_knowledge', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_product_recommendations_mode', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'auto',
        ] );

        register_setting( 'aam_settings_group', 'aam_strict_grounding', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        // WooCommerce Integration Settings
        register_setting( 'aam_settings_group', 'aam_wc_enable', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        register_setting( 'aam_settings_group', 'aam_wc_max_products', [
            'type'              => 'integer',
            'sanitize_callback' => function( $val ) { $n = absint( $val ); return $n > 0 ? $n : 30; },
            'default'           => 30,
        ] );

        // Prompt & Instructions
        register_setting( 'aam_settings_group', 'aam_custom_system_instruction', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_chatbot_name', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { $v = sanitize_text_field( $val ); return ! empty( trim( $v ) ) ? $v : 'Arrodes Assistant'; },
            'default'           => 'Arrodes Assistant',
        ] );

        register_setting( 'aam_settings_group', 'aam_welcome_message', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { $v = sanitize_text_field( $val ); return ! empty( trim( $v ) ) ? $v : 'Hi! How can I help you today?'; },
            'default'           => 'Hi! How can I help you today?',
        ] );

        register_setting( 'aam_settings_group', 'aam_include_all_pages', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        register_setting( 'aam_settings_group', 'aam_selected_pages', [
            'type'              => 'array',
            'sanitize_callback' => function( $val ) {
                return is_array( $val ) ? array_map( 'absint', $val ) : [];
            },
            'default'           => [],
        ] );

        // Frontend Chat Styling
        register_setting( 'aam_settings_group', 'aam_primary_color', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { $v = sanitize_hex_color( $val ); return ! empty( $v ) ? $v : '#14bda9'; },
            'default'           => '#14bda9',
        ] );

        register_setting( 'aam_settings_group', 'aam_theme_style', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'light',
        ] );

        register_setting( 'aam_settings_group', 'aam_widget_position', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'bottom-right',
        ] );

        register_setting( 'aam_settings_group', 'aam_bubble_text', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { $v = sanitize_text_field( $val ); return ! empty( trim( $v ) ) ? $v : 'Ask Assistant'; },
            'default'           => 'Ask Assistant',
        ] );

        register_setting( 'aam_settings_group', 'aam_avatar_icon', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { $v = sanitize_text_field( $val ); return ! empty( trim( $v ) ) ? $v : '💬'; },
            'default'           => '💬',
        ] );

        register_setting( 'aam_settings_group', 'aam_auto_open', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '0',
        ] );

        register_setting( 'aam_settings_group', 'aam_streaming_mode', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        register_setting( 'aam_settings_group', 'aam_launcher_animation', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'pulse',
        ] );

        register_setting( 'aam_settings_group', 'aam_suggested_questions', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => "What are your top products?\nDo you have any discount codes?\nTell me about shipping & returns",
        ] );

        // Support Contact Email
        register_setting( 'aam_settings_group', 'aam_support_email', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ] );

        // WhatsApp Support Number
        register_setting( 'aam_settings_group', 'aam_whatsapp_number', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        // Offcanvas Chat Box Dimensions
        register_setting( 'aam_settings_group', 'aam_chat_width', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                $v = trim( sanitize_text_field( $val ) );
                return preg_match( '/^\d{1,4}(px|%|em|rem|vh|vw)$/i', $v ) ? $v : '380px';
            },
            'default'           => '380px',
        ] );

        register_setting( 'aam_settings_group', 'aam_chat_height', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                $v = trim( sanitize_text_field( $val ) );
                return preg_match( '/^\d{1,4}(px|%|em|rem|vh|vw)$/i', $v ) ? $v : '520px';
            },
            'default'           => '520px',
        ] );

        // Custom Logo Options
        register_setting( 'aam_settings_group', 'aam_wl_custom_logo', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_wl_logo_width', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                $v = trim( sanitize_text_field( $val ) );
                return preg_match( '/^\d{1,4}(px|%|em|rem)$/i', $v ) ? $v : '60px';
            },
            'default'           => '60px',
        ] );

        register_setting( 'aam_settings_group', 'aam_wl_logo_height', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                $v = trim( sanitize_text_field( $val ) );
                return preg_match( '/^\d{1,4}(px|%|em|rem)$/i', $v ) ? $v : '60px';
            },
            'default'           => '60px',
        ] );

        // Visibility Rules (Hide Floating Chat)
        register_setting( 'aam_settings_group', 'aam_hide_on_front', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '0',
        ] );

        register_setting( 'aam_settings_group', 'aam_hide_on_cart', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '0',
        ] );

        register_setting( 'aam_settings_group', 'aam_hide_on_checkout', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '0',
        ] );

        register_setting( 'aam_settings_group', 'aam_hide_page_ids', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        // Search & Indexing Settings
        register_setting( 'aam_settings_group', 'aam_auto_index', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        register_setting( 'aam_settings_group', 'aam_search_limit', [
            'type'              => 'integer',
            'sanitize_callback' => function( $val ) { $n = absint( $val ); return $n > 0 ? $n : 8; },
            'default'           => 8,
        ] );

        register_setting( 'aam_settings_group', 'aam_embedding_provider', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'auto',
        ] );

        register_setting( 'aam_settings_group', 'aam_embedding_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'aam_settings_group', 'aam_similarity_threshold', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                $f = (float) $val;
                return ( $f >= 0.1 && $f <= 0.99 ) ? (string) $f : '0.50';
            },
            'default'           => '0.50',
        ] );

        register_setting( 'aam_settings_group', 'aam_enable_query_expansion', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        // Rate Limit, Security & Session TTL Settings
        register_setting( 'aam_settings_group', 'aam_chatbot_enabled', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        register_setting( 'aam_settings_group', 'aam_rate_limit_count', [
            'type'              => 'integer',
            'sanitize_callback' => function( $val ) { $n = absint( $val ); return $n > 0 ? $n : 30; },
            'default'           => 30,
        ] );

        register_setting( 'aam_settings_group', 'aam_rate_limit_window', [
            'type'              => 'integer',
            'sanitize_callback' => function( $val ) { $n = absint( $val ); return $n > 0 ? $n : 10; },
            'default'           => 10,
        ] );

        register_setting( 'aam_settings_group', 'aam_session_ttl', [
            'type'              => 'integer',
            'sanitize_callback' => function( $val ) { $n = absint( $val ); return $n > 0 ? $n : 30; },
            'default'           => 30,
        ] );

        register_setting( 'aam_settings_group', 'aam_max_tokens', [
            'type'              => 'integer',
            'sanitize_callback' => function( $val ) { $n = absint( $val ); return $n >= 50 ? $n : 500; },
            'default'           => 500,
        ] );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'arrodes-ai-manager' ) );
        }

        $provider         = get_option( 'aam_provider', 'openrouter' );
        $api_key          = get_option( 'aam_api_key', '' );
        $model_name       = get_option( 'aam_model_name', '' );
        $custom_endpoint  = get_option( 'aam_custom_endpoint', '' );
        $wc_enable        = get_option( 'aam_wc_enable', '1' );
        $wc_max_products  = (int) get_option( 'aam_wc_max_products', 30 );
        if ( $wc_max_products <= 0 ) {
            $wc_max_products = 30;
        }
        $custom_prompt    = get_option( 'aam_custom_system_instruction', '' );

        $chatbot_name     = trim( (string) get_option( 'aam_chatbot_name', 'Arrodes Assistant' ) );
        if ( empty( $chatbot_name ) ) {
            $chatbot_name = 'Arrodes Assistant';
        }
        $welcome_message  = trim( (string) get_option( 'aam_welcome_message', 'Hi! How can I help you today?' ) );
        if ( empty( $welcome_message ) ) {
            $welcome_message = 'Hi! How can I help you today?';
        }
        $include_all      = get_option( 'aam_include_all_pages', '1' );
        $primary_color    = trim( (string) get_option( 'aam_primary_color', '#14bda9' ) );
        if ( empty( $primary_color ) ) {
            $primary_color = '#14bda9';
        }
        $theme_style      = get_option( 'aam_theme_style', 'light' );
        $widget_position  = get_option( 'aam_widget_position', 'bottom-right' );
        $bubble_text      = trim( (string) get_option( 'aam_bubble_text', 'Ask Assistant' ) );
        if ( empty( $bubble_text ) ) {
            $bubble_text = 'Ask Assistant';
        }
        $avatar_icon      = trim( (string) get_option( 'aam_avatar_icon', '💬' ) );
        if ( empty( $avatar_icon ) ) {
            $avatar_icon = '💬';
        }

        $support_email    = get_option( 'aam_support_email', '' );
        $whatsapp_number  = get_option( 'aam_whatsapp_number', '' );
        $chat_width       = trim( (string) get_option( 'aam_chat_width', '380px' ) );
        if ( empty( $chat_width ) ) {
            $chat_width = '380px';
        }
        $chat_height      = trim( (string) get_option( 'aam_chat_height', '520px' ) );
        if ( empty( $chat_height ) ) {
            $chat_height = '520px';
        }
        $auto_open        = get_option( 'aam_auto_open', '0' );
        $launcher_anim    = get_option( 'aam_launcher_animation', 'pulse' );
        $suggested_q      = get_option( 'aam_suggested_questions', "What are your top products?\nDo you have any discount codes?\nTell me about shipping & returns" );

        $wl_custom_logo   = get_option( 'aam_wl_custom_logo', '' );
        $wl_logo_width    = get_option( 'aam_wl_logo_width', '60px' );
        $wl_logo_height   = get_option( 'aam_wl_logo_height', '60px' );

        // Visibility Options
        $hide_on_front    = get_option( 'aam_hide_on_front', '0' );
        $hide_on_cart     = get_option( 'aam_hide_on_cart', '0' );
        $hide_on_checkout = get_option( 'aam_hide_on_checkout', '0' );
        $hide_page_ids    = get_option( 'aam_hide_page_ids', '' );

        // Search & Indexing Options
        $auto_index   = get_option( 'aam_auto_index', '1' );
        $search_limit = (int) get_option( 'aam_search_limit', 8 );
        if ( $search_limit <= 0 ) {
            $search_limit = 8;
        }

        $embedding_provider = get_option( 'aam_embedding_provider', 'auto' );
        $embedding_api_key  = get_option( 'aam_embedding_api_key', '' );
        $similarity_thresh  = get_option( 'aam_similarity_threshold', '0.50' );
        $enable_expansion   = get_option( 'aam_enable_query_expansion', '1' );

        $index_stats  = class_exists( 'AAM_Indexer' ) ? AAM_Indexer::get_index_stats() : [
            'table_exists'      => false,
            'total_indexed'     => 0,
            'products_indexed'  => 0,
            'pages_indexed'     => 0,
            'total_chunks'      => 0,
            'pricing_chunks'    => 0,
            'faq_chunks'        => 0,
            'vectorized_chunks' => 0,
            'last_updated'      => 'Never',
        ];

        // Rate Limit, Security & TTL Options (guaranteed positive non-zero defaults)
        $chatbot_enabled   = get_option( 'aam_chatbot_enabled', '1' );
        $rate_limit_count  = (int) get_option( 'aam_rate_limit_count', 30 );
        if ( $rate_limit_count <= 0 ) {
            $rate_limit_count = 30;
        }
        $rate_limit_window = (int) get_option( 'aam_rate_limit_window', 10 );
        if ( $rate_limit_window <= 0 ) {
            $rate_limit_window = 10;
        }
        $session_ttl       = (int) get_option( 'aam_session_ttl', 30 );
        if ( $session_ttl <= 0 ) {
            $session_ttl = 30;
        }
        $max_tokens        = (int) get_option( 'aam_max_tokens', 500 );
        if ( $max_tokens < 50 ) {
            $max_tokens = 500;
        }

        // Site Profile & Ground Truth Options
        $site_type       = get_option( 'aam_site_type', 'general' );
        $pricing_plans   = (string) get_option( 'aam_pricing_plans', '' );
        $key_pages       = (string) get_option( 'aam_key_pages', '' );
        $faqs_knowledge  = (string) get_option( 'aam_faqs_knowledge', '' );
        $rec_mode        = get_option( 'aam_product_recommendations_mode', 'auto' );
        $strict_ground   = get_option( 'aam_strict_grounding', '1' );
        $streaming_mode  = get_option( 'aam_streaming_mode', '1' );

        // Feedback & Unanswered Questions Analytics
        $unanswered_questions = (array) get_option( 'aam_unanswered_questions', [] );
        $feedback_up          = (int) get_option( 'aam_feedback_up', 0 );
        $feedback_down        = (int) get_option( 'aam_feedback_down', 0 );
        $total_feedback       = $feedback_up + $feedback_down;
        $satisfaction_rate    = $total_feedback > 0 ? round( ( $feedback_up / $total_feedback ) * 100 ) : 100;

        $wc_installed     = class_exists( 'WooCommerce' );
        ?>

        <div class="aam-admin-page">
            <!-- Header Banner -->
            <div class="aam-header-card">
                <div class="aam-header-left">
                    <div class="aam-header-titles">
                        <h1>Arrodes AI Manager Settings</h1>
                        <p>Configure chatbot identity, design theme, layout dimensions, visibility rules, and AI parameters.</p>
                    </div>
                </div>
                <div class="aam-brand-badge-wrap" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <a href="https://lmdesigners.gumroad.com/coffee/" target="_blank" rel="noopener noreferrer" class="aam-donate-btn" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg, #FFDD00 0%, #FBB03B 100%);color:#000;font-weight:700;padding:8px 16px;border-radius:8px;text-decoration:none;box-shadow:0 2px 8px rgba(251,176,59,0.3);transition:all 0.2s ease;">☕ Buy Us a Coffee / Support ↗</a>
                    <div class="aam-brand-badge">
                        <span class="dashicons dashicons-shield-alt" style="color:#14bda9;"></span>
                        <span>Plugin Configuration</span>
                    </div>
                </div>
            </div>

            <?php settings_errors(); ?>

            <form method="post" action="options.php" novalidate>
                <?php settings_fields( 'aam_settings_group' ); ?>

                <div class="aam-layout-grid">
                    <!-- Left Vertical Sidebar Tabs -->
                    <div class="aam-sidebar-nav">
                        <div class="aam-nav-item active" data-tab="tab-identity">
                            <span class="dashicons dashicons-format-chat"></span>
                            <span>Chat Identity & Content</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-business">
                            <span class="dashicons dashicons-building"></span>
                            <span>Site Profile & Real Plans</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-style">
                            <span class="dashicons dashicons-art"></span>
                            <span>Appearance & Theme</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-layout">
                            <span class="dashicons dashicons-layout"></span>
                            <span>Widget Layout & Size</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-visibility">
                            <span class="dashicons dashicons-visibility"></span>
                            <span>Visibility & Page Rules</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-provider">
                            <span class="dashicons dashicons-rest-api"></span>
                            <span>AI Models & API</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-ratelimit">
                            <span class="dashicons dashicons-shield"></span>
                            <span>Rate Limits & Privacy</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-indexing">
                            <span class="dashicons dashicons-search"></span>
                            <span>Search & Indexing</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-integrations">
                            <span class="dashicons dashicons-shortcode"></span>
                            <span>Shortcode & Elementor</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-woocommerce">
                            <span class="dashicons dashicons-cart"></span>
                            <span>WooCommerce Store</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-prompt">
                            <span class="dashicons dashicons-editor-alignleft"></span>
                            <span>System Instructions</span>
                        </div>
                        <div class="aam-nav-item" data-tab="tab-analytics">
                            <span class="dashicons dashicons-chart-pie"></span>
                            <span>Unanswered Gaps & Feedback</span>
                        </div>
                    </div>

                    <!-- Right Main Content Panel -->
                    <div class="aam-main-content">

                        <!-- TAB 1: Chat Identity & Content -->
                        <div id="tab-identity" class="aam-tab-panel active">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-format-chat" style="color:#14bda9;"></span>
                                Chatbot Identity & Content
                            </h2>

                            <!-- Chatbot Name -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_chatbot_name">Chatbot Name</label>
                                    <p class="aam-field-desc">Title displayed in chat header and widget labels.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_chatbot_name" name="aam_chatbot_name" value="<?php echo esc_attr( $chatbot_name ); ?>" class="aam-input-text" />
                                </div>
                            </div>

                            <!-- Default Avatar Emoji / Icon -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_avatar_icon">Default Avatar Emoji / Icon</label>
                                    <p class="aam-field-desc">Used if custom image logo is not uploaded.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_avatar_icon" name="aam_avatar_icon" value="<?php echo esc_attr( $avatar_icon ); ?>" class="aam-input-text" style="width:100px; text-align:center;" />
                                </div>
                            </div>

                            <!-- Welcome Greeting Message -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_welcome_message">Welcome Greeting Message</label>
                                    <p class="aam-field-desc">Initial message displayed when a visitor opens the chat.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_welcome_message" name="aam_welcome_message" value="<?php echo esc_attr( $welcome_message ); ?>" class="aam-input-text" />
                                </div>
                            </div>

                            <!-- Suggested Starter Questions (Chips) -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_suggested_questions">Suggested Starter Questions (Chips)</label>
                                    <p class="aam-field-desc">Enter default questions (one per line) for users to click without typing.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <textarea id="aam_suggested_questions" name="aam_suggested_questions" rows="3" class="aam-textarea"><?php echo esc_textarea( $suggested_q ); ?></textarea>
                                </div>
                            </div>

                            <!-- Customer Support Email -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_support_email">Customer Support Email</label>
                                    <p class="aam-field-desc">Optional store support email provided to AI for customer queries (defaults to WooCommerce store email or admin email if left blank).</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="email" id="aam_support_email" name="aam_support_email" value="<?php echo esc_attr( $support_email ); ?>" class="aam-input-text" placeholder="e.g. support@yourstore.com" />
                                </div>
                            </div>

                            <!-- WhatsApp Support Number -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_whatsapp_number">WhatsApp Support Number</label>
                                    <p class="aam-field-desc">Optional business WhatsApp number with country code (e.g. +92 300 1234567). Enables direct pre-filled WhatsApp ordering and customer support links.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_whatsapp_number" name="aam_whatsapp_number" value="<?php echo esc_attr( $whatsapp_number ); ?>" class="aam-input-text" placeholder="e.g. +92 300 1234567" />
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: Site Profile, Real Plans & Ground Truth -->
                        <div id="tab-business" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-building" style="color:#14bda9;"></span>
                                Site Profile, Real Plans & Ground Truth
                            </h2>

                            <!-- Informational Guarantee Box -->
                            <div style="background:#f0fdfa; border:1px solid #99f6e4; border-radius:12px; padding:16px 20px; margin-bottom:24px;">
                                <div style="display:flex; align-items:flex-start; gap:12px;">
                                    <span class="dashicons dashicons-yes-alt" style="color:#0d9488; font-size:22px; margin-top:2px;"></span>
                                    <div>
                                        <h3 style="margin:0 0 6px 0; color:#115e59; font-size:15px; font-weight:700;">Zero Hallucinations & Mobile-Optimized Responses</h3>
                                        <p style="margin:0; font-size:13px; color:#0f766e; line-height:1.5;">
                                            Configure your business model, exact plans & pricing, real page links, and policies. The AI is strictly bound to this ground truth, stopping fake prices or nonexistent pages. In addition, <strong>messy ASCII/markdown tables are strictly prohibited</strong> to guarantee crystal-clear bullet cards that fit mobile chat windows perfectly.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Website / Business Type -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_site_type">Website / Business Type</label>
                                    <p class="aam-field-desc">Tells the AI the exact nature of your website to set appropriate persona and prevent fake product cards.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_site_type" name="aam_site_type" class="aam-select" style="max-width:380px;">
                                        <option value="saas" <?php selected( $site_type, 'saas' ); ?>>💻 SaaS / Software / Web App / Plugin</option>
                                        <option value="ecommerce" <?php selected( $site_type, 'ecommerce' ); ?>>🛍️ E-Commerce / WooCommerce Online Store</option>
                                        <option value="services" <?php selected( $site_type, 'services' ); ?>>🏢 Digital Agency & Professional Services (Quotes, Consult)</option>
                                        <option value="lms" <?php selected( $site_type, 'lms' ); ?>>🎓 Education / LMS / Courses & Certifications</option>
                                        <option value="local_business" <?php selected( $site_type, 'local_business' ); ?>>🏨 Local Business / Clinic / Restaurant / Appointments</option>
                                        <option value="blog" <?php selected( $site_type, 'blog' ); ?>>📰 Blog / Media / Content Publishing</option>
                                        <option value="general" <?php selected( $site_type, 'general' ); ?>>🌐 General Corporate / Company Website</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Strict Grounding / Anti-Hallucination Mode -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Strict Grounding Mode (Anti-Hallucination)</label>
                                    <p class="aam-field-desc">Enforce strict adherence to verified facts. AI will NEVER guess or make up prices, plans, discounts, or page links. If details are not found in the site data, it will politely direct the user to your official contact.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_strict_grounding" value="1" <?php checked( $strict_ground, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                    <span style="font-size:12px; color:#059669; font-weight:600; margin-left:10px;">Recommended Active</span>
                                </div>
                            </div>

                            <!-- Product Recommendation Cards Mode -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_product_recommendations_mode">Product Recommendation Cards Mode</label>
                                    <p class="aam-field-desc">Control whether the AI is allowed to output structured product cards. For SaaS, Services, or blogs, disabling prevents fake store product recommendations.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_product_recommendations_mode" name="aam_product_recommendations_mode" class="aam-select" style="max-width:380px;">
                                        <option value="auto" <?php selected( $rec_mode, 'auto' ); ?>>⚡ Auto-Detect (Only active if WooCommerce store has products)</option>
                                        <option value="disabled" <?php selected( $rec_mode, 'disabled' ); ?>>🚫 Disabled (Conversational text & bullet links only — recommended for SaaS)</option>
                                        <option value="enabled" <?php selected( $rec_mode, 'enabled' ); ?>>✅ Always Enabled (Allow structured product cards)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Official Plans, Tiers & Pricing Box -->
                            <div class="aam-field-row" style="align-items:flex-start;">
                                <div class="aam-field-label-group">
                                    <label for="aam_pricing_plans">Official Plans, Tiers & Pricing (Authoritative Source)</label>
                                    <p class="aam-field-desc">Specify exact plans, package names, prices, billing frequency (monthly, annual, lifetime), and what is included. The AI is strictly instructed to use ONLY these numbers and tiers.</p>
                                    
                                    <!-- Preset Helpers -->
                                    <div style="margin-top:10px; display:flex; flex-direction:column; gap:6px;">
                                        <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Quick Templates:</span>
                                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <button type="button" class="aam-template-btn" data-template="saas" style="background:#e2e8f0; border:none; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer; font-weight:600;">+ SaaS / Plugin Plans</button>
                                            <button type="button" class="aam-template-btn" data-template="service" style="background:#e2e8f0; border:none; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer; font-weight:600;">+ Service Packages</button>
                                            <button type="button" class="aam-template-btn" data-template="course" style="background:#e2e8f0; border:none; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer; font-weight:600;">+ Courses / LMS</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="aam-field-control-group">
                                    <textarea id="aam_pricing_plans" name="aam_pricing_plans" rows="7" class="aam-textarea" placeholder="Example:
• Basic Plan: $19/mo (Essential features & standard support)
• Professional Plan: $49/mo (All features, priority support)
• Enterprise: Custom quote"><?php echo esc_textarea( $pricing_plans ); ?></textarea>
                                    <p style="font-size:12px; color:#64748b; margin-top:6px;">💡 Tip: You can include real links like <code>[View Pricing](https://yourdomain.com/pricing/)</code></p>
                                </div>
                            </div>

                            <!-- Verified Key Pages & Official URLs Directory -->
                            <div class="aam-field-row" style="align-items:flex-start;">
                                <div class="aam-field-label-group">
                                    <label for="aam_key_pages">Key Pages & Verified URLs Directory</label>
                                    <p class="aam-field-desc">List the real page URLs on your site (e.g. Pricing, Docs, Contact, Features, Free Trial). The AI will ONLY suggest or link to verified pages, eliminating broken/nonexistent links.</p>
                                    
                                    <button type="button" id="aam_auto_populate_pages_btn" class="aam-btn-primary" style="background:#0f172a; margin-top:12px; font-size:12px; padding:6px 12px; display:inline-flex; align-items:center; gap:6px;">
                                        <span class="dashicons dashicons-admin-links" style="font-size:16px;"></span>
                                        Scan & Auto-populate Real Site Pages
                                    </button>
                                    <div id="aam_pages_populate_status" style="display:none; font-size:12px; margin-top:6px; font-weight:600;"></div>
                                </div>
                                <div class="aam-field-control-group">
                                    <textarea id="aam_key_pages" name="aam_key_pages" rows="6" class="aam-textarea" placeholder="Example:
• Home: <?php echo esc_url( home_url( '/' ) ); ?>
• Pricing & Plans: <?php echo esc_url( home_url( '/pricing/' ) ); ?>
• Contact Us: <?php echo esc_url( home_url( '/contact/' ) ); ?>
• Documentation: <?php echo esc_url( home_url( '/docs/' ) ); ?>"><?php echo esc_textarea( $key_pages ); ?></textarea>
                                </div>
                            </div>

                            <!-- Frequently Asked Questions & Official Policies -->
                            <div class="aam-field-row" style="align-items:flex-start;">
                                <div class="aam-field-label-group">
                                    <label for="aam_faqs_knowledge">FAQs, Refund Guarantee & Official Policies</label>
                                    <p class="aam-field-desc">Add details regarding refunds (e.g. 14-day money back guarantee), free trial terms, licensing rules, support turnaround, or discounts.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <textarea id="aam_faqs_knowledge" name="aam_faqs_knowledge" rows="5" class="aam-textarea" placeholder="Example:
• Refund Policy: We offer a 100% money-back guarantee within 14 days of purchase if you are not satisfied.
• Free Trial: 7-day free trial available on our Pro plan with no credit card required.
• Support Hours: Monday to Friday, 9am - 6pm EST via email or support tickets."><?php echo esc_textarea( $faqs_knowledge ); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: Appearance & Theme -->
                        <div id="tab-style" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-art" style="color:#14bda9;"></span>
                                Appearance, Logo & Theme Styling
                            </h2>

                            <!-- Custom Avatar / Logo Image -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_wl_custom_logo">Open Brand Logo (Unboxed)</label>
                                    <p class="aam-field-desc">Upload a custom logo image. The logo is displayed <strong>OPEN</strong> without an enclosed white box, auto-adjusting with <code>object-fit: contain</code>.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-image-picker-group">
                                        <input type="url" id="aam_wl_custom_logo" name="aam_wl_custom_logo" value="<?php echo esc_attr( $wl_custom_logo ); ?>" class="aam-input-text" placeholder="https://your-domain.com/uploads/logo.png" />
                                        <button type="button" class="aam-btn-secondary" id="aam_upload_logo_btn">Choose Logo</button>
                                    </div>
                                    <div class="aam-size-group" style="margin-top:10px;">
                                        <div class="aam-size-input-box">
                                            <span>Logo Width:</span>
                                            <input type="text" id="aam_wl_logo_width" name="aam_wl_logo_width" value="<?php echo esc_attr( $wl_logo_width ); ?>" placeholder="auto / 120px" />
                                        </div>
                                        <div class="aam-size-input-box">
                                            <span>Logo Height:</span>
                                            <input type="text" id="aam_wl_logo_height" name="aam_wl_logo_height" value="<?php echo esc_attr( $wl_logo_height ); ?>" placeholder="auto / 40px" />
                                        </div>
                                        <span style="font-size:10px; color:#94a3b8; font-style:italic;">Contain Auto-Fit (Default: auto x 40px)</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Primary Theme Color -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_primary_color">Primary Theme Color</label>
                                    <p class="aam-field-desc">Set the primary brand color for buttons, chat header, and active tabs. Type any HEX code or pick from the palette.</p>
                                </div>
                                <div class="aam-field-control-group" style="display:flex; align-items:center; gap:8px;">
                                    <input type="color" id="aam_primary_color" name="aam_primary_color" value="<?php echo esc_attr( $primary_color ); ?>" style="height:36px; width:44px; padding:2px; cursor:pointer; vertical-align:middle;" />
                                    <input type="text" id="aam_primary_color_text" class="aam-input-text" style="width:120px; font-family:monospace; font-weight:600;" value="<?php echo esc_attr( $primary_color ); ?>" placeholder="#14bda9" maxlength="7" />
                                </div>
                            </div>

                            <!-- Chat Panel Visual Style -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_theme_style">Chat Panel Visual Style & Theme</label>
                                    <p class="aam-field-desc">Select visual color theme for front-end widget (6 modern designs).</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_theme_style" name="aam_theme_style" class="aam-select">
                                        <option value="light" <?php selected( $theme_style, 'light' ); ?>>✨ Modern Crisp Light (Clean White & Soft Ambient Shadows)</option>
                                        <option value="dark" <?php selected( $theme_style, 'dark' ); ?>>🌙 Obsidian Midnight Dark (Deep Dark Mode with Glowing Accents)</option>
                                        <option value="glass" <?php selected( $theme_style, 'glass' ); ?>>🔮 Frosted Glassmorphism (Semi-Transparent Blur & Specular Edges)</option>
                                        <option value="luxury" <?php selected( $theme_style, 'luxury' ); ?>>👑 Royal Luxury Gold & Onyx (High-End Retail & Boutique Style)</option>
                                        <option value="minimal" <?php selected( $theme_style, 'minimal' ); ?>>📐 Clean Minimalist Outline (Flat High-Contrast Crisp Lines)</option>
                                        <option value="vibrant" <?php selected( $theme_style, 'vibrant' ); ?>>⚡ Vibrant Neo-Gradient (Playful Modern Geometry & Dynamic Accents)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Launcher Button Animation -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_launcher_animation">Launcher Button Animation</label>
                                    <p class="aam-field-desc">Choose an attention-grabbing animation for the floating chat bubble button.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_launcher_animation" name="aam_launcher_animation" class="aam-select">
                                        <option value="none" <?php selected( $launcher_anim, 'none' ); ?>>None (Static Button)</option>
                                        <option value="pulse" <?php selected( $launcher_anim, 'pulse' ); ?>>Pulse Glow Effect</option>
                                        <option value="bounce" <?php selected( $launcher_anim, 'bounce' ); ?>>Gentle Bounce Effect</option>
                                        <option value="wiggle" <?php selected( $launcher_anim, 'wiggle' ); ?>>Wiggle / Shake Effect</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: Widget Layout & Size -->
                        <div id="tab-layout" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-layout" style="color:#14bda9;"></span>
                                Widget Layout & Size Dimensions
                            </h2>

                            <!-- Screen Alignment -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_widget_position">Screen Alignment</label>
                                    <p class="aam-field-desc">Position of the floating chat launcher button on screen.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_widget_position" name="aam_widget_position" class="aam-select">
                                        <option value="bottom-right" <?php selected( $widget_position, 'bottom-right' ); ?>>Bottom Right Corner</option>
                                        <option value="bottom-left" <?php selected( $widget_position, 'bottom-left' ); ?>>Bottom Left Corner</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Floating Bubble Label Text -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_bubble_text">Floating Bubble Label Text</label>
                                    <p class="aam-field-desc">Text shown next to launcher icon on desktop screens.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_bubble_text" name="aam_bubble_text" value="<?php echo esc_attr( $bubble_text ); ?>" class="aam-input-text" />
                                </div>
                            </div>

                            <!-- Offcanvas Chat Box Dimensions (Width & Height) -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Offcanvas Chat Box Dimensions</label>
                                    <p class="aam-field-desc">Set custom width and height for the floating chat panel container.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-size-group">
                                        <div class="aam-size-input-box">
                                            <span>Chat Width:</span>
                                            <input type="text" id="aam_chat_width" name="aam_chat_width" value="<?php echo esc_attr( $chat_width ); ?>" placeholder="380px" />
                                        </div>
                                        <div class="aam-size-input-box">
                                            <span>Chat Height:</span>
                                            <input type="text" id="aam_chat_height" name="aam_chat_height" value="<?php echo esc_attr( $chat_height ); ?>" placeholder="520px" />
                                        </div>
                                        <span style="font-size:10px; color:#94a3b8; font-style:italic;">Default: 380px x 520px</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Auto Open Chat Box on Page Load -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Auto Open Chat on Page Load</label>
                                    <p class="aam-field-desc">Automatically open the chat window when a visitor arrives at your site.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_auto_open" value="1" <?php checked( $auto_open, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 4: Visibility & Page Rules -->
                        <div id="tab-visibility" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-visibility" style="color:#14bda9;"></span>
                                Floating Chat Visibility & Hiding Rules
                            </h2>

                            <!-- Hide on Front Page -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Hide Floating Chat on Home Page</label>
                                    <p class="aam-field-desc">Disable the floating chat launcher button on the front/homepage.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_hide_on_front" value="1" <?php checked( $hide_on_front, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Hide on Cart Page -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Hide Floating Chat on Cart Page</label>
                                    <p class="aam-field-desc">Disable floating button on WooCommerce Cart page.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_hide_on_cart" value="1" <?php checked( $hide_on_cart, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Hide on Checkout Page -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Hide Floating Chat on Checkout Page</label>
                                    <p class="aam-field-desc">Disable floating button on WooCommerce Checkout page.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_hide_on_checkout" value="1" <?php checked( $hide_on_checkout, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Hide on Specific Page IDs -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_hide_page_ids">Specific Page/Post IDs to Hide On</label>
                                    <p class="aam-field-desc">Enter comma-separated page or post IDs where floating chat button should not appear (e.g. 12, 45, 108).</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_hide_page_ids" name="aam_hide_page_ids" value="<?php echo esc_attr( $hide_page_ids ); ?>" class="aam-input-text" placeholder="e.g. 12, 45, 108" />
                                </div>
                            </div>
                        </div>

                        <!-- TAB 5: AI Models & API -->
                        <div id="tab-provider" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-rest-api" style="color:#14bda9;"></span>
                                Multi-AI Provider Configuration
                            </h2>

                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_provider">Select Active AI Engine</label>
                                    <p class="aam-field-desc">Backend model routing service.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_provider" name="aam_provider" class="aam-select">
                                        <option value="openrouter" <?php selected( $provider, 'openrouter' ); ?>>OpenRouter API (Access 100+ Models)</option>
                                        <option value="gemini" <?php selected( $provider, 'gemini' ); ?>>Google Gemini API</option>
                                        <option value="deepseek" <?php selected( $provider, 'deepseek' ); ?>>DeepSeek API</option>
                                        <option value="kimi" <?php selected( $provider, 'kimi' ); ?>>Moonshot / Kimi API</option>
                                        <option value="custom" <?php selected( $provider, 'custom' ); ?>>Custom Agent Router / OpenAI Compatible Endpoint</option>
                                    </select>
                                </div>
                            </div>

                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_api_key">Provider Secret API Key</label>
                                    <p class="aam-field-desc">Enter secret API key.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="password" id="aam_api_key" name="aam_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="aam-input-text" autocomplete="off" placeholder="Paste your API key here..." />
                                </div>
                            </div>

                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_model_name">Custom Model Name Override</label>
                                    <p class="aam-field-desc">Specific model string ID. For Google Gemini, default is <code>gemini-3.6-flash</code>. For OpenRouter, you can use free models like <code>meta-llama/llama-3.3-70b-instruct:free</code>.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_model_name" name="aam_model_name" value="<?php echo esc_attr( $model_name ); ?>" class="aam-input-text" placeholder="e.g. gemini-3.6-flash, deepseek-chat" />
                                </div>
                            </div>

                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_custom_endpoint">Custom API Endpoint URL</label>
                                    <p class="aam-field-desc">Custom OpenAI compatible endpoint URL if using Custom Router.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="url" id="aam_custom_endpoint" name="aam_custom_endpoint" value="<?php echo esc_attr( $custom_endpoint ); ?>" class="aam-input-text" placeholder="https://api.yourcustomrouter.com/v1" />
                                </div>
                            </div>
                        </div>

                        <!-- TAB: Rate Limits, Security & Privacy -->
                        <div id="tab-ratelimit" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-shield" style="color:#14bda9;"></span>
                                Rate Limits, Abuse Protection & Privacy Controls
                            </h2>

                            <!-- Master Chatbot Toggle -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Master Chatbot Enabled</label>
                                    <p class="aam-field-desc">Master ON/OFF switch. Immediately enables or disables the front-end chat widget across your store.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_chatbot_enabled" value="1" <?php checked( $chatbot_enabled, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Rate Limit Max Messages -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_rate_limit_count">Max Messages Per Rate Limit Window</label>
                                    <p class="aam-field-desc">Maximum number of messages a user/IP can send within the rate window before being throttled.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" id="aam_rate_limit_count" name="aam_rate_limit_count" value="<?php echo esc_attr( $rate_limit_count ); ?>" class="aam-input-text" style="width:120px;" min="5" max="200" />
                                    <span style="font-size:12px; color:#64748b; margin-left:8px;">messages (Default: 30)</span>
                                </div>
                            </div>

                            <!-- Rate Limit Window Minutes -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_rate_limit_window">Rate Limit Time Window (Minutes)</label>
                                    <p class="aam-field-desc">Time window in minutes for the rate limit counter reset.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" id="aam_rate_limit_window" name="aam_rate_limit_window" value="<?php echo esc_attr( $rate_limit_window ); ?>" class="aam-input-text" style="width:120px;" min="1" max="60" />
                                    <span style="font-size:12px; color:#64748b; margin-left:8px;">minutes (Default: 10)</span>
                                </div>
                            </div>

                            <!-- Chat Session Auto-Expiry TTL -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_session_ttl">Transient Chat History TTL (Auto-Expiry)</label>
                                    <p class="aam-field-desc">Chat histories are stored safely in WordPress transients and auto-expire after this duration (zero DB bloat/permanence).</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" id="aam_session_ttl" name="aam_session_ttl" value="<?php echo esc_attr( $session_ttl ); ?>" class="aam-input-text" style="width:120px;" min="5" max="1440" />
                                    <span style="font-size:12px; color:#64748b; margin-left:8px;">minutes (Default: 30)</span>
                                </div>
                            </div>

                            <!-- Max Output Tokens Cap -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_max_tokens">AI Output Max Tokens Cap</label>
                                    <p class="aam-field-desc">Maximum tokens generated per AI reply to control API response length and prevent cost inflation.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" id="aam_max_tokens" name="aam_max_tokens" value="<?php echo esc_attr( $max_tokens ); ?>" class="aam-input-text" style="width:120px;" min="50" max="4000" />
                                    <span style="font-size:12px; color:#64748b; margin-left:8px;">tokens (Default: 500)</span>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 6: Search & Indexing -->
                        <div id="tab-indexing" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-search" style="color:#14bda9;"></span>
                                Hybrid Semantic & Vector Indexing Configuration
                            </h2>

                            <!-- Index Health Stats Box -->
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:24px;">
                                <h3 style="margin-top:0; color:#0f172a; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <span class="dashicons dashicons-database" style="color:#14bda9;"></span>
                                    Content Index & Semantic Vector Chunks Status
                                </h3>
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:12px; margin:16px 0;">
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#0f172a;" id="aam_stat_total"><?php echo esc_html( $index_stats['total_indexed'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Pages/Products</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#0284c7;" id="aam_stat_chunks"><?php echo esc_html( $index_stats['total_chunks'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Semantic Chunks</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#059669;" id="aam_stat_pricing"><?php echo esc_html( $index_stats['pricing_chunks'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Pricing Chunks</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#d97706;" id="aam_stat_faqs"><?php echo esc_html( $index_stats['faq_chunks'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">FAQ Chunks</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#7c3aed;" id="aam_stat_vectors"><?php echo esc_html( $index_stats['vectorized_chunks'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Vector Embeddings</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:12px; font-weight:600; color:#334155; margin-top:4px;" id="aam_stat_updated"><?php echo esc_html( $index_stats['last_updated'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; margin-top:2px;">Last Sync</div>
                                    </div>
                                </div>

                                <div style="display:flex; align-items:center; gap:16px; margin-top:12px;">
                                    <button type="button" class="aam-btn-primary" id="aam_reindex_btn">Reindex & Vectorize All Content Now</button>
                                    <div id="aam_reindex_status" style="display:none; font-size:13px; font-weight:600;"></div>
                                </div>
                            </div>

                            <!-- Embedding Provider Selector -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_embedding_provider">Vector Embedding Engine</label>
                                    <p class="aam-field-desc">Universal vector model for semantic similarity search. Works across all AI providers without external vector databases.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_embedding_provider" name="aam_embedding_provider" class="aam-select">
                                        <option value="auto" <?php selected( $embedding_provider, 'auto' ); ?>>Auto (Match Active AI Model / Gemini / Local Fallback)</option>
                                        <option value="gemini" <?php selected( $embedding_provider, 'gemini' ); ?>>Google Gemini (text-embedding-004 - 768 dim - High Precision Multilingual)</option>
                                        <option value="openai" <?php selected( $embedding_provider, 'openai' ); ?>>OpenAI (text-embedding-3-small - 1536 dim)</option>
                                        <option value="openai_compat" <?php selected( $embedding_provider, 'openai_compat' ); ?>>Custom OpenAI-Compatible Embedding Endpoint</option>
                                        <option value="local_hash" <?php selected( $embedding_provider, 'local_hash' ); ?>>Basic / Offline Mode (Deterministic Keyword Hash, Free — for Groq / Kimi / DeepSeek)</option>
                                    </select>
                                    <p class="aam-field-desc" style="margin-top:6px; font-size:11px; color:#64748b;">
                                        <em>Note: "Basic / Offline Mode" uses deterministic keyword hashing (BM25 + pack('f*')). It is 100% free and has zero external API costs. For true deep semantic embeddings across Roman Urdu & English, select Google Gemini or OpenAI.</em>
                                    </p>
                                </div>
                            </div>

                            <!-- Dedicated Embedding API Key (Optional Override) -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_embedding_api_key">Embedding API Key (Optional)</label>
                                    <p class="aam-field-desc">Leave blank to automatically reuse the primary API key from the "AI Models & API" tab.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="password" id="aam_embedding_api_key" name="aam_embedding_api_key" value="<?php echo esc_attr( $embedding_api_key ); ?>" class="aam-input-text" autocomplete="off" placeholder="Optional: Use custom key for embeddings..." />
                                </div>
                            </div>

                            <!-- Roman Urdu & Casual Query Expansion Toggle -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Roman Urdu & Conversational Query Expansion</label>
                                    <p class="aam-field-desc">Automatically translates queries like "rate kya hai", "refund mile ga", and typos to canonical search terms to eliminate 0-result errors.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_enable_query_expansion" value="1" <?php checked( $enable_expansion, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Similarity Threshold -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_similarity_threshold">Minimum Relevance Threshold</label>
                                    <p class="aam-field-desc">Discards chunks with lower composite scores (0.10 to 0.95) to prevent hallucinations and irrelevant noise. (Default: 0.50)</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" step="0.05" id="aam_similarity_threshold" name="aam_similarity_threshold" value="<?php echo esc_attr( $similarity_thresh ); ?>" class="aam-input-text" style="width:120px;" min="0.10" max="0.95" />
                                </div>
                            </div>

                            <!-- Auto Indexing Toggle -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Automatic Post & Product Indexing</label>
                                    <p class="aam-field-desc">Automatically update search index and generate vector chunks whenever a product or page is saved or edited.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_auto_index" value="1" <?php checked( $auto_index, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Search Results Limit -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_search_limit">Max Chunks Injected Per Query</label>
                                    <p class="aam-field-desc">Number of top semantic chunks injected into AI prompt context per question. (Default: 8)</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" id="aam_search_limit" name="aam_search_limit" value="<?php echo esc_attr( $search_limit ); ?>" class="aam-input-text" style="width:120px;" min="2" max="25" />
                                </div>
                            </div>

                            <!-- Live Test Search Box with Intent & Score Inspector -->
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-top:24px;">
                                <h3 style="margin-top:0; color:#0f172a; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <span class="dashicons dashicons-search" style="color:#14bda9;"></span>
                                    Test Hybrid Retrieval & Intent Inspector
                                </h3>
                                <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Type any user question (in English, Roman Urdu, or casual terms) to test how chunks are retrieved, scored, and expanded:</p>

                                <div style="display:flex; gap:10px; margin-bottom:16px;">
                                    <input type="text" id="aam_test_query" class="aam-input-text" placeholder="e.g. rate kya hai, refund policy, Pro package, certificate verification" style="flex:1;" />
                                    <button type="button" class="aam-btn-primary" id="aam_test_search_btn">Run Hybrid Search</button>
                                </div>

                                <div id="aam_test_results"></div>
                            </div>
                        </div>

                        <!-- TAB 7: Shortcode & Elementor Integration -->
                        <div id="tab-integrations" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-shortcode" style="color:#14bda9;"></span>
                                Shortcode & Elementor Integration
                            </h2>

                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:20px;">
                                <h3 style="margin-top:0; color:#0f172a; font-size:16px;">1. WordPress Shortcode</h3>
                                <p style="font-size:13px; color:#64748b; margin-bottom:12px;">Copy and paste this shortcode into any page, post, block editor, or sidebar widget to embed an inline AI Chat window:</p>
                                <div style="background:#0f172a; color:#38bdf8; padding:12px 16px; border-radius:8px; font-family:monospace; font-size:14px; user-select:all; display:flex; justify-content:space-between; align-items:center;">
                                    <code>[arrodes_ai_chat width="100%" height="550px" theme="light" primary_color="#14bda9"]</code>
                                </div>
                                <ul style="font-size:12px; color:#64748b; margin-top:12px; padding-left:20px; line-height:1.6;">
                                    <li><code>width</code>: Container width (e.g. <code>100%</code>, <code>400px</code>)</li>
                                    <li><code>height</code>: Chat box height (e.g. <code>550px</code>, <code>600px</code>)</li>
                                    <li><code>theme</code>: Visual style (<code>light</code> or <code>dark</code>)</li>
                                    <li><code>primary_color</code>: Hex color code (e.g. <code>#14bda9</code>)</li>
                                </ul>
                            </div>

                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                                <h3 style="margin-top:0; color:#0f172a; font-size:16px;">2. Elementor Page Builder Widget</h3>
                                <p style="font-size:13px; color:#64748b; margin-bottom:12px;">Arrodes AI Manager includes a dedicated Elementor Widget. Simply open Elementor Page Builder, search for <strong>"Arrodes AI Chat"</strong> under General Widgets, and drag & drop it onto your page with full typography and style controls.</p>
                                <div style="display:flex; align-items:center; gap:10px; background:#f1f5f9; padding:10px 14px; border-radius:8px; font-size:12px; color:#334155;">
                                    <span class="dashicons dashicons-yes-alt" style="color:#059669; font-size:18px;"></span>
                                    <span>Elementor Widget Status: <strong>Active & Registered</strong></span>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 8: WooCommerce Store -->
                        <div id="tab-woocommerce" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-cart" style="color:#14bda9;"></span>
                                WooCommerce Store Settings
                            </h2>

                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Enable Product Indexing</label>
                                    <p class="aam-field-desc">Allow AI to read store catalog, prices, and stock statuses.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_wc_enable" value="1" <?php checked( $wc_enable, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_wc_max_products">Maximum Products Context</label>
                                    <p class="aam-field-desc">Number of store products fed to AI context.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" id="aam_wc_max_products" name="aam_wc_max_products" value="<?php echo esc_attr( $wc_max_products ); ?>" class="aam-input-text" style="width:140px;" min="5" max="150" />
                                </div>
                            </div>
                        </div>

                        <!-- TAB 9: System Instructions -->
                        <div id="tab-prompt" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-editor-alignleft" style="color:#14bda9;"></span>
                                Custom System Prompts
                            </h2>

                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_custom_system_instruction">Custom AI System Prompt</label>
                                    <p class="aam-field-desc">Guidelines, store policy, or coupon codes provided to AI.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <textarea id="aam_custom_system_instruction" name="aam_custom_system_instruction" rows="6" class="aam-textarea" placeholder="e.g. Always respond politely, promote our free shipping offer over $50..."><?php echo esc_textarea( $custom_prompt ); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 10: Unanswered Gaps & Feedback Analytics -->
                        <div id="tab-analytics" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-chart-pie" style="color:#14bda9;"></span>
                                Unanswered Gaps & Customer Feedback
                            </h2>

                            <!-- Analytics Stats Overview Grid -->
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:24px;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                    <div style="font-size:28px; font-weight:800; color:#0f172a;" id="aam_stat_gaps_count"><?php echo count( $unanswered_questions ); ?></div>
                                    <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; margin-top:4px;">Knowledge Gaps Logged</div>
                                </div>
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                    <div style="font-size:28px; font-weight:800; color:#10b981;"><?php echo esc_html( $feedback_up ); ?> 👍</div>
                                    <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; margin-top:4px;">Thumbs Up (Helpful)</div>
                                </div>
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                    <div style="font-size:28px; font-weight:800; color:#ef4444;"><?php echo esc_html( $feedback_down ); ?> 👎</div>
                                    <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; margin-top:4px;">Thumbs Down</div>
                                </div>
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                    <div style="font-size:28px; font-weight:800; color:#14bda9;"><?php echo esc_html( $satisfaction_rate ); ?>%</div>
                                    <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; margin-top:4px;">Customer Satisfaction</div>
                                </div>
                            </div>

                            <!-- Fast Setting: Streaming / Typewriter Response Mode -->
                            <div class="aam-field-row" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:20px;">
                                <div class="aam-field-label-group">
                                    <label>Streaming / Typewriter Response Mode</label>
                                    <p class="aam-field-desc">Animate chat replies word-by-word with a typewriter cursor effect for lightning-fast perceived performance.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-toggle-wrapper">
                                        <label class="aam-toggle-switch">
                                            <input type="checkbox" name="aam_streaming_mode" value="1" <?php checked( $streaming_mode, '1' ); ?> />
                                            <span class="aam-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- WhatsApp Live Direct Conversion Card -->
                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px; margin-bottom:24px;">
                                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                    <div>
                                        <h4 style="margin:0; color:#166534; font-size:14px; display:flex; align-items:center; gap:6px;">
                                            <span>💬</span> WhatsApp Direct Order & Support Handoff
                                        </h4>
                                        <p style="margin:4px 0 0 0; font-size:12px; color:#15803d;">
                                            <?php if ( ! empty( $whatsapp_number ) ) : ?>
                                                Active Number: <strong><?php echo esc_html( $whatsapp_number ); ?></strong> (Auto-populates product titles, live prices, and order inquiries directly into customer WhatsApp messages).
                                            <?php else : ?>
                                                WhatsApp number is not set. Go to <strong>Chat Identity & Content</strong> tab to enter your WhatsApp number (e.g. <code>923001234567</code>).
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <?php if ( ! empty( $whatsapp_number ) ) : ?>
                                        <span style="background:#22c55e; color:#ffffff; font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px;">ENABLED</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Unanswered Questions Knowledge Gap Table -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:20px;">
                                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                                    <div>
                                        <h3 style="margin:0; font-size:15px; color:#0f172a;">Knowledge Gaps (Unanswered Questions)</h3>
                                        <p style="margin:3px 0 0 0; font-size:12px; color:#64748b;">Queries where relevance fell below the confidence threshold, safely redirected to your support team.</p>
                                    </div>
                                    <?php if ( ! empty( $unanswered_questions ) ) : ?>
                                        <button type="button" class="aam-btn-secondary" id="aam_clear_unanswered_btn" style="color:#ef4444; border-color:#fca5a5;">
                                            Clear Knowledge Gaps Log
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if ( empty( $unanswered_questions ) ) : ?>
                                    <div style="background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; padding:30px; text-align:center; color:#64748b; font-size:13px;">
                                        <div style="font-size:28px; margin-bottom:8px;">🎯</div>
                                        <strong>No knowledge gaps logged yet!</strong><br />
                                        All recent customer questions matched relevant chunks or were successfully grounded by the assistant.
                                    </div>
                                <?php else : ?>
                                    <div style="overflow-x:auto;">
                                        <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;">
                                            <thead>
                                                <tr style="border-bottom:2px solid #e2e8f0; color:#475569;">
                                                    <th style="padding:10px 8px;">Date / Time</th>
                                                    <th style="padding:10px 8px;">Customer Query</th>
                                                    <th style="padding:10px 8px;">Reason / Top Score</th>
                                                    <th style="padding:10px 8px;">User IP</th>
                                                </tr>
                                            </thead>
                                            <tbody id="aam_unanswered_table_body">
                                                <?php foreach ( array_reverse( $unanswered_questions ) as $item ) : ?>
                                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                                        <td style="padding:10px 8px; color:#64748b; white-space:nowrap;"><?php echo esc_html( isset( $item['time'] ) ? $item['time'] : '-' ); ?></td>
                                                        <td style="padding:10px 8px; font-weight:600; color:#0f172a;"><?php echo esc_html( isset( $item['query'] ) ? $item['query'] : '' ); ?></td>
                                                        <td style="padding:10px 8px; color:#475569;"><?php echo esc_html( isset( $item['reason'] ) ? $item['reason'] : 'Relevance below confidence threshold' ); ?></td>
                                                        <td style="padding:10px 8px; color:#94a3b8; font-family:monospace;"><?php echo esc_html( isset( $item['ip'] ) ? $item['ip'] : '-' ); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Save Footer -->
                        <div class="aam-submit-footer">
                            <button type="submit" class="aam-btn-primary">Save All Configuration Settings</button>
                        </div>

                    </div>
                </div>
            </form>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const navItems = document.querySelectorAll('.aam-nav-item');
                const panels   = document.querySelectorAll('.aam-tab-panel');

                function switchTab(targetTabId) {
                    if (!targetTabId) return;
                    let foundItem = null;
                    let foundPanel = document.getElementById(targetTabId);

                    navItems.forEach(i => {
                        if (i.getAttribute('data-tab') === targetTabId) {
                            foundItem = i;
                        }
                    });

                    if (foundItem && foundPanel) {
                        navItems.forEach(i => i.classList.remove('active'));
                        panels.forEach(p => p.classList.remove('active'));
                        foundItem.classList.add('active');
                        foundPanel.classList.add('active');
                        try {
                            sessionStorage.setItem('aam_admin_active_tab', targetTabId);
                        } catch (e) {}
                    }
                }

                navItems.forEach(item => {
                    item.addEventListener('click', function() {
                        const target = this.getAttribute('data-tab');
                        switchTab(target);
                    });
                });

                // Restore active tab after form save redirect
                try {
                    const savedTab = sessionStorage.getItem('aam_admin_active_tab');
                    const hashTab  = window.location.hash ? window.location.hash.replace('#', '') : '';
                    if (hashTab && document.getElementById(hashTab)) {
                        switchTab(hashTab);
                    } else if (savedTab && document.getElementById(savedTab)) {
                        switchTab(savedTab);
                    }
                } catch (e) {}

                // WP Media Image Uploader
                const uploadBtn = document.getElementById('aam_upload_logo_btn');
                const logoInput = document.getElementById('aam_wl_custom_logo');

                if (uploadBtn && logoInput) {
                    uploadBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (typeof wp !== 'undefined' && wp.media) {
                            const customUploader = wp.media({
                                title: 'Select Custom Avatar / Logo Image',
                                button: { text: 'Use This Image' },
                                multiple: false
                            });

                            customUploader.on('select', function() {
                                const attachment = customUploader.state().get('selection').first().toJSON();
                                logoInput.value = attachment.url;
                            });

                            customUploader.open();
                        } else {
                            alert('Media library not available.');
                        }
                    });
                }
            });
        </script>
        <?php
    }

    public function ajax_reindex_all() {
        check_ajax_referer( 'aam_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'arrodes-ai-manager' ) ] );
        }

        if ( class_exists( 'AAM_Indexer' ) ) {
            $indexer = new AAM_Indexer();
            $res     = $indexer->reindex_all();
            $stats   = AAM_Indexer::get_index_stats();
            wp_send_json_success( [
                'message' => sprintf( __( 'Successfully indexed %d products and %d pages!', 'arrodes-ai-manager' ), $res['products'], $res['pages'] ),
                'stats'   => $stats,
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Indexer module unavailable.', 'arrodes-ai-manager' ) ] );
        }
    }

    public function ajax_test_search() {
        check_ajax_referer( 'aam_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'arrodes-ai-manager' ) ] );
        }

        $query = isset( $_POST['query'] ) ? sanitize_text_field( $_POST['query'] ) : '';
        $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 8;

        if ( empty( $query ) ) {
            wp_send_json_error( [ 'message' => __( 'Please provide a search phrase.', 'arrodes-ai-manager' ) ] );
        }

        $preprocessed = class_exists( 'AAM_Query_Preprocessor' ) ? AAM_Query_Preprocessor::process( $query ) : null;

        if ( class_exists( 'AAM_Hybrid_Search' ) ) {
            delete_transient( 'aam_hyb_' . md5( strtolower( trim( $query ) ) . '_' . $limit ) );
            $chunks = AAM_Hybrid_Search::search( $query, $limit );
            wp_send_json_success( [
                'engine'       => 'hybrid_semantic',
                'preprocessed' => $preprocessed,
                'results'      => $chunks,
                'count'        => count( $chunks ),
            ] );
        } elseif ( class_exists( 'AAM_Search' ) ) {
            delete_transient( 'aam_srch_' . md5( strtolower( trim( $query ) ) . '_' . $limit ) );
            $results = AAM_Search::find_relevant( $query, $limit );
            wp_send_json_success( [
                'engine'       => 'legacy_fulltext',
                'preprocessed' => $preprocessed,
                'results'      => $results,
                'count'        => count( $results ),
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Search engine module unavailable.', 'arrodes-ai-manager' ) ] );
        }
    }

    public function ajax_get_site_pages() {
        check_ajax_referer( 'aam_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'arrodes-ai-manager' ) ] );
        }

        $pages = get_posts( [
            'post_type'      => [ 'page', 'post' ],
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $items = [];
        foreach ( $pages as $p ) {
            $items[] = [
                'title'     => $p->post_title,
                'permalink' => get_permalink( $p->ID ),
                'type'      => $p->post_type,
            ];
        }

        wp_send_json_success( [
            'pages' => $items,
            'count' => count( $items ),
        ] );
    }

    public function ajax_clear_unanswered_log() {
        check_ajax_referer( 'aam_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'arrodes-ai-manager' ) ] );
        }

        update_option( 'aam_unanswered_questions', [] );
        wp_send_json_success( [ 'message' => __( 'Knowledge gaps log cleared successfully.', 'arrodes-ai-manager' ) ] );
    }

}
