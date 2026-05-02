<?php
/**
 * BossCode AI Agent — Uninstall Script
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all plugin data: options, custom tables, transients, and backups.
 *
 * @package BossCode_AI_Agent
 * @since   1.0.0
 */

// Abort if not called by WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Delete all plugin options
$options = array(
    'bosscode_ai_provider',
    'bosscode_ai_base_url',
    'bosscode_ai_api_key',
    'bosscode_ai_model',
    'bosscode_ai_max_loop_iterations',
    'bosscode_ai_embedding_model',
    'bosscode_ai_allowed_paths',
    'bosscode_ai_version',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// 2. Drop custom database tables
$table_name = $wpdb->prefix . 'bosscode_embeddings';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL

// 3. Clean up transients
delete_transient( 'bosscode_index_progress' );
delete_transient( 'bosscode_active_jobs' );

// 4. Optionally remove backup directory
$backup_dir = WP_CONTENT_DIR . '/bosscode-backups/';
if ( is_dir( $backup_dir ) ) {
    // Recursively delete the backup directory
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            rmdir( $file->getRealPath() );
        } else {
            unlink( $file->getRealPath() );
        }
    }
    rmdir( $backup_dir );
}
