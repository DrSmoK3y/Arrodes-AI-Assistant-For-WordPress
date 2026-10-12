<?php
defined( 'ABSPATH' ) || exit;

class AAM_Activator {

    public static function activate() {
        self::create_tables();
        self::save_version();
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

        // Create Content Index table
        if ( class_exists( 'AAM_Indexer' ) ) {
            AAM_Indexer::create_table();
        }
    }

    private static function save_version() {
        add_option( 'aam_version', AAM_VERSION );
    }

}
