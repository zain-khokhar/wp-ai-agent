<?php
/**
 * BossCode AI Agent — Security Module
 *
 * Handles encryption/decryption of sensitive data (API keys),
 * path validation and sandboxing, and input sanitization utilities.
 *
 * @package BossCode_AI_Agent
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Security {

    /**
     * Encryption cipher method.
     *
     * @var string
     */
    const CIPHER_METHOD = 'aes-256-cbc';

    /**
     * Encrypt a plaintext string using AES-256-CBC.
     *
     * Uses WordPress auth salt as the encryption key to tie encryption
     * to the specific WordPress installation. Each encrypted value gets
     * a unique IV for semantic security.
     *
     * @param string $plaintext The string to encrypt.
     * @return string|false Base64-encoded "iv:ciphertext" string, or false on failure.
     */
    public function encrypt( $plaintext ) {
        if ( empty( $plaintext ) ) {
            return '';
        }

        $key = $this->get_encryption_key();
        $iv_length = openssl_cipher_iv_length( self::CIPHER_METHOD );
        $iv = openssl_random_pseudo_bytes( $iv_length );

        $ciphertext = openssl_encrypt( $plaintext, self::CIPHER_METHOD, $key, 0, $iv );

        if ( false === $ciphertext ) {
            return false;
        }

        // Store IV alongside ciphertext so we can decrypt later
        // Format: base64(iv):base64(ciphertext)
        return base64_encode( $iv ) . ':' . $ciphertext;
    }

    /**
     * Decrypt an encrypted string.
     *
     * @param string $encrypted The "iv:ciphertext" string from encrypt().
     * @return string|false The decrypted plaintext, or false on failure.
     */
    public function decrypt( $encrypted ) {
        if ( empty( $encrypted ) ) {
            return '';
        }

        $parts = explode( ':', $encrypted, 2 );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        $iv         = base64_decode( $parts[0] );
        $ciphertext = $parts[1];
        $key        = $this->get_encryption_key();

        $plaintext = openssl_decrypt( $ciphertext, self::CIPHER_METHOD, $key, 0, $iv );

        return $plaintext;
    }

    /**
     * Derive the encryption key from WordPress salts.
     *
     * Uses a SHA-256 hash of the auth salt to ensure a consistent
     * 32-byte key regardless of the salt length.
     *
     * @return string 32-byte binary key.
     */
    private function get_encryption_key() {
        // Combine multiple salts for stronger key derivation
        $salt = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
        return hash( 'sha256', $salt, true ); // 32 bytes, raw binary
    }

    /**
     * Mask a sensitive string for safe display.
     *
     * Shows only the last 4 characters, replacing the rest with asterisks.
     * Returns empty string if the input is empty.
     *
     * @param string $value The sensitive value to mask.
     * @return string Masked string, e.g., "sk-****ab12".
     */
    public function mask_value( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $length = strlen( $value );
        if ( $length <= 4 ) {
            return str_repeat( '*', $length );
        }

        $visible = substr( $value, -4 );
        $masked  = str_repeat( '*', min( $length - 4, 8 ) );

        return $masked . $visible;
    }

    /**
     * Sanitize and validate a filesystem path.
     *
     * Resolves the path to an absolute path, strips null bytes,
     * and normalizes directory separators.
     *
     * @param string $path The path to sanitize.
     * @return string|false The sanitized absolute path, or false if invalid.
     */
    public function sanitize_path( $path ) {
        // Strip null bytes (path traversal attack vector)
        $path = str_replace( "\0", '', $path );

        // Normalize separators
        $path = str_replace( '\\', '/', $path );

        // Remove any double dots for safety before resolving
        // (realpath handles this too, but belt-and-suspenders)
        if ( strpos( $path, '..' ) !== false ) {
            return false;
        }

        // If it's a relative path, resolve from ABSPATH
        if ( ! $this->is_absolute_path( $path ) ) {
            $path = ABSPATH . ltrim( $path, '/' );
        }

        // Use realpath for existing paths, wp_normalize_path for new files
        $real = realpath( $path );
        if ( false !== $real ) {
            return wp_normalize_path( $real );
        }

        // For new files (create_file), validate the parent directory exists
        $parent = realpath( dirname( $path ) );
        if ( false !== $parent ) {
            return wp_normalize_path( $parent . '/' . basename( $path ) );
        }

        return false;
    }

    /**
     * Check if a path falls within the allowed directories.
     *
     * The whitelist is stored in the bosscode_ai_allowed_paths option.
     * Additionally, the wp-content/bosscode-backups/ directory is always allowed.
     *
     * @param string $path The absolute path to check.
     * @return bool True if the path is within an allowed directory.
     */
    public function is_path_allowed( $path ) {
        $sanitized = $this->sanitize_path( $path );
        if ( false === $sanitized ) {
            return false;
        }

        // Normalize for comparison
        $sanitized = wp_normalize_path( $sanitized );

        // Never allow access to sensitive WordPress files
        $blocked_files = array(
            'wp-config.php',
            '.htaccess',
            'wp-settings.php',
        );
        $basename = basename( $sanitized );
        if ( in_array( $basename, $blocked_files, true ) ) {
            return false;
        }

        // Never allow access to the plugin's own backend files
        $plugin_backend = wp_normalize_path( BOSSCODE_PLUGIN_DIR . 'backend/' );
        if ( strpos( $sanitized, $plugin_backend ) === 0 ) {
            return false;
        }

        // Build the whitelist
        $allowed_paths = get_option( 'bosscode_ai_allowed_paths', array() );
        if ( ! is_array( $allowed_paths ) ) {
            $allowed_paths = array();
        }

        // Always allow the backups directory
        $backup_dir = wp_normalize_path( WP_CONTENT_DIR . '/bosscode-backups/' );
        $allowed_paths[] = $backup_dir;

        // Check if the sanitized path starts with any allowed directory
        foreach ( $allowed_paths as $allowed ) {
            $allowed = wp_normalize_path( rtrim( $allowed, '/' ) . '/' );
            if ( strpos( $sanitized, $allowed ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a path is absolute.
     *
     * @param string $path The path to check.
     * @return bool
     */
    private function is_absolute_path( $path ) {
        // Unix absolute
        if ( strpos( $path, '/' ) === 0 ) {
            return true;
        }
        // Windows absolute (e.g., C:\)
        if ( preg_match( '/^[a-zA-Z]:[\\\\\/]/', $path ) ) {
            return true;
        }
        return false;
    }

    /**
     * Create a backup of a file before modification.
     *
     * Backups are stored in wp-content/bosscode-backups/ with timestamps.
     *
     * @param string $file_path Absolute path to the file to back up.
     * @return string|false Path to the backup file, or false on failure.
     */
    public function create_backup( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        $backup_dir = WP_CONTENT_DIR . '/bosscode-backups/';
        if ( ! file_exists( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
        }

        // Generate a timestamped backup filename
        $relative   = str_replace( ABSPATH, '', $file_path );
        $safe_name  = str_replace( array( '/', '\\' ), '__', $relative );
        $timestamp  = gmdate( 'Y-m-d_H-i-s' );
        $backup_path = $backup_dir . $timestamp . '__' . $safe_name;

        $result = copy( $file_path, $backup_path );

        return $result ? $backup_path : false;
    }
}
