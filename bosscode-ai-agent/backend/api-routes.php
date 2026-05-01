<?php
/**
 * REST API Routes for BossCode AI Agent
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    $namespace = 'bosscode-ai/v1';

    // Route: GET Settings
    register_rest_route( $namespace, '/settings', array(
        'methods'             => 'GET',
        'callback'            => 'bosscode_ai_get_settings',
        'permission_callback' => 'bosscode_ai_permissions_check',
    ) );

    // Route: POST Save Settings
    register_rest_route( $namespace, '/save-settings', array(
        'methods'             => 'POST',
        'callback'            => 'bosscode_ai_save_settings',
        'permission_callback' => 'bosscode_ai_permissions_check',
    ) );

    // Route: POST Chat
    register_rest_route( $namespace, '/chat', array(
        'methods'             => 'POST',
        'callback'            => 'bosscode_ai_chat_handler',
        'permission_callback' => 'bosscode_ai_permissions_check',
    ) );
});

/**
 * Capability check to ensure only admins can use these endpoints
 */
function bosscode_ai_permissions_check() {
    return current_user_can( 'manage_options' );
}

/**
 * Callback: Get current settings
 */
function bosscode_ai_get_settings() {
    return rest_ensure_response( array(
        'api_base_url' => get_option( 'bosscode_ai_base_url', 'http://localhost:11434/v1' ),
        'api_key'      => get_option( 'bosscode_ai_api_key', '' )
    ) );
}

/**
 * Callback: Save Settings securely
 */
function bosscode_ai_save_settings( WP_REST_Request $request ) {
    $api_base_url = sanitize_text_field( $request->get_param( 'api_base_url' ) );
    $api_key      = sanitize_text_field( $request->get_param( 'api_key' ) );

    // Save to wp_options table
    update_option( 'bosscode_ai_base_url', $api_base_url );
    update_option( 'bosscode_ai_api_key', $api_key );

    return rest_ensure_response( array(
        'success' => true,
        'message' => 'Settings saved successfully.'
    ) );
}

/**
 * Callback: Handle Chat request from frontend
 */
function bosscode_ai_chat_handler( WP_REST_Request $request ) {
    // Sanitize the input prompt
    $prompt = sanitize_text_field( $request->get_param( 'prompt' ) );
    
    if ( empty( $prompt ) ) {
        return new WP_Error( 'missing_prompt', 'Prompt is required.', array( 'status' => 400 ) );
    }

    // Call the AI logic handler
    $response = bosscode_ai_process_request( $prompt );

    // Handle WP_Error if something failed in the AI handler
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return rest_ensure_response( array(
        'success'  => true,
        'response' => $response
    ) );
}
