<?php
/**
 * BossCode_Capabilities — Granular Permissions
 *
 * Defines specific capabilities for executing different levels of tools.
 * By default, assigned to administrators.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Capabilities {

    const CAP_READ_FILES    = 'bosscode_read_files';
    const CAP_WRITE_FILES   = 'bosscode_write_files';
    const CAP_RUN_COMMANDS  = 'bosscode_run_commands';
    const CAP_MANAGE_DB     = 'bosscode_manage_db';

    /**
     * Get all custom capabilities.
     *
     * @return array
     */
    public static function get_all_caps() {
        return array(
            self::CAP_READ_FILES,
            self::CAP_WRITE_FILES,
            self::CAP_RUN_COMMANDS,
            self::CAP_MANAGE_DB,
        );
    }

    /**
     * Add capabilities to the administrator role on plugin activation.
     */
    public static function add_caps_to_admin() {
        $role = get_role( 'administrator' );
        if ( $role ) {
            foreach ( self::get_all_caps() as $cap ) {
                $role->add_cap( $cap );
            }
        }
    }

    /**
     * Remove capabilities on plugin deactivation.
     */
    public static function remove_caps_from_admin() {
        $role = get_role( 'administrator' );
        if ( $role ) {
            foreach ( self::get_all_caps() as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }
}
