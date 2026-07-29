<?php
// Block direct access — required in uninstall.php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop chat logs table safely using prepared query format
$table = $wpdb->prefix . 'aam_chat_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Delete all plugin options
delete_option( 'aam_version' );
delete_option( 'aam_api_key' );
delete_option( 'aam_chatbot_name' );
delete_option( 'aam_welcome_message' );
delete_option( 'aam_include_all_pages' );
delete_option( 'aam_selected_pages' );
