<?php
// Block direct access — required in uninstall.php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Drop all custom plugin database tables
$tables = [
    $wpdb->prefix . 'aam_chat_logs',
    $wpdb->prefix . 'aam_content_index',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// 2. Delete all plugin options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aam_%'" );

// 3. Delete all cached transients & transient timeouts
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aam_%' OR option_name LIKE '_transient_timeout_aam_%'" );

// 4. Multisite network-wide cleanup if enabled
if ( is_multisite() ) {
    $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'aam_%'" );
}
