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

        add_action( 'wp_ajax_aam_get_history',        [ $this, 'get_history' ] );
        add_action( 'wp_ajax_nopriv_aam_get_history', [ $this, 'get_history' ] );
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

        $auto_open     = get_option( 'aam_auto_open', '0' );
        $launcher_anim = get_option( 'aam_launcher_animation', 'pulse' );
        $suggested_q   = get_option( 'aam_suggested_questions', "What are your top products?\nDo you have any discount codes?\nTell me about shipping & returns" );
        $suggested_arr = array_values( array_filter( array_map( 'trim', explode( "\n", $suggested_q ) ) ) );

        wp_localize_script( 'aam-chat', 'AAM', [
            'ajax_url'            => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'aam_chat_nonce' ),
            'auto_open'           => $auto_open === '1',
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
                        <img src="<?php echo esc_url( $wl_custom_logo ); ?>" alt="Logo" class="aam-header-logo" />
                    <?php else : ?>
                        <span class="aam-header-avatar"><?php echo esc_html( $avatar_icon ); ?></span>
                    <?php endif; ?>
                    <span class="aam-header-title"><?php echo esc_html( $chatbot_name ); ?></span>
                </div>
                <button id="aam-close" aria-label="<?php echo esc_attr__( 'Close Chat', 'arrodes-ai-manager' ); ?>">&times;</button>
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
        check_ajax_referer( 'aam_chat_nonce', 'nonce' );

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

        $ai       = new AAM_AI();
        $response = $ai->send_message( $message, $history_formatted );

        $this->save_to_log( $session_id, $message, $response );

        wp_send_json_success( [
            'message'  => esc_html( $message ),
            'response' => $response,
        ] );
    }

    public function get_history() {
        check_ajax_referer( 'aam_chat_nonce', 'nonce' );

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

        wp_send_json_success( $sanitized_history );
    }

    private function get_client_ip() {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
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

}
