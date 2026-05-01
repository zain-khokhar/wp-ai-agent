<?php
/**
 * AI API Handler Logic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Processes the chat request, connects to local/external LLM API.
 * 
 * @param string $prompt
 * @return string|WP_Error Returns AI text response on success, WP_Error on failure.
 */
function bosscode_ai_process_request( $prompt ) {
    // Retrieve stored settings
    $api_base_url = get_option( 'bosscode_ai_base_url', 'http://localhost:11434/v1' );
    $api_key      = get_option( 'bosscode_ai_api_key', '' );

    // Ensure no trailing slash
    $api_base_url = rtrim( $api_base_url, '/' );
    
    // Construct the standard chat completions endpoint
    $endpoint = $api_base_url . '/chat/completions';

    // Prepare HTTP Headers
    $headers = array(
        'Content-Type'  => 'application/json',
    );

    if ( ! empty( $api_key ) ) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    // Prepare HTTP Body in OpenAI-compatible format
    $body = array(
        'model'    => 'local-model', // Generally ignored by local tools, required for standard
        'messages' => array(
            array(
                'role'    => 'system',
                'content' => 'You are BossCode AI Agent, a helpful, expert coding assistant embedded in WordPress.'
            ),
            array(
                'role'    => 'user',
                'content' => $prompt
            )
        )
    );

    // CRITICAL for Local Dev: WP usually blocks localhost/127.0.0.1 requests via wp_remote_post
    // for security reasons (SSRF). We temporarily bypass this with a filter to allow local models.
    add_filter( 'http_request_args', 'bosscode_ai_allow_local_requests', 10, 2 );

    // Make the external/local POST request
    $response = wp_remote_post( $endpoint, array(
        'headers' => $headers,
        'body'    => wp_json_encode( $body ),
        'timeout' => 120, // Timeout increased as local LLMs may take longer to respond
    ) );

    // Remove the filter to restore security for other wp_remote_post calls globally
    remove_filter( 'http_request_args', 'bosscode_ai_allow_local_requests', 10 );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'api_error', 'Failed to connect to AI API: ' . $response->get_error_message(), array( 'status' => 500 ) );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body        = wp_remote_retrieve_body( $response );
    $data        = json_decode( $body, true );

    // Check for HTTP errors from the LLM server
    if ( $status_code !== 200 ) {
        $error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error from AI endpoint.';
        return new WP_Error( 'api_error', 'API Error: ' . $error_msg, array( 'status' => $status_code ) );
    }

    // Extract the content from the response
    if ( isset( $data['choices'][0]['message']['content'] ) ) {
        return $data['choices'][0]['message']['content'];
    }

    return new WP_Error( 'parse_error', 'Could not parse response structure from AI endpoint.', array( 'status' => 500 ) );
}

/**
 * Filter to allow requests to localhost / local IPs safely for this specific call.
 * This is necessary for connecting to Ollama/LM Studio running on the same machine.
 */
function bosscode_ai_allow_local_requests( $args, $url ) {
    $args['reject_unsafe_urls'] = false;
    return $args;
}
