<?php
/**
 * BossCode_Filesystem — WP_Filesystem Abstraction Layer
 *
 * Wraps WordPress WP_Filesystem API to eliminate raw PHP file I/O
 * (file_get_contents, file_put_contents, unlink) from all tool executors.
 * Handles Direct, FTP, SSH2, and FTPext transport methods transparently.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Filesystem {

    /** @var WP_Filesystem_Base|null */
    private $fs = null;

    /** @var bool Whether WP_Filesystem has been successfully initialized */
    private $initialized = false;

    /**
     * Initialize WP_Filesystem.
     *
     * Must be called after 'admin_init' hook (or equivalent).
     * Handles: Direct, FTP, SSH2, FTPext transport methods.
     * Falls back gracefully with a WP_Error if credentials are needed.
     *
     * @return true|WP_Error
     */
    public function init() {
        if ( $this->initialized ) {
            return true;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Try credential-less direct init first (most common on VPS/managed hosts).
        // Pass null for URL and dir so no form output is generated.
        $credentials = request_filesystem_credentials( '', '', false, false, null );

        if ( false === $credentials ) {
            return new WP_Error(
                'fs_credentials_required',
                'WordPress filesystem requires FTP/SSH credentials. ' .
                'Please define FS_METHOD=\'direct\' in wp-config.php if the web server owns the files, ' .
                'or set FTP_HOST, FTP_USER, FTP_PASS and FS_METHOD=\'ftpext\' for shared hosting.'
            );
        }

        if ( ! WP_Filesystem( $credentials ) ) {
            return new WP_Error( 'fs_init_failed', 'WP_Filesystem initialization failed. Check server filesystem permissions.' );
        }

        global $wp_filesystem;
        $this->fs          = $wp_filesystem;
        $this->initialized = true;

        return true;
    }

    /**
     * Read a file's contents.
     *
     * @param string $abs_path Absolute path to the file.
     * @return string|WP_Error File contents on success, WP_Error on failure.
     */
    public function read( $abs_path ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return $init;

        if ( ! $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_not_found', "File does not exist: {$abs_path}" );
        }

        if ( ! $this->fs->is_readable( $abs_path ) ) {
            return new WP_Error( 'permission_denied', "Cannot read file (permission denied): {$abs_path}" );
        }

        $size = $this->fs->size( $abs_path );
        if ( $size > 512000 ) {
            return new WP_Error( 'file_too_large', "File exceeds 500 KB limit ({$size} bytes): {$abs_path}" );
        }

        $content = $this->fs->get_contents( $abs_path );
        if ( false === $content ) {
            return new WP_Error( 'read_failed', "Failed to read file: {$abs_path}" );
        }

        return $content;
    }

    /**
     * Write content to an EXISTING file.
     * Use create() for new files.
     *
     * @param string $abs_path Absolute path to the file.
     * @param string $content  New file contents.
     * @return true|WP_Error
     */
    public function write( $abs_path, $content ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return $init;

        if ( ! $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_not_found', "File does not exist: {$abs_path}. Use create() for new files." );
        }

        $result = $this->fs->put_contents( $abs_path, $content, FS_CHMOD_FILE );
        if ( ! $result ) {
            return new WP_Error(
                'write_failed',
                "Failed to write to: {$abs_path}. Check file ownership/permissions. " .
                "If on managed hosting, ensure FS_METHOD is set correctly in wp-config.php."
            );
        }

        return true;
    }

    /**
     * Create a NEW file (must NOT already exist).
     * Recursively creates parent directories if needed.
     *
     * @param string $abs_path Absolute path for the new file.
     * @param string $content  Initial file contents.
     * @return true|WP_Error
     */
    public function create( $abs_path, $content = '' ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return $init;

        if ( $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_exists', "File already exists: {$abs_path}. Use write() to modify existing files." );
        }

        // Ensure parent directory exists.
        $dir = dirname( $abs_path );
        if ( ! $this->fs->is_dir( $dir ) ) {
            if ( ! $this->fs->mkdir( $dir, FS_CHMOD_DIR, true ) ) {
                return new WP_Error( 'mkdir_failed', "Cannot create parent directory: {$dir}" );
            }
        }

        $result = $this->fs->put_contents( $abs_path, $content, FS_CHMOD_FILE );
        if ( ! $result ) {
            return new WP_Error( 'create_failed', "Failed to create file: {$abs_path}. Check directory permissions." );
        }

        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $abs_path Absolute path to the file.
     * @return true|WP_Error
     */
    public function delete( $abs_path ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return $init;

        if ( ! $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_not_found', "File does not exist: {$abs_path}" );
        }

        if ( ! $this->fs->delete( $abs_path ) ) {
            return new WP_Error( 'delete_failed', "Failed to delete file: {$abs_path}. Check permissions." );
        }

        return true;
    }

    /**
     * Check if a path exists (file or directory).
     *
     * @param string $abs_path Absolute path.
     * @return bool|WP_Error
     */
    public function exists( $abs_path ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return false;
        return $this->fs->exists( $abs_path );
    }

    /**
     * Check if a path is a directory.
     *
     * @param string $abs_path Absolute path.
     * @return bool
     */
    public function is_dir( $abs_path ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return false;
        return $this->fs->is_dir( $abs_path );
    }

    /**
     * Create a directory (recursively).
     *
     * @param string $abs_path Absolute path to directory.
     * @return true|WP_Error
     */
    public function mkdir( $abs_path ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return $init;

        if ( $this->fs->is_dir( $abs_path ) ) {
            return true; // Already exists.
        }

        if ( ! $this->fs->mkdir( $abs_path, FS_CHMOD_DIR, true ) ) {
            return new WP_Error( 'mkdir_failed', "Cannot create directory: {$abs_path}" );
        }

        return true;
    }

    /**
     * Get file size in bytes.
     *
     * @param string $abs_path Absolute path.
     * @return int|false File size or false on failure.
     */
    public function size( $abs_path ) {
        $init = $this->ensure_init();
        if ( is_wp_error( $init ) ) return false;
        return $this->fs->size( $abs_path );
    }

    /**
     * Get the filesystem transport method currently in use.
     * Useful for diagnostics.
     *
     * @return string 'direct', 'ftpext', 'ssh2', 'ftpsockets', or 'unknown'
     */
    public function get_method() {
        if ( defined( 'FS_METHOD' ) ) {
            return FS_METHOD;
        }
        if ( function_exists( 'get_filesystem_method' ) ) {
            return get_filesystem_method();
        }
        return 'unknown';
    }

    /**
     * Run a full filesystem diagnostics check.
     * Returns structured data for the Diagnostics REST endpoint.
     *
     * @return array Diagnostic results.
     */
    public function run_diagnostics() {
        $results = array(
            'filesystem_method'  => $this->get_method(),
            'fs_initialized'     => false,
            'writable_paths'     => array(),
            'unwritable_paths'   => array(),
            'php_capabilities'   => array(),
            'recommendations'    => array(),
        );

        // Try to init FS.
        $init = $this->init();
        if ( is_wp_error( $init ) ) {
            $results['fs_error']        = $init->get_error_message();
            $results['recommendations'][] = 'Define FS_METHOD=\'direct\' in wp-config.php if the web server owns the files.';
            return $results;
        }
        $results['fs_initialized'] = true;

        // Check key paths.
        $paths_to_check = array(
            'Theme directory'   => get_stylesheet_directory(),
            'Plugins directory' => WP_CONTENT_DIR . '/plugins',
            'Uploads directory' => wp_upload_dir()['basedir'],
            'Backup directory'  => WP_CONTENT_DIR . '/bosscode-backups',
            'wp-content'        => WP_CONTENT_DIR,
        );

        foreach ( $paths_to_check as $label => $path ) {
            $norm = wp_normalize_path( $path );
            $exists   = $this->fs->exists( $path );
            $is_dir   = $exists && $this->fs->is_dir( $path );

            if ( ! $exists ) {
                // Try to create backup dir if missing.
                if ( 'Backup directory' === $label ) {
                    $created = $this->fs->mkdir( $path, FS_CHMOD_DIR, true );
                    $exists  = (bool) $created;
                    $is_dir  = $exists;
                }
            }

            // Attempt write test via temp file.
            $writable = false;
            if ( $is_dir ) {
                $test_file = $path . '/bosscode_write_test_' . time() . '.tmp';
                $wrote     = $this->fs->put_contents( $test_file, 'test', FS_CHMOD_FILE );
                if ( $wrote ) {
                    $this->fs->delete( $test_file );
                    $writable = true;
                }
            }

            $entry = array(
                'label'    => $label,
                'path'     => $norm,
                'exists'   => $exists,
                'is_dir'   => $is_dir,
                'writable' => $writable,
            );

            if ( $writable ) {
                $results['writable_paths'][] = $entry;
            } else {
                $results['unwritable_paths'][] = $entry;
                if ( $is_dir ) {
                    $web_user = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( posix_geteuid() )['name'] ?? 'www-data' : 'www-data';
                    $results['recommendations'][] = "Fix: chown -R {$web_user}:{$web_user} " . escapeshellarg( $path ) . " && chmod -R 755 " . escapeshellarg( $path );
                }
            }
        }

        // PHP capability checks.
        $results['php_capabilities'] = array(
            'exec_available'        => function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true ),
            'php_version'           => phpversion(),
            'safe_mode'             => (bool) ini_get( 'safe_mode' ),
            'set_time_limit_works'  => ! ini_get( 'safe_mode' ),
            'allow_url_fopen'       => (bool) ini_get( 'allow_url_fopen' ),
        );

        // wp-config.php permissions warning.
        $wp_config = ABSPATH . 'wp-config.php';
        if ( file_exists( $wp_config ) ) {
            $perms = fileperms( $wp_config );
            if ( $perms && ( $perms & 0x0004 ) ) { // World-readable
                $results['recommendations'][] = 'wp-config.php is world-readable. Recommend: chmod 640 ' . $wp_config;
            }
        }

        if ( empty( $results['recommendations'] ) ) {
            $results['recommendations'][] = 'All checks passed. No action required.';
        }

        return $results;
    }

    // ─── Private Helpers ─────────────────────────────────────────────

    /**
     * Ensure WP_Filesystem is initialized. Returns WP_Error on failure.
     *
     * @return true|WP_Error
     */
    private function ensure_init() {
        if ( $this->initialized && $this->fs ) {
            return true;
        }
        return $this->init();
    }
}
