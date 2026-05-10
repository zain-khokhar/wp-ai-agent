<?php
/**
 * BossCode_Rate_Limiter — Request Throttling
 *
 * Implements per-user rate limiting using WordPress transients.
 * Prevents abuse by capping the number of chat/tool requests
 * a user can make within a sliding time window.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Rate_Limiter {

    /** @var int Max requests per window */
    private $limit;

    /** @var int Window size in seconds */
    private $window;

    /**
     * @param int $limit  Max requests per window (default: 30).
     * @param int $window Window size in seconds (default: 60).
     */
    public function __construct( $limit = 30, $window = 60 ) {
        $this->limit  = (int) apply_filters( 'bosscode_rate_limit', $limit );
        $this->window = (int) apply_filters( 'bosscode_rate_window', $window );
    }

    /**
     * Check and increment the request counter for a user.
     *
     * @param int    $user_id WordPress user ID.
     * @param string $action  Rate limit bucket (e.g. 'chat', 'tool').
     * @return true|WP_Error True if allowed, WP_Error if rate limited.
     */
    public function check( $user_id, $action = 'chat' ) {
        $key = "bosscode_rl_{$action}_{$user_id}";
        $data = get_transient( $key );

        if ( false === $data ) {
            // First request in this window.
            set_transient( $key, array( 'count' => 1, 'started' => time() ), $this->window );
            return true;
        }

        if ( ! is_array( $data ) || ! isset( $data['count'] ) ) {
            delete_transient( $key );
            set_transient( $key, array( 'count' => 1, 'started' => time() ), $this->window );
            return true;
        }

        if ( $data['count'] >= $this->limit ) {
            $remaining = $this->window - ( time() - $data['started'] );
            $remaining = max( 1, $remaining );
            return new WP_Error(
                'rate_limited',
                sprintf(
                    'Rate limit exceeded. Maximum %d requests per %d seconds. Try again in %d seconds.',
                    $this->limit,
                    $this->window,
                    $remaining
                ),
                array( 'status' => 429 )
            );
        }

        // Increment counter.
        $data['count']++;
        $remaining_ttl = $this->window - ( time() - $data['started'] );
        set_transient( $key, $data, max( 1, $remaining_ttl ) );

        return true;
    }

    /**
     * Get the current count for a user/action.
     *
     * @param int    $user_id WordPress user ID.
     * @param string $action  Rate limit bucket.
     * @return int Current request count.
     */
    public function get_count( $user_id, $action = 'chat' ) {
        $key  = "bosscode_rl_{$action}_{$user_id}";
        $data = get_transient( $key );
        return is_array( $data ) && isset( $data['count'] ) ? $data['count'] : 0;
    }
}
