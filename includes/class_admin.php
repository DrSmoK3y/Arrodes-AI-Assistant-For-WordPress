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

        // WooCommerce Integration Settings
        register_setting( 'aam_settings_group', 'aam_wc_enable', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        register_setting( 'aam_settings_group', 'aam_wc_max_products', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
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
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Arrodes Assistant',
        ] );

        register_setting( 'aam_settings_group', 'aam_welcome_message', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
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
            'sanitize_callback' => 'sanitize_hex_color',
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
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Ask Assistant',
        ] );

        register_setting( 'aam_settings_group', 'aam_avatar_icon', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '💬',
        ] );

        register_setting( 'aam_settings_group', 'aam_auto_open', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '0',
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
            'sanitize_callback' => 'absint',
            'default'           => 8,
        ] );

        // Rate Limit, Security & Session TTL Settings
        register_setting( 'aam_settings_group', 'aam_chatbot_enabled', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) { return $val === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );

        register_setting( 'aam_settings_group', 'aam_rate_limit_count', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 30,
        ] );

        register_setting( 'aam_settings_group', 'aam_rate_limit_window', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 10,
        ] );

        register_setting( 'aam_settings_group', 'aam_session_ttl', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 30,
        ] );

        register_setting( 'aam_settings_group', 'aam_max_tokens', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
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
        $wc_max_products  = get_option( 'aam_wc_max_products', 30 );
        $custom_prompt    = get_option( 'aam_custom_system_instruction', '' );
        $chatbot_name     = get_option( 'aam_chatbot_name', 'Arrodes Assistant' );
        $welcome_message  = get_option( 'aam_welcome_message', 'Hi! How can I help you today?' );
        $include_all      = get_option( 'aam_include_all_pages', '1' );
        $primary_color    = get_option( 'aam_primary_color', '#14bda9' );
        $theme_style      = get_option( 'aam_theme_style', 'light' );
        $widget_position  = get_option( 'aam_widget_position', 'bottom-right' );
        $bubble_text      = get_option( 'aam_bubble_text', 'Ask Assistant' );
        $avatar_icon      = get_option( 'aam_avatar_icon', '💬' );

        $support_email    = get_option( 'aam_support_email', '' );
        $chat_width       = get_option( 'aam_chat_width', '380px' );
        $chat_height      = get_option( 'aam_chat_height', '520px' );
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
        $search_limit = get_option( 'aam_search_limit', 8 );
        $index_stats  = class_exists( 'AAM_Indexer' ) ? AAM_Indexer::get_index_stats() : [
            'table_exists'     => false,
            'total_indexed'    => 0,
            'products_indexed' => 0,
            'pages_indexed'    => 0,
            'last_updated'     => 'Never',
        ];

        // Rate Limit, Security & TTL Options
        $chatbot_enabled   = get_option( 'aam_chatbot_enabled', '1' );
        $rate_limit_count  = get_option( 'aam_rate_limit_count', 30 );
        $rate_limit_window = get_option( 'aam_rate_limit_window', 10 );
        $session_ttl       = get_option( 'aam_session_ttl', 30 );
        $max_tokens        = get_option( 'aam_max_tokens', 500 );

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

            <form method="post" action="options.php">
                <?php settings_fields( 'aam_settings_group' ); ?>

                <div class="aam-layout-grid">
                    <!-- Left Vertical Sidebar Tabs -->
                    <div class="aam-sidebar-nav">
                        <div class="aam-nav-item active" data-tab="tab-identity">
                            <span class="dashicons dashicons-format-chat"></span>
                            <span>Chat Identity & Content</span>
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
                        </div>

                        <!-- TAB 2: Appearance & Theme -->
                        <div id="tab-style" class="aam-tab-panel">
                            <h2 class="aam-section-title">
                                <span class="dashicons dashicons-art" style="color:#14bda9;"></span>
                                Appearance, Logo & Theme Styling
                            </h2>

                            <!-- Custom Avatar / Logo Image -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_wl_custom_logo">Custom Avatar / Logo Image</label>
                                    <p class="aam-field-desc">Upload a custom logo image to display on chat header and launcher button.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <div class="aam-image-picker-group">
                                        <input type="url" id="aam_wl_custom_logo" name="aam_wl_custom_logo" value="<?php echo esc_attr( $wl_custom_logo ); ?>" class="aam-input-text" placeholder="https://your-domain.com/uploads/logo.png" />
                                        <button type="button" class="aam-btn-secondary" id="aam_upload_logo_btn">Choose Logo</button>
                                    </div>
                                    <div class="aam-size-group" style="margin-top:10px;">
                                        <div class="aam-size-input-box">
                                            <span>Logo Width:</span>
                                            <input type="text" id="aam_wl_logo_width" name="aam_wl_logo_width" value="<?php echo esc_attr( $wl_logo_width ); ?>" />
                                        </div>
                                        <div class="aam-size-input-box">
                                            <span>Logo Height:</span>
                                            <input type="text" id="aam_wl_logo_height" name="aam_wl_logo_height" value="<?php echo esc_attr( $wl_logo_height ); ?>" />
                                        </div>
                                        <span style="font-size:10px; color:#94a3b8; font-style:italic;">Default: 60px</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Primary Theme Color -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_primary_color">Primary Theme Color</label>
                                    <p class="aam-field-desc">Set the primary brand color for buttons, chat header, active tabs, and links.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="color" id="aam_primary_color" name="aam_primary_color" value="<?php echo esc_attr( $primary_color ); ?>" style="height:36px; width:50px; padding:2px; cursor:pointer;" />
                                    <input type="text" class="aam-input-text" style="width:120px;" value="<?php echo esc_attr( $primary_color ); ?>" readonly />
                                </div>
                            </div>

                            <!-- Chat Panel Visual Style -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label for="aam_theme_style">Chat Panel Visual Style</label>
                                    <p class="aam-field-desc">Select visual color theme for front-end widget.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <select id="aam_theme_style" name="aam_theme_style" class="aam-select">
                                        <option value="light" <?php selected( $theme_style, 'light' ); ?>>Modern Light (Clean White & Subtle Shadows)</option>
                                        <option value="dark" <?php selected( $theme_style, 'dark' ); ?>>Obsidian Dark Glass (Sleek Dark Mode)</option>
                                        <option value="minimal" <?php selected( $theme_style, 'minimal' ); ?>>Minimalist Border (Flat Light Accent)</option>
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
                                    <p class="aam-field-desc">Specific model string ID.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="text" id="aam_model_name" name="aam_model_name" value="<?php echo esc_attr( $model_name ); ?>" class="aam-input-text" placeholder="e.g. gemini-2.5-flash, deepseek-chat" />
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
                                Search Engine & Indexing Configuration
                            </h2>

                            <!-- Index Health Stats Box -->
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:24px;">
                                <h3 style="margin-top:0; color:#0f172a; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <span class="dashicons dashicons-database" style="color:#14bda9;"></span>
                                    Content Index Status
                                </h3>
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:16px; margin:16px 0;">
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#0f172a;" id="aam_stat_total"><?php echo esc_html( $index_stats['total_indexed'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Total Items</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#0284c7;" id="aam_stat_products"><?php echo esc_html( $index_stats['products_indexed'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Products</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:20px; font-weight:700; color:#059669;" id="aam_stat_pages"><?php echo esc_html( $index_stats['pages_indexed'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Pages / Posts</div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; padding:12px; border-radius:8px; text-align:center;">
                                        <div style="font-size:13px; font-weight:600; color:#334155; margin-top:4px;" id="aam_stat_updated"><?php echo esc_html( $index_stats['last_updated'] ); ?></div>
                                        <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; margin-top:2px;">Last Sync</div>
                                    </div>
                                </div>

                                <div style="display:flex; align-items:center; gap:16px; margin-top:12px;">
                                    <button type="button" class="aam-btn-primary" id="aam_reindex_btn">Reindex All Content Now</button>
                                    <div id="aam_reindex_status" style="display:none; font-size:13px; font-weight:600;"></div>
                                </div>
                            </div>

                            <!-- Auto Indexing Toggle -->
                            <div class="aam-field-row">
                                <div class="aam-field-label-group">
                                    <label>Automatic Post & Product Indexing</label>
                                    <p class="aam-field-desc">Automatically update search index whenever a product or page is saved or edited.</p>
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
                                    <label for="aam_search_limit">Search Results Limit Per Query</label>
                                    <p class="aam-field-desc">Max number of top matching products and pages injected into AI context per question.</p>
                                </div>
                                <div class="aam-field-control-group">
                                    <input type="number" id="aam_search_limit" name="aam_search_limit" value="<?php echo esc_attr( $search_limit ); ?>" class="aam-input-text" style="width:120px;" min="2" max="25" />
                                </div>
                            </div>

                            <!-- Live Test Search Box -->
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-top:24px;">
                                <h3 style="margin-top:0; color:#0f172a; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <span class="dashicons dashicons-search" style="color:#14bda9;"></span>
                                    Test Search Index Live
                                </h3>
                                <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Type a product keyword or question to test what items the search engine retrieves for AI context:</p>

                                <div style="display:flex; gap:10px; margin-bottom:16px;">
                                    <input type="text" id="aam_test_query" class="aam-input-text" placeholder="e.g. Shawl, Speaker, Delivery, Under 5000" style="flex:1;" />
                                    <button type="button" class="aam-btn-primary" id="aam_test_search_btn">Test Search</button>
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

                navItems.forEach(item => {
                    item.addEventListener('click', function() {
                        navItems.forEach(i => i.classList.remove('active'));
                        panels.forEach(p => p.classList.remove('active'));

                        this.classList.add('active');
                        const target = this.getAttribute('data-tab');
                        const panel = document.getElementById(target);
                        if (panel) panel.classList.add('active');
                    });
                });

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

        if ( class_exists( 'AAM_Search' ) ) {
            delete_transient( 'aam_srch_' . md5( strtolower( trim( $query ) ) . '_' . $limit ) );
            $results = AAM_Search::find_relevant( $query, $limit );
            wp_send_json_success( [ 'results' => $results, 'count' => count( $results ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Search engine module unavailable.', 'arrodes-ai-manager' ) ] );
        }
    }

}
