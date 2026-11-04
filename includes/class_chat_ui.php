<?php
defined( 'ABSPATH' ) || exit;

class AAM_Chat_UI {

    public function __construct() {
        $this->init();
    }

    private function init() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_footer', [ $this, 'render_chat' ] );

        add_action( 'wp_ajax_aam_send_message',        [ $this, 'handle_message' ] );
        add_action( 'wp_ajax_nopriv_aam_send_message', [ $this, 'handle_message' ] );

        add_action( 'wp_ajax_aam_reset_chat',         [ $this, 'reset_chat' ] );
        add_action( 'wp_ajax_nopriv_aam_reset_chat',  [ $this, 'reset_chat' ] );

        add_action( 'wp_ajax_aam_get_history',        [ $this, 'get_history' ] );
        add_action( 'wp_ajax_nopriv_aam_get_history', [ $this, 'get_history' ] );

        add_action( 'wp_ajax_aam_save_feedback',        [ $this, 'handle_feedback' ] );
        add_action( 'wp_ajax_nopriv_aam_save_feedback', [ $this, 'handle_feedback' ] );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'aam-chat', AAM_URL . 'assets/chat.css', [], AAM_VERSION );
        wp_enqueue_script( 'aam-chat', AAM_URL . 'assets/chat.js', [], AAM_VERSION, true );

        $primary_color   = sanitize_hex_color( get_option( 'aam_primary_color', '#14bda9' ) ) ?: '#14bda9';
        $theme_style     = get_option( 'aam_theme_style', 'light' );
        $widget_position = get_option( 'aam_widget_position', 'bottom-right' );
        
        $logo_width      = preg_match( '/^\d{1,4}(px|%|em|rem)$/i', get_option( 'aam_wl_logo_width', '60px' ) ) ? get_option( 'aam_wl_logo_width', '60px' ) : '60px';
        $logo_height     = preg_match( '/^\d{1,4}(px|%|em|rem)$/i', get_option( 'aam_wl_logo_height', '60px' ) ) ? get_option( 'aam_wl_logo_height', '60px' ) : '60px';
        $chat_width      = preg_match( '/^\d{1,4}(px|%|em|rem)$/i', get_option( 'aam_chat_width', '380px' ) ) ? get_option( 'aam_chat_width', '380px' ) : '380px';
        $chat_height     = preg_match( '/^\d{1,4}(px|%|em|rem)$/i', get_option( 'aam_chat_height', '520px' ) ) ? get_option( 'aam_chat_height', '520px' ) : '520px';

        // Inject dynamic styling variables securely
        $custom_css = "
            :root {
                --aam-primary: {$primary_color};
                --aam-logo-width: {$logo_width};
                --aam-logo-height: {$logo_height};
                --aam-chat-width: {$chat_width};
                --aam-chat-height: {$chat_height};
            }
        ";
        wp_add_inline_style( 'aam-chat', $custom_css );

        $auto_open      = get_option( 'aam_auto_open', '0' );
        $streaming_mode = get_option( 'aam_streaming_mode', '1' );
        $launcher_anim  = get_option( 'aam_launcher_animation', 'pulse' );
        $suggested_q    = get_option( 'aam_suggested_questions', "What are your top products?\nDo you have any discount codes?\nTell me about shipping & returns" );
        $suggested_arr  = array_values( array_filter( array_map( 'trim', explode( "\n", $suggested_q ) ) ) );

        wp_localize_script( 'aam-chat', 'AAM', [
            'ajax_url'            => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'aam_chat_nonce' ),
            'auto_open'           => $auto_open === '1',
            'streaming'           => $streaming_mode === '1',
            'launcher_animation'  => $launcher_anim,
            'suggested_questions' => $suggested_arr,
        ]);
    }

    public function render_chat() {
        if ( get_option( 'aam_chatbot_enabled', '1' ) === '0' ) {
            return;
        }

        // Check page visibility rules
        if ( is_front_page() && get_option( 'aam_hide_on_front', '0' ) === '1' ) {
            return;
        }
        if ( function_exists( 'is_cart' ) && is_cart() && get_option( 'aam_hide_on_cart', '0' ) === '1' ) {
            return;
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() && get_option( 'aam_hide_on_checkout', '0' ) === '1' ) {
            return;
        }
        $hide_ids_raw = get_option( 'aam_hide_page_ids', '' );
        if ( ! empty( $hide_ids_raw ) ) {
            $hide_ids = array_map( 'absint', array_filter( array_map( 'trim', explode( ',', $hide_ids_raw ) ) ) );
            if ( is_singular() && in_array( get_queried_object_id(), $hide_ids, true ) ) {
                return;
            }
        }

        $site_name       = get_bloginfo( 'name' );
        $chatbot_name    = get_option( 'aam_chatbot_name', 'Arrodes Assistant' );
        $welcome_message = get_option( 'aam_welcome_message', 'Hi! How can I help you today?' );
        $bubble_text     = get_option( 'aam_bubble_text', 'Ask Assistant' );
        $avatar_icon     = get_option( 'aam_avatar_icon', '💬' );

        $theme_style     = get_option( 'aam_theme_style', 'light' );
        $widget_position = get_option( 'aam_widget_position', 'bottom-right' );
        $launcher_anim   = get_option( 'aam_launcher_animation', 'pulse' );

        $wl_custom_logo  = get_option( 'aam_wl_custom_logo', '' );

        $position_class  = $widget_position === 'bottom-left' ? 'aam-pos-left' : 'aam-pos-right';
        $theme_class     = 'aam-theme-' . esc_attr( $theme_style );
        $anim_class      = ! empty( $launcher_anim ) && $launcher_anim !== 'none' ? 'aam-anim-' . esc_attr( $launcher_anim ) : '';
        ?>

        <!-- Chat Bubble -->
        <div id="aam-bubble" class="<?php echo esc_attr( $position_class . ' ' . $anim_class ); ?>" role="button" aria-label="<?php echo esc_attr__( 'Open Chat', 'arrodes-ai-manager' ); ?>" tabindex="0">
            <?php if ( ! empty( $wl_custom_logo ) ) : ?>
                <img src="<?php echo esc_url( $wl_custom_logo ); ?>" alt="Logo" class="aam-bubble-logo" />
            <?php else : ?>
                <span id="aam-bubble-icon"><?php echo esc_html( $avatar_icon ); ?></span>
            <?php endif; ?>
            <span id="aam-bubble-text"><?php echo esc_html( $bubble_text ); ?></span>
        </div>

        <!-- Chat Window -->
        <div id="aam-window" class="<?php echo esc_attr( $position_class . ' ' . $theme_class ); ?>" style="display:none;" aria-hidden="true">

            <!-- Header -->
            <div id="aam-header">
                <div class="aam-header-info">
                    <?php if ( ! empty( $wl_custom_logo ) ) : ?>
                        <img src="<?php echo esc_url( $wl_custom_logo ); ?>" alt="Logo" class="aam-header-logo aam-header-logo-open" />
                    <?php else : ?>
                        <span class="aam-header-avatar"><?php echo esc_html( $avatar_icon ); ?></span>
                    <?php endif; ?>
                    <div class="aam-header-meta">
                        <span class="aam-header-title"><?php echo esc_html( $chatbot_name ); ?></span>
                        <span class="aam-header-status"><span class="aam-status-dot"></span> <?php esc_html_e( 'Online • AI Assistant', 'arrodes-ai-manager' ); ?></span>
                    </div>
                </div>
                <div class="aam-header-actions">
                    <button id="aam-refresh" type="button" title="<?php echo esc_attr__( 'Refresh Chat / Start Fresh', 'arrodes-ai-manager' ); ?>" aria-label="<?php echo esc_attr__( 'Refresh Chat', 'arrodes-ai-manager' ); ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path></svg>
                    </button>
                    <button id="aam-close" type="button" aria-label="<?php echo esc_attr__( 'Close Chat', 'arrodes-ai-manager' ); ?>">&times;</button>
                </div>
            </div>

            <!-- Messages Area -->
            <div id="aam-messages" aria-live="polite">
                <!-- Welcome message -->
                <div class="aam-message aam-bot">
                    <?php echo esc_html( $welcome_message ); ?>
                </div>
            </div>

            <!-- Suggested Questions Container -->
            <div id="aam-suggested-wrapper" style="display:none;"></div>

            <!-- Input Area -->
            <div id="aam-input-area">
                <input type="text" id="aam-input" placeholder="<?php echo esc_attr__( 'Type your question...', 'arrodes-ai-manager' ); ?>" maxlength="1000" />
                <button id="aam-send"><?php echo esc_html__( 'Send', 'arrodes-ai-manager' ); ?></button>
            </div>

            <!-- Privacy Notice Disclaimer -->
            <div id="aam-privacy-notice">
                AI can make mistakes. 🔒 Don't share any private information in the chat
            </div>

            <!-- Footer Branding -->
            <div id="aam-footer-branding">
                <span>Developed By <a href="https://lmwebdesigners.com/" target="_blank" rel="noopener noreferrer">LM Designers</a> x <a href="https://www.linkedin.com/in/drsmok3y/" target="_blank" rel="noopener noreferrer">DrSmoK3y</a></span>
            </div>

        </div>

        <?php
    }

    public function handle_message() {
        if ( ! check_ajax_referer( 'aam_chat_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed or session expired. Please refresh the page.', 'arrodes-ai-manager' ) ] );
        }

        if ( get_option( 'aam_chatbot_enabled', '1' ) === '0' ) {
            wp_send_json_error( [ 'message' => __( 'Chatbot is currently disabled by administrator.', 'arrodes-ai-manager' ) ] );
        }

        $session_id = $this->get_or_create_session_id();
        $message    = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

        if ( empty( trim( $message ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Message cannot be empty.', 'arrodes-ai-manager' ) ] );
        }

        if ( mb_strlen( $message ) > 2000 ) {
            wp_send_json_error( [ 'message' => __( 'Message length exceeds limit.', 'arrodes-ai-manager' ) ] );
        }

        // Duplicate-message throttle (ignore exact same message sent within 5 seconds)
        $dup_key  = 'aam_last_msg_' . md5( $session_id );
        $last_msg = get_transient( $dup_key );
        if ( $last_msg && $last_msg === md5( $message ) ) {
            wp_send_json_error( [ 'message' => __( 'Duplicate message detected. Please wait a moment before sending again.', 'arrodes-ai-manager' ) ] );
        }
        set_transient( $dup_key, md5( $message ), 5 );

        // Dual IP + Session Rate Limiting Check
        $limit_count  = (int) get_option( 'aam_rate_limit_count', 30 );
        $limit_window = max( 60, (int) get_option( 'aam_rate_limit_window', 10 ) * 60 );

        $ip_key      = 'aam_rate_ip_' . md5( $this->get_client_ip() );
        $session_key = 'aam_rate_' . md5( $session_id );

        $ip_count      = (int) get_transient( $ip_key );
        $session_count = (int) get_transient( $session_key );

        if ( $ip_count >= $limit_count || $session_count >= $limit_count ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please wait before sending more messages.', 'arrodes-ai-manager' ) ] );
        }

        set_transient( $ip_key, $ip_count + 1, $limit_window );
        set_transient( $session_key, $session_count + 1, $limit_window );

        $session_history   = $this->get_session_history( $session_id );
        $history_formatted = [];
        if ( ! empty( $session_history ) && is_array( $session_history ) ) {
            $recent = array_slice( $session_history, -3 );
            foreach ( $recent as $row ) {
                if ( ! empty( $row['message'] ) ) {
                    $history_formatted[] = [ 'role' => 'user', 'content' => $row['message'] ];
                }
                if ( ! empty( $row['response'] ) ) {
                    $history_formatted[] = [ 'role' => 'assistant', 'content' => $row['response'] ];
                }
            }
        }

        // Confidence Threshold & Cost-Saver Guard:
        // If user asks a product, pricing, or policy question and no chunks match or score is below threshold,
        // stop hallucinations and avoid wasting API costs by returning immediate verified fallback.
        $strict_ground = get_option( 'aam_strict_grounding', '1' );
        $threshold     = (float) get_option( 'aam_similarity_threshold', '0.45' );
        $preprocessed  = class_exists( 'AAM_Query_Preprocessor' ) ? AAM_Query_Preprocessor::process( $message, $history_formatted ) : null;
        $is_factual    = $preprocessed && in_array( $preprocessed['intent'], [ 'pricing', 'policy', 'product' ], true );

        if ( $strict_ground === '1' && $is_factual && class_exists( 'AAM_Hybrid_Search' ) ) {
            $top_chunks = AAM_Hybrid_Search::search( $message, 1 );
            if ( empty( $top_chunks ) || ( isset( $top_chunks[0]['score'] ) && $top_chunks[0]['score'] < $threshold && empty( $top_chunks[0]['title_boost'] ) ) ) {
                $this->log_unanswered_question( $message, $preprocessed['intent'] ?? 'general', $top_chunks[0]['score'] ?? 0.0 );

                $support_mail = get_option( 'aam_support_email' ) ?: get_option( 'admin_email' );
                $contact_page = get_page_by_path( 'contact' ) ?: get_page_by_path( 'contact-us' );
                $contact_link = $contact_page ? get_permalink( $contact_page->ID ) : home_url( '/contact/' );
                $wa_number    = get_option( 'aam_whatsapp_number', '' );
                $clean_wa     = preg_replace( '/[^0-9]/', '', $wa_number );

                $fallback_text = "I don't have the exact verified details on that on our website right now. Please reach out to our team at {$support_mail} or via our [Contact Page]({$contact_link}) so we can provide you with personalized assistance.";
                if ( ! empty( $clean_wa ) ) {
                    $wa_url = "https://wa.me/{$clean_wa}?text=" . rawurlencode( "Salam! I was asking your AI Assistant regarding '{$message}'. Could you assist me?" );
                    $fallback_text .= "\n\n💬 **Direct WhatsApp Support:**\n[Click here to chat with our team on WhatsApp]({$wa_url})";
                }

                $this->save_to_log( $session_id, $message, $fallback_text );

                wp_send_json_success( [
                    'message'  => esc_html( $message ),
                    'response' => $fallback_text,
                ] );
                return;
            }
        }

        $ai       = new AAM_AI();
        $response = $ai->send_message( $message, $history_formatted );

        // Live WooCommerce Product & WhatsApp Data Enrichment
        $response = $this->enrich_product_response( $response, $message );

        $this->save_to_log( $session_id, $message, $response );

        // Discard any accidental PHP notices, deprecation warnings, or buffer noise
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }

        // Guarantee valid UTF-8 string so wp_send_json_success never fails
        if ( is_string( $response ) ) {
            $response = wp_check_invalid_utf8( $response, true );
        }

        wp_send_json_success( [
            'message'  => esc_html( $message ),
            'response' => $response,
        ] );
    }

    /**
     * Log unanswered query into database for admin knowledge gap dashboard
     */
    public function log_unanswered_question( $query, $intent = 'general', $score = 0.0 ) {
        $logs = get_option( 'aam_unanswered_questions', [] );
        if ( ! is_array( $logs ) ) $logs = [];
        array_unshift( $logs, [
            'id'        => 'uq_' . time() . '_' . wp_rand( 100, 999 ),
            'query'     => sanitize_text_field( $query ),
            'intent'    => sanitize_text_field( $intent ),
            'score'     => round( (float) $score, 3 ),
            'timestamp' => current_time( 'mysql' ),
        ] );
        if ( count( $logs ) > 100 ) {
            $logs = array_slice( $logs, 0, 100 );
        }
        update_option( 'aam_unanswered_questions', $logs, false );
    }

    /**
     * AJAX handler for user feedback (thumbs up / down)
     */
    public function handle_feedback() {
        check_ajax_referer( 'aam_chat_nonce', 'nonce' );
        $rating  = isset( $_POST['rating'] ) ? sanitize_text_field( $_POST['rating'] ) : 'up';
        $query   = isset( $_POST['query'] ) ? sanitize_text_field( $_POST['query'] ) : '';
        $msg_id  = isset( $_POST['message_id'] ) ? sanitize_text_field( $_POST['message_id'] ) : '';

        $feedbacks = get_option( 'aam_chat_feedbacks', [] );
        if ( ! is_array( $feedbacks ) ) $feedbacks = [];
        array_unshift( $feedbacks, [
            'id'        => $msg_id ?: ( 'fb_' . time() ),
            'rating'    => ( $rating === 'down' ) ? 'down' : 'up',
            'query'     => $query,
            'timestamp' => current_time( 'mysql' ),
        ] );
        if ( count( $feedbacks ) > 100 ) {
            $feedbacks = array_slice( $feedbacks, 0, 100 );
        }
        update_option( 'aam_chat_feedbacks', $feedbacks, false );
        wp_send_json_success( [ 'saved' => true ] );
    }

    /**
     * Enrich product recommendation JSON and text with authentic WooCommerce images, prices, stock, and WhatsApp links
     */
    private function enrich_product_response( $response, $message = '' ) {
        if ( empty( $response ) || ! is_string( $response ) ) {
            return $response;
        }

        $wa_number = get_option( 'aam_whatsapp_number', '' );
        $clean_wa  = preg_replace( '/[^0-9]/', '', $wa_number );

        // 1. Check if the AI returned a product_recommendation JSON block
        if ( strpos( $response, '"products"' ) !== false || strpos( $response, 'product_recommendation' ) !== false ) {
            $json_str = $response;
            $has_fences = false;
            if ( preg_match( '/```(?:json)?\s*([\s\S]*?)(?:```)?$/i', $response, $m ) ) {
                $json_str = trim( $m[1] );
                $has_fences = true;
            }

            $decoded = json_decode( $json_str, true );
            if ( is_array( $decoded ) && ! empty( $decoded['products'] ) && is_array( $decoded['products'] ) ) {
                $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : 'Rs.';

                foreach ( $decoded['products'] as &$prod ) {
                    $prod_title = $prod['title'] ?? '';
                    $wc_p = null;

                    // Try to find matching WooCommerce product
                    if ( function_exists( 'wc_get_products' ) && ! empty( $prod_title ) ) {
                        $matches = wc_get_products( [ 's' => $prod_title, 'status' => 'publish', 'limit' => 1 ] );
                        if ( ! empty( $matches[0] ) ) {
                            $wc_p = $matches[0];
                        }
                    }

                    if ( $wc_p ) {
                        $pid = $wc_p->get_id();
                        // Live verified image
                        $img_id = $wc_p->get_image_id();
                        if ( $img_id ) {
                            $real_img = wp_get_attachment_image_url( $img_id, 'large' );
                            if ( $real_img ) {
                                $prod['image_url'] = $real_img;
                            }
                        }
                        // Live price
                        $raw_price = $wc_p->get_price();
                        if ( ! empty( $raw_price ) && is_numeric( $raw_price ) ) {
                            $prod['price'] = $symbol . ' ' . number_format( (float) $raw_price, 2 );
                        }
                        // Live stock
                        $prod['stock'] = $wc_p->is_in_stock() ? 'In Stock' : 'Out of Stock';
                        // Server-side verified add-to-cart
                        $prod['add_to_cart_url'] = function_exists( 'wc_get_checkout_url' )
                            ? add_query_arg( 'add-to-cart', $pid, wc_get_checkout_url() )
                            : home_url( "/cart/?add-to-cart={$pid}" );
                        // Permanent link
                        if ( empty( $prod['permalink'] ) || strpos( $prod['permalink'], 'example.com' ) !== false ) {
                            $prod['permalink'] = get_permalink( $pid );
                        }
                        // WhatsApp link
                        if ( ! empty( $clean_wa ) ) {
                            $wa_text = "Salam! I want to order {$prod_title} ({$prod['price']}) on " . get_bloginfo( 'name' ) . ". Is Cash on Delivery available?";
                            $prod['whatsapp_url'] = "https://wa.me/{$clean_wa}?text=" . rawurlencode( $wa_text );
                        }
                    } elseif ( ! empty( $clean_wa ) && empty( $prod['whatsapp_url'] ) ) {
                        $wa_text = "Salam! I am interested in " . ( $prod['title'] ?? 'this product' ) . ". Can you provide details?";
                        $prod['whatsapp_url'] = "https://wa.me/{$clean_wa}?text=" . rawurlencode( $wa_text );
                    }
                }
                unset( $prod );

                $new_json = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                return $has_fences ? "```json\n" . $new_json . "\n```" : $new_json;
            }
        }

        // 2. If user message asked for contact, support, or WhatsApp and WhatsApp is enabled
        if ( ! empty( $clean_wa ) && preg_match( '/(whatsapp|wa\.me|contact|support|call|phone|rabta|human|agent)/i', $message ) ) {
            if ( strpos( $response, 'wa.me' ) === false ) {
                $wa_url = "https://wa.me/{$clean_wa}?text=" . rawurlencode( "Salam! I was chatting with your AI Assistant regarding: '{$message}'. Please connect me with your team." );
                $response .= "\n\n💬 **Direct WhatsApp Support:**\n[Chat with our team on WhatsApp]({$wa_url})";
            }
        }

        return $response;
    }

    public function get_history() {
        if ( ! check_ajax_referer( 'aam_chat_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed or session expired. Please refresh the page.', 'arrodes-ai-manager' ) ] );
        }

        // IP Rate Limit Protection against automated history scraping
        $limit_count  = (int) get_option( 'aam_rate_limit_count', 30 );
        $limit_window = max( 60, (int) get_option( 'aam_rate_limit_window', 10 ) * 60 );
        $ip_key       = 'aam_rate_ip_' . md5( $this->get_client_ip() );
        $ip_count     = (int) get_transient( $ip_key );

        if ( $ip_count >= $limit_count ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please wait.', 'arrodes-ai-manager' ) ] );
        }

        $session_id  = $this->get_or_create_session_id();
        $raw_history = $this->get_session_history( $session_id );

        $sanitized_history = [];
        if ( is_array( $raw_history ) ) {
            foreach ( $raw_history as $row ) {
                $sanitized_history[] = [
                    'message'  => esc_html( isset( $row['message'] ) ? $row['message'] : '' ),
                    'response' => esc_html( isset( $row['response'] ) ? $row['response'] : '' ),
                ];
            }
        }

        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }

        wp_send_json_success( $sanitized_history );
    }

    private function get_client_ip() {
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';

        // 1. Cloudflare verification — only trust CF-Connecting-IP if REMOTE_ADDR is in verified Cloudflare IP ranges
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && $this->is_cloudflare_ip( $remote ) ) {
            $cf_ip = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
            if ( filter_var( $cf_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $cf_ip;
            }
        }

        // 2. Trusted reverse proxy check — only trust X-Forwarded-For if REMOTE_ADDR is an explicitly whitelisted proxy
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && $this->is_trusted_proxy( $remote ) ) {
            $parts  = array_map( 'trim', explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $client = $parts[0]; // Leftmost is the originating client IP
            if ( filter_var( $client, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $client;
            }
        }

        // 3. Strict fallback to REMOTE_ADDR (tamper-proof TCP peer address)
        return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
    }

    private function is_cloudflare_ip( $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        // Cloudflare official published IP ranges (IPv4 and IPv6)
        $cf_ranges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
            '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
            '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
            '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'
        ];

        foreach ( $cf_ranges as $range ) {
            if ( $this->ip_in_range( $ip, $range ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_trusted_proxy( $ip ) {
        $trusted = apply_filters( 'aam_trusted_proxies', [ '127.0.0.1', '::1' ] );
        return is_array( $trusted ) && in_array( $ip, $trusted, true );
    }

    private function ip_in_range( $ip, $range ) {
        if ( strpos( $range, '/' ) === false ) {
            return $ip === $range;
        }

        list( $subnet, $bits ) = explode( '/', $range );
        $bits = (int) $bits;

        // IPv4 CIDR check
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            if ( $bits < 0 || $bits > 32 ) return false;
            $ip_dec     = ip2long( $ip );
            $subnet_dec = ip2long( $subnet );
            $mask       = -1 << ( 32 - $bits );
            return ( ( $ip_dec & $mask ) === ( $subnet_dec & $mask ) );
        }

        // IPv6 CIDR check
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            if ( $bits < 0 || $bits > 128 ) return false;
            $ip_bin     = @inet_pton( $ip );
            $subnet_bin = @inet_pton( $subnet );
            if ( false === $ip_bin || false === $subnet_bin ) return false;

            $full_bytes = intdiv( $bits, 8 );
            $remainder  = $bits % 8;

            if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $subnet_bin, 0, $full_bytes ) ) {
                return false;
            }

            if ( $remainder > 0 ) {
                $mask = 0xFF << ( 8 - $remainder ) & 0xFF;
                if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) !== ( ord( $subnet_bin[ $full_bytes ] ) & $mask ) ) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function get_or_create_session_id() {
        $cookie_name = 'aam_session_token';
        if ( ! empty( $_COOKIE[$cookie_name] ) ) {
            $token = sanitize_text_field( wp_unslash( $_COOKIE[$cookie_name] ) );
            if ( preg_match( '/^[a-f0-9]{32,64}$/i', $token ) ) {
                return $token;
            }
        }

        if ( function_exists( 'random_bytes' ) ) {
            $new_token = bin2hex( random_bytes( 16 ) );
        } else {
            $new_token = md5( wp_generate_password( 32, true, true ) . microtime( true ) );
        }

        setcookie( $cookie_name, $new_token, [
            'expires'  => time() + ( 86400 * 30 ),
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );

        return $new_token;
    }

    // Save message pair into a single transient per session (auto-expires with no DB table bloat)
    private function save_to_log( $session_id, $message, $response ) {
        $ttl_minutes = (int) get_option( 'aam_session_ttl', 30 );
        $ttl_seconds = max( 60, $ttl_minutes * 60 );

        $key     = 'aam_session_' . md5( $session_id );
        $history = get_transient( $key );
        $history = is_array( $history ) ? $history : [];

        $history[] = [
            'message'    => $message,
            'response'   => $response,
            'created_at' => time(),
        ];

        // Keep only last 50 exchanges max
        if ( count( $history ) > 50 ) {
            $history = array_slice( $history, -50 );
        }

        set_transient( $key, $history, $ttl_seconds );
    }

    private function get_session_history( $session_id ) {
        $key     = 'aam_session_' . md5( $session_id );
        $history = get_transient( $key );
        return is_array( $history ) ? $history : [];
    }

    public function reset_chat() {
        if ( ! check_ajax_referer( 'aam_chat_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'arrodes-ai-manager' ) ] );
        }

        $session_id = $this->get_or_create_session_id();
        if ( ! empty( $session_id ) ) {
            delete_transient( 'aam_session_' . md5( $session_id ) );
            delete_transient( 'aam_last_msg_' . md5( $session_id ) );
        }

        wp_send_json_success( [ 'message' => __( 'Conversation reset successfully.', 'arrodes-ai-manager' ) ] );
    }

}
