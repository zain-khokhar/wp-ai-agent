<?php
/**
 * BossCode_Rollback_Manager — File Backup & Restore
 *
 * Manages automated file backups created before destructive operations
 * and provides restore functionality. Backups are stored in
 * WP_CONTENT_DIR/bosscode-backups/ with a 30-day retention policy
 * enforced by wp_cron (see P7-04).
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Rollback_Manager {

    /** @var string */
    private $backup_dir;

    /** @var BossCode_Filesystem */
    private $filesystem;

    /**
     * @param BossCode_Filesystem $filesystem WP_Filesystem wrapper.
     */
    public function __construct( BossCode_Filesystem $filesystem ) {
        $this->filesystem = $filesystem;
        $this->backup_dir = WP_CONTENT_DIR . '/bosscode-backups';
    }

    /**
     * Create a backup of a file before modification.
     *
     * @param string $abs_path Absolute path to the file.
     * @return string|false Backup file path on success, false on failure.
     */
    public function create_backup( $abs_path ) {
        if ( ! $this->filesystem->exists( $abs_path ) ) {
            return false;
        }

        $content = $this->filesystem->read( $abs_path );
        if ( is_wp_error( $content ) ) {
            return false;
        }

        // Ensure backup directory exists.
        $this->filesystem->mkdir( $this->backup_dir );

        // Create a unique backup filename.
        $relative = ltrim( str_replace(
            wp_normalize_path( ABSPATH ),
            '',
            wp_normalize_path( $abs_path )
        ), '/' );
        $safe_name = str_replace( array( '/', '\\' ), '__', $relative );
        $timestamp = gmdate( 'Ymd_His' );
        $backup_path = $this->backup_dir . '/' . $safe_name . '.' . $timestamp . '.bak';

        $result = $this->filesystem->exists( $backup_path )
            ? $this->filesystem->write( $backup_path, $content )
            : $this->filesystem->create( $backup_path, $content );

        if ( is_wp_error( $result ) ) {
            return false;
        }

        return $backup_path;
    }

    /**
     * Restore a file from a backup.
     *
     * @param string $backup_path Absolute path to the backup file.
     * @param string $target_path Absolute path where the file should be restored.
     * @return true|WP_Error
     */
    public function restore( $backup_path, $target_path ) {
        $content = $this->filesystem->read( $backup_path );
        if ( is_wp_error( $content ) ) {
            return $content;
        }

        if ( $this->filesystem->exists( $target_path ) ) {
            return $this->filesystem->write( $target_path, $content );
        } else {
            return $this->filesystem->create( $target_path, $content );
        }
    }

    /**
     * List backups for a specific file.
     *
     * @param string $abs_path Absolute path to the original file.
     * @return array Array of backup records: {path, filename, timestamp, size}.
     */
    public function list_backups( $abs_path ) {
        $relative  = ltrim( str_replace(
            wp_normalize_path( ABSPATH ),
            '',
            wp_normalize_path( $abs_path )
        ), '/' );
        $safe_name = str_replace( array( '/', '\\' ), '__', $relative );

        if ( ! is_dir( $this->backup_dir ) ) {
            return array();
        }

        $backups = array();
        $pattern = $this->backup_dir . '/' . $safe_name . '.*.bak';

        foreach ( glob( $pattern ) as $file ) {
            // Extract timestamp from filename.
            $basename = basename( $file );
            $parts    = explode( '.', $basename );
            $ts_part  = count( $parts ) >= 3 ? $parts[ count( $parts ) - 2 ] : '';

            $backups[] = array(
                'path'      => wp_normalize_path( $file ),
                'filename'  => $basename,
                'timestamp' => $ts_part,
                'size'      => filesize( $file ),
            );
        }

        // Sort newest first.
        usort( $backups, function( $a, $b ) {
            return strcmp( $b['timestamp'], $a['timestamp'] );
        } );

        return $backups;
    }

    /**
     * Purge backups older than a given number of days.
     * Called by wp_cron (P7-04).
     *
     * @param int $days Retention period in days (default: 30).
     * @return int Number of files purged.
     */
    public function purge_old( $days = 30 ) {
        if ( ! is_dir( $this->backup_dir ) ) {
            return 0;
        }

        $cutoff  = time() - ( $days * DAY_IN_SECONDS );
        $purged  = 0;

        foreach ( glob( $this->backup_dir . '/*.bak' ) as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                $this->filesystem->delete( $file );
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * Get the backup directory path.
     *
     * @return string Absolute path to backup directory.
     */
    public function get_backup_dir() {
        return $this->backup_dir;
    }
}
