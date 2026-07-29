<?php
defined( 'ABSPATH' ) || exit;

class AAM_Shortcode_Elementor {

    public function __construct() {
        // Register Shortcodes
        add_shortcode( 'arrodes_ai_chat', [ $this, 'render_shortcode' ] );
        add_shortcode( 'arrodes_chat',    [ $this, 'render_shortcode' ] );

        // Register Elementor Widget
        add_action( 'elementor/widgets/register', [ $this, 'register_elementor_widget' ] );
        add_action( 'elementor/widgets/widgets_registered', [ $this, 'register_elementor_widget' ] );
    }

    /**
     * Render AI Chat Shortcode [arrodes_ai_chat width="100%" height="550px" theme="light"]
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'width'           => '100%',
            'height'          => '520px',
            'theme'           => get_option( 'aam_theme_style', 'light' ),
            'primary_color'   => get_option( 'aam_primary_color', '#14bda9' ),
            'chatbot_name'    => get_option( 'aam_chatbot_name', 'Arrodes Assistant' ),
            'welcome_message' => get_option( 'aam_welcome_message', 'Hi! How can I help you today?' ),
            'avatar_icon'     => get_option( 'aam_avatar_icon', '💬' ),
            'border_radius'   => '16px',
        ], $atts, 'arrodes_ai_chat' );

        // Ensure scripts & styles are loaded
        if ( class_exists( 'AAM_Chat_UI' ) ) {
            $ui = new AAM_Chat_UI();
            $ui->enqueue_assets();
        }

        $unique_id        = 'aam-embed-' . wp_rand( 1000, 9999 );
        $theme_class      = 'aam-theme-' . esc_attr( $atts['theme'] );
        $suggested_q      = get_option( 'aam_suggested_questions', "What are your top products?\nDo you have any discount codes?\nTell me about shipping & returns" );
        $suggested_arr    = array_values( array_filter( array_map( 'trim', explode( "\n", $suggested_q ) ) ) );

        $wl_custom_logo   = get_option( 'aam_wl_custom_logo', '' );

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $unique_id ); ?>" class="aam-embed-wrapper" style="width: <?php echo esc_attr( $atts['width'] ); ?>; max-width: 100%;">
            <style type="text/css">
                #<?php echo esc_attr( $unique_id ); ?> .aam-window-embedded {
                    width: 100% !important;
                    height: <?php echo esc_attr( $atts['height'] ); ?> !important;
                    max-width: 100% !important;
                    position: relative !important;
                    bottom: auto !important;
                    right: auto !important;
                    left: auto !important;
                    display: flex !important;
                    border-radius: <?php echo esc_attr( $atts['border_radius'] ); ?> !important;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.08) !important;
                    overflow: hidden !important;
                    font-family: inherit !important;
                }
                #<?php echo esc_attr( $unique_id ); ?> .aam-header-embedded {
                    background: <?php echo esc_attr( $atts['primary_color'] ); ?> !important;
                }
            </style>

            <div class="aam-window aam-window-embedded <?php echo esc_attr( $theme_class ); ?>" aria-hidden="false">
                <!-- Header -->
                <div id="aam-header" class="aam-header-embedded">
                    <div class="aam-header-info">
                        <?php if ( ! empty( $wl_custom_logo ) ) : ?>
                            <img src="<?php echo esc_url( $wl_custom_logo ); ?>" alt="Logo" class="aam-header-logo" />
                        <?php else : ?>
                            <span class="aam-header-avatar"><?php echo esc_html( $atts['avatar_icon'] ); ?></span>
                        <?php endif; ?>
                        <span class="aam-header-title"><?php echo esc_html( $atts['chatbot_name'] ); ?></span>
                    </div>
                </div>

                <!-- Messages Area -->
                <div class="aam-messages-embedded aam-messages-area" id="aam-messages-<?php echo esc_attr( $unique_id ); ?>" style="flex:1; padding:16px; overflow-y:auto;">
                    <div class="aam-message aam-bot">
                        <?php echo esc_html( $atts['welcome_message'] ); ?>
                    </div>
                </div>

                <!-- Suggested Questions Chips -->
                <?php if ( ! empty( $suggested_arr ) ) : ?>
                    <div class="aam-suggested-box">
                        <span class="aam-suggested-title">Suggested Questions:</span>
                        <div class="aam-suggested-chips">
                            <?php foreach ( $suggested_arr as $q ) : ?>
                                <button type="button" class="aam-chip-btn" onclick="if(window.AAM_Send_Embed) window.AAM_Send_Embed('<?php echo esc_attr( $unique_id ); ?>', '<?php echo esc_js( $q ); ?>')">💬 <?php echo esc_html( $q ); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Input Area -->
                <div class="aam-input-area-embedded" style="display:flex; padding:12px; border-top:1px solid rgba(0,0,0,0.08); background:inherit; gap:8px;">
                    <input type="text" id="aam-input-<?php echo esc_attr( $unique_id ); ?>" class="aam-input-embedded" placeholder="<?php echo esc_attr__( 'Type your question...', 'arrodes-ai-manager' ); ?>" maxlength="1000" onkeypress="if(event.key==='Enter'){if(window.AAM_Send_Embed) window.AAM_Send_Embed('<?php echo esc_attr( $unique_id ); ?>', this.value);}" style="flex:1; border:1px solid #cbd5e1; border-radius:8px; padding:10px 14px; font-size:14px; outline:none;" />
                    <button type="button" class="aam-send-embedded" onclick="if(window.AAM_Send_Embed){ var inp=document.getElementById('aam-input-<?php echo esc_attr( $unique_id ); ?>'); window.AAM_Send_Embed('<?php echo esc_attr( $unique_id ); ?>', inp.value); }" style="padding:10px 18px; background:<?php echo esc_attr( $atts['primary_color'] ); ?>; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px;"><?php echo esc_html__( 'Send', 'arrodes-ai-manager' ); ?></button>
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
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Register Elementor Widget class
     */
    public function register_elementor_widget( $widgets_manager ) {
        if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
            return;
        }

        require_once AAM_PATH . 'includes/class_elementor_widget.php';

        if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
            $widgets_manager->register( new AAM_Elementor_Widget() );
        } elseif ( class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::instance()->widgets_manager->register_widget_type( new AAM_Elementor_Widget() );
        }
    }

}
