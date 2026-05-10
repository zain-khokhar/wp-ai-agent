<?php
/**
 * BossCode_Concurrency_Locks — Prevents race conditions using Transients.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Concurrency_Locks {

    /**
     * Acquire a lock.
     *
     * @param string $lock_name The name of the lock.
     * @param int    $expiration Expiration in seconds (default 300).
     * @return bool True if lock acquired, false if it already exists.
     */
    public static function acquire( $lock_name, $expiration = 300 ) {
        $transient_name = 'bosscode_lock_' . md5( $lock_name );
        
        // If transient exists, lock is held
        if ( false !== get_transient( $transient_name ) ) {
            return false;
        }

        // Set the transient. Since there's a small race condition window here in standard WP,
        // it's "good enough" for most WP environments without Redis/Memcached atomic locks.
        set_transient( $transient_name, time(), $expiration );
        return true;
    }

    /**
     * Release a lock.
     *
     * @param string $lock_name The name of the lock.
     */
    public static function release( $lock_name ) {
        $transient_name = 'bosscode_lock_' . md5( $lock_name );
        delete_transient( $transient_name );
    }

    /**
     * Check if a lock exists.
     *
     * @param string $lock_name The name of the lock.
     * @return bool
     */
    public static function is_locked( $lock_name ) {
        $transient_name = 'bosscode_lock_' . md5( $lock_name );
        return false !== get_transient( $transient_name );
    }
}
