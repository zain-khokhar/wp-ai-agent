<?php
/**
 * BossCode_Error_Handler — Error Recovery & Retry Logic
 *
 * Implements exponential backoff for transient API errors and network issues.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Error_Handler {

    // Transient errors that should be retried:
    const RETRYABLE_ERRORS = array(
        'http_request_failed',     // Network blip
        'rest_no_route',           // Transient REST issue
        'timeout',                 // AI provider timeout
        'api_connection_error',
    );

    // Errors that should abort immediately:
    const FATAL_ERRORS = array(
        'invalid_api_key',
        'insufficient_quota',
        'model_not_found',
        'fs_credentials_required',
    );

    /**
     * Retry a callable up to $max_attempts times with exponential backoff.
     *
     * @param callable $fn             Function to retry.
     * @param int      $max_attempts   Max retries (default 3).
     * @param int      $base_delay_ms  Base delay in milliseconds (default 1000).
     * @return mixed|WP_Error
     */
    public function with_retry( $fn, $max_attempts = 3, $base_delay_ms = 1000 ) {
        $attempts = 0;

        while ( $attempts < $max_attempts ) {
            $attempts++;
            
            $result = call_user_func( $fn );

            if ( is_wp_error( $result ) && $this->is_retryable( $result ) ) {
                if ( $attempts >= $max_attempts ) {
                    return $result; // Final attempt failed
                }
                
                // Exponential backoff: base_delay * (2 ^ (attempts - 1))
                $delay = $base_delay_ms * pow( 2, $attempts - 1 );
                usleep( $delay * 1000 ); // usleep takes microseconds
                continue;
            }

            return $result;
        }

        return new WP_Error( 'max_retries_exceeded', 'Maximum retry attempts exceeded.' );
    }

    /**
     * Classify a WP_Error or exception and determine if it is retryable.
     *
     * @param WP_Error|Exception $error
     * @return bool
     */
    public function is_retryable( $error ) {
        if ( is_wp_error( $error ) ) {
            $code = $error->get_error_code();
            
            if ( in_array( $code, self::FATAL_ERRORS, true ) ) {
                return false;
            }

            if ( in_array( $code, self::RETRYABLE_ERRORS, true ) ) {
                return true;
            }

            // HTTP 5xx errors are typically retryable
            $data = $error->get_error_data();
            if ( is_array( $data ) && isset( $data['status'] ) ) {
                $status = (int) $data['status'];
                if ( $status >= 500 && $status < 600 ) {
                    return true;
                }
                if ( $status === 429 ) { // Too Many Requests
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Format a user-friendly error message for display in chat.
     *
     * @param WP_Error $error
     * @return string
     */
    public function format_for_display( $error ) {
        if ( is_wp_error( $error ) ) {
            return "⚠️ Request Failed: " . $error->get_error_message();
        }
        return "⚠️ An unknown error occurred.";
    }
}
