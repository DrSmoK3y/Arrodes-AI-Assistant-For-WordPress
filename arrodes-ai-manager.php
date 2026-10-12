<?php
/**
 * Plugin Name: Arrodes AI Manager
 * Plugin URI:  https://github.com/DrSmoK3y/Arrodes-AI-Assistant-For-WordPress
 * Description: Intelligent AI Chatbot and Website Content Manager powered by OpenRouter / Gemini / DeepSeek API.
 * Version:     1.1.0
 * Author:      DrSmoK3y
 * Author URI:  https://www.linkedin.com/in/drsmok3y/
 * Donate link: https://lmdesigners.gumroad.com/coffee/
 * Text Domain: arrodes-ai-manager
 */

defined( 'ABSPATH' ) || exit;

// Define constants
define( 'AAM_VERSION',  '1.1.0' );
define( 'AAM_PATH',     plugin_dir_path( __FILE__ ) );
define( 'AAM_URL',      plugin_dir_url( __FILE__ ) );
define( 'AAM_BASENAME', plugin_basename( __FILE__ ) );

class Arrodes_AI_Manager {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
        $this->init_classes();
    }

    private function load_dependencies() {
        require_once AAM_PATH . 'includes/class_activator.php';
        require_once AAM_PATH . 'includes/class_deactivator.php';
        require_once AAM_PATH . 'includes/class_indexer.php';
        require_once AAM_PATH . 'includes/class_search.php';
        require_once AAM_PATH . 'includes/class_admin.php';
        require_once AAM_PATH . 'includes/class_ai.php';
        require_once AAM_PATH . 'includes/class_chat_ui.php';
        require_once AAM_PATH . 'includes/class_site_reader.php';
        require_once AAM_PATH . 'includes/class_shortcode_elementor.php';
    }

    private function register_hooks() {
        register_activation_hook( __FILE__, [ 'AAM_Activator', 'activate' ] );
        register_deactivation_hook( __FILE__, [ 'AAM_Deactivator', 'deactivate' ] );
        add_filter( 'plugin_row_meta', [ $this, 'add_plugin_row_meta' ], 10, 2 );
    }

    public function add_plugin_row_meta( $links, $file ) {
        if ( AAM_BASENAME === $file ) {
            $row_meta = [
                'donate' => '<a href="https://lmdesigners.gumroad.com/coffee/" target="_blank" rel="noopener noreferrer" style="color:#14bda9;font-weight:600;">❤️ ' . __( 'Donate / Support', 'arrodes-ai-manager' ) . '</a>',
                'github' => '<a href="https://github.com/DrSmoK3y/Arrodes-AI-Assistant-For-WordPress" target="_blank" rel="noopener noreferrer">' . __( 'GitHub Repository', 'arrodes-ai-manager' ) . '</a>',
            ];
            return array_merge( $links, $row_meta );
        }
        return $links;
    }

    private function init_classes() {
        new AAM_Indexer();
        new AAM_Admin();
        new AAM_AI();
        new AAM_Chat_UI();
        new AAM_Shortcode_Elementor();
    }

}

Arrodes_AI_Manager::get_instance();
