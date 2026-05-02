<?php
/**
 * BossCode AI Agent — Sandboxed Tool Executor
 *
 * Executes whitelisted tools in a sandboxed environment with path validation,
 * automatic backups, and standardized JSON envelope responses.
 *
 * @package BossCode_AI_Agent
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Tool_Executor {

    /** @var BossCode_Security */
    private $security;

    public function __construct( BossCode_Security $security ) {
        $this->security = $security;
    }

    /**
     * Execute a tool by name with given arguments.
     *
     * @param string $name Tool name.
     * @param array  $args Tool arguments.
     * @return array Standardized result: {success, result, error?}
     */
    public function execute( $name, $args ) {
        $method = 'tool_' . $name;
        if ( ! method_exists( $this, $method ) ) {
            return $this->error_response( 'UNKNOWN_TOOL', "Tool '{$name}' is not implemented." );
        }

        try {
            return call_user_func( array( $this, $method ), $args );
        } catch ( \Exception $e ) {
            return $this->error_response( 'EXECUTION_ERROR', $e->getMessage() );
        }
    }

    // ─── Tool Implementations ───────────────────────────────────

    private function tool_read_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path; // error response
        }

        if ( ! file_exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', "File does not exist: {$args['path']}" );
        }

        if ( ! is_readable( $path ) ) {
            return $this->error_response( 'PERMISSION_DENIED', "Cannot read file: {$args['path']}" );
        }

        // Limit file size to 500KB to prevent memory issues
        $size = filesize( $path );
        if ( $size > 512000 ) {
            return $this->error_response( 'FILE_TOO_LARGE', "File exceeds 500KB limit ({$size} bytes)." );
        }

        $content = file_get_contents( $path );
        return $this->success_response( $content );
    }

    private function tool_write_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! file_exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', "File does not exist. Use create_file for new files." );
        }

        // Create backup before writing
        $backup = $this->security->create_backup( $path );

        $result = file_put_contents( $path, $args['content'] ?? '' );
        if ( false === $result ) {
            return $this->error_response( 'WRITE_FAILED', "Failed to write to {$args['path']}." );
        }

        $msg = "Successfully wrote " . strlen( $args['content'] ) . " bytes to {$args['path']}.";
        if ( $backup ) {
            $msg .= " Backup saved.";
        }
        return $this->success_response( $msg );
    }

    private function tool_create_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '', true );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( file_exists( $path ) ) {
            return $this->error_response( 'FILE_EXISTS', "File already exists. Use write_file to modify." );
        }

        // Ensure parent directory exists
        $dir = dirname( $path );
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $result = file_put_contents( $path, $args['content'] ?? '' );
        if ( false === $result ) {
            return $this->error_response( 'CREATE_FAILED', "Failed to create {$args['path']}." );
        }

        return $this->success_response( "Created {$args['path']} (" . strlen( $args['content'] ) . " bytes)." );
    }

    private function tool_delete_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! file_exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', "File does not exist: {$args['path']}" );
        }

        $this->security->create_backup( $path );

        if ( ! unlink( $path ) ) {
            return $this->error_response( 'DELETE_FAILED', "Failed to delete {$args['path']}." );
        }

        return $this->success_response( "Deleted {$args['path']}. Backup was created." );
    }

    private function tool_list_directory( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! is_dir( $path ) ) {
            return $this->error_response( 'NOT_A_DIRECTORY', "Path is not a directory: {$args['path']}" );
        }

        $items = array();
        $iterator = new \DirectoryIterator( $path );
        $count = 0;

        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) continue;
            if ( $count >= 200 ) {
                $items[] = array( 'name' => '... (truncated at 200 items)', 'type' => 'notice' );
                break;
            }
            $items[] = array(
                'name' => $item->getFilename(),
                'type' => $item->isDir() ? 'directory' : 'file',
                'size' => $item->isFile() ? $item->getSize() : null,
            );
            $count++;
        }

        return $this->success_response( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
    }

    private function tool_search_files( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! is_dir( $path ) ) {
            return $this->error_response( 'NOT_A_DIRECTORY', "Path is not a directory." );
        }

        $query = $args['query'] ?? '';
        if ( empty( $query ) ) {
            return $this->error_response( 'MISSING_QUERY', "Search query is required." );
        }

        $extensions = array( 'php', 'js', 'css', 'html', 'txt', 'json' );
        if ( ! empty( $args['file_extensions'] ) ) {
            $extensions = array_map( 'trim', explode( ',', $args['file_extensions'] ) );
        }

        $matches = array();
        $this->search_recursive( $path, $query, $extensions, $matches, 50 );

        if ( empty( $matches ) ) {
            return $this->success_response( "No matches found for '{$query}'." );
        }

        $output = "Found " . count( $matches ) . " match(es):\n";
        foreach ( $matches as $m ) {
            $rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $m['file'] ) );
            $output .= "\n{$rel}:{$m['line']} — {$m['content']}";
        }
        return $this->success_response( $output );
    }

    private function tool_replace_in_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! file_exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', "File does not exist." );
        }

        $content = file_get_contents( $path );
        $search  = $args['search'] ?? '';
        $replace = $args['replace'] ?? '';

        if ( strpos( $content, $search ) === false ) {
            return $this->error_response( 'NOT_FOUND', "Search text not found in file." );
        }

        $this->security->create_backup( $path );

        $count = 0;
        $new_content = str_replace( $search, $replace, $content, $count );
        file_put_contents( $path, $new_content );

        return $this->success_response( "Replaced {$count} occurrence(s) in {$args['path']}." );
    }

    private function tool_get_wordpress_info( $args ) {
        $info = array(
            'wp_version'    => get_bloginfo( 'version' ),
            'php_version'   => phpversion(),
            'active_theme'  => wp_get_theme()->get( 'Name' ),
            'theme_dir'     => get_stylesheet_directory(),
            'active_plugins' => get_option( 'active_plugins', array() ),
            'site_url'      => site_url(),
            'abspath'       => ABSPATH,
        );

        // Get custom post types
        $cpts = get_post_types( array( '_builtin' => false ), 'names' );
        $info['custom_post_types'] = array_values( $cpts );

        return $this->success_response( wp_json_encode( $info, JSON_PRETTY_PRINT ) );
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * Resolve a relative path and validate it against the whitelist.
     *
     * @param string $raw_path      The raw path from tool arguments.
     * @param bool   $allow_new     If true, allows paths to non-existent files.
     * @return string|array Absolute path string, or error response array.
     */
    private function resolve_and_validate_path( $raw_path, $allow_new = false ) {
        if ( empty( $raw_path ) ) {
            return $this->error_response( 'MISSING_PATH', 'Path argument is required.' );
        }

        $sanitized = $this->security->sanitize_path( $raw_path );
        if ( false === $sanitized ) {
            return $this->error_response( 'INVALID_PATH', "Invalid or unsafe path: {$raw_path}" );
        }

        if ( ! $this->security->is_path_allowed( $sanitized ) ) {
            return $this->error_response( 'PATH_NOT_ALLOWED', "Access denied for path: {$raw_path}. Path is outside allowed directories." );
        }

        return $sanitized;
    }

    private function success_response( $result ) {
        return array( 'success' => true, 'result' => $result );
    }

    private function error_response( $code, $message ) {
        return array( 'success' => false, 'error' => $code, 'result' => $message );
    }

    /**
     * Recursively search files for a text query.
     */
    private function search_recursive( $dir, $query, $extensions, &$matches, $limit ) {
        if ( count( $matches ) >= $limit ) return;

        $items = scandir( $dir );
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            if ( count( $matches ) >= $limit ) return;

            $full = $dir . DIRECTORY_SEPARATOR . $item;

            if ( is_dir( $full ) ) {
                // Skip common non-code directories
                if ( in_array( $item, array( 'node_modules', '.git', 'vendor' ), true ) ) continue;
                $this->search_recursive( $full, $query, $extensions, $matches, $limit );
            } elseif ( is_file( $full ) ) {
                $ext = pathinfo( $full, PATHINFO_EXTENSION );
                if ( ! in_array( $ext, $extensions, true ) ) continue;

                $lines = file( $full, FILE_IGNORE_NEW_LINES );
                if ( ! $lines ) continue;

                foreach ( $lines as $i => $line ) {
                    if ( count( $matches ) >= $limit ) return;
                    if ( stripos( $line, $query ) !== false ) {
                        $matches[] = array(
                            'file'    => $full,
                            'line'    => $i + 1,
                            'content' => trim( substr( $line, 0, 200 ) ),
                        );
                    }
                }
            }
        }
    }
}
