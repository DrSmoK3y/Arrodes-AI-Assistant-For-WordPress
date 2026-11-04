<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
    return;
}

class AAM_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'arrodes_ai_chat';
    }

    public function get_title() {
        return __( 'Arrodes AI Chat', 'arrodes-ai-manager' );
    }

    public function get_icon() {
        return 'eicon-comments';
    }

    public function get_categories() {
        return [ 'general', 'basic' ];
    }

    public function get_keywords() {
        return [ 'ai', 'chat', 'chatbot', 'assistant', 'arrodes', 'support' ];
    }

    protected function register_controls() {

        // Content Section
        $this->start_controls_section(
            'section_content',
            [
                'label' => __( 'Chat Settings', 'arrodes-ai-manager' ),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'chatbot_name',
            [
                'label'       => __( 'Assistant Name', 'arrodes-ai-manager' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __( 'Arrodes AI Assistant', 'arrodes-ai-manager' ),
                'placeholder' => __( 'Enter bot title', 'arrodes-ai-manager' ),
            ]
        );

        $this->add_control(
            'welcome_message',
            [
                'label'       => __( 'Welcome Message', 'arrodes-ai-manager' ),
                'type'        => \Elementor\Controls_Manager::TEXTAREA,
                'default'     => __( 'Hello! Welcome to our store. How can I assist you today?', 'arrodes-ai-manager' ),
                'rows'        => 3,
            ]
        );

        $this->add_control(
            'avatar_icon',
            [
                'label'   => __( 'Avatar Emoji / Icon', 'arrodes-ai-manager' ),
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => '💬',
            ]
        );

        $this->end_controls_section();

        // Style Section
        $this->start_controls_section(
            'section_style',
            [
                'label' => __( 'Appearance & Styling', 'arrodes-ai-manager' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'theme',
            [
                'label'   => __( 'Theme Style', 'arrodes-ai-manager' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'light',
                'options' => [
                    'light' => __( 'Clean Light', 'arrodes-ai-manager' ),
                    'dark'  => __( 'Modern Dark', 'arrodes-ai-manager' ),
                ],
            ]
        );

        $this->add_control(
            'primary_color',
            [
                'label'     => __( 'Primary Accent Color', 'arrodes-ai-manager' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#14bda9',
            ]
        );

        $this->add_control(
            'height',
            [
                'label'       => __( 'Chat Container Height', 'arrodes-ai-manager' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => '550px',
                'placeholder' => '550px',
            ]
        );

        $this->add_control(
            'border_radius',
            [
                'label'       => __( 'Border Radius', 'arrodes-ai-manager' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => '16px',
                'placeholder' => '16px',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $shortcode = sprintf(
            '[arrodes_ai_chat width="100%%" height="%s" theme="%s" primary_color="%s" chatbot_name="%s" welcome_message="%s" avatar_icon="%s" border_radius="%s"]',
            esc_attr( $settings['height'] ),
            esc_attr( $settings['theme'] ),
            esc_attr( $settings['primary_color'] ),
            esc_attr( $settings['chatbot_name'] ),
            esc_attr( $settings['welcome_message'] ),
            esc_attr( $settings['avatar_icon'] ),
            esc_attr( $settings['border_radius'] )
        );

        echo do_shortcode( $shortcode );
    }

}
