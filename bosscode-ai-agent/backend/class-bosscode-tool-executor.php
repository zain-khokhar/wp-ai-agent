<?php
/**
 * BossCode AI Agent — Sandboxed Tool Executor
 *
 * Executes whitelisted tools in a sandboxed environment with path validation,
 * automatic backups, PHP syntax validation, and standardized JSON envelope responses.
 * All file I/O is routed through BossCode_Filesystem (WP_Filesystem API) — never
 * raw PHP file_get_contents / file_put_contents / unlink.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Tool_Executor {

    /** @var BossCode_Security */
    private $security;

    /** @var BossCode_Filesystem */
    private $filesystem;

    /**
     * @param BossCode_Security   $security
     * @param BossCode_Filesystem $filesystem
     */
    public function __construct( BossCode_Security $security, BossCode_Filesystem $filesystem ) {
        $this->security   = $security;
        $this->filesystem = $filesystem;
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

        // P7-01: Granular Capabilities Check
        $caps = array(
            'list_directory' => BossCode_Capabilities::CAP_READ_FILES,
            'read_file'      => BossCode_Capabilities::CAP_READ_FILES,
            'search_files'   => BossCode_Capabilities::CAP_READ_FILES,
            'write_file'     => BossCode_Capabilities::CAP_WRITE_FILES,
            'update_file'    => BossCode_Capabilities::CAP_WRITE_FILES,
            'delete_file'    => BossCode_Capabilities::CAP_WRITE_FILES,
            'run_wp_cli'     => BossCode_Capabilities::CAP_RUN_COMMANDS,
            'run_db_query'   => BossCode_Capabilities::CAP_MANAGE_DB,
            'create_backup'  => BossCode_Capabilities::CAP_READ_FILES,
            'restore_backup' => BossCode_Capabilities::CAP_WRITE_FILES,
            'list_backups'   => BossCode_Capabilities::CAP_READ_FILES,
        );

        if ( isset( $caps[ $name ] ) && ! current_user_can( $caps[ $name ] ) ) {
            return $this->error_response( 'PERMISSION_DENIED', "You do not have permission ({$caps[$name]}) to execute '{$name}'." );
        }

        try {
            return call_user_func( array( $this, $method ), $args );
        } catch ( \Exception $e ) {
            return $this->error_response( 'EXECUTION_ERROR', $e->getMessage() );
        }
    }

    // ─── Tool Implementations ────────────────────────────────────────

    /**
     * Tool: read_file
     * Read a file's contents. Uses WP_Filesystem (never raw file_get_contents).
     */
    private function tool_read_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path; // error response
        }

        $content = $this->filesystem->read( $path );
        if ( is_wp_error( $content ) ) {
            return $this->error_response( 'READ_FAILED', $content->get_error_message() );
        }

        return $this->success_response( $content );
    }

    /**
     * Tool: write_file
     * Overwrite an existing file. Creates a backup first.
     * Validates PHP syntax before writing .php files.
     */
    private function tool_write_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! $this->filesystem->exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', 'File does not exist. Use create_file for new files.' );
        }

        $content = $args['content'] ?? '';

        // PHP syntax validation gate.
        $syntax_check = $this->validate_php_syntax( $content, $path );
        if ( is_wp_error( $syntax_check ) ) {
            return $this->error_response( 'PHP_SYNTAX_ERROR', $syntax_check->get_error_message() );
        }

        // Create backup before writing (using raw PHP for backup file only — backup dir is always writable by web user).
        $backup = $this->security->create_backup( $path );

        $result = $this->filesystem->write( $path, $content );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( 'WRITE_FAILED', $result->get_error_message() );
        }

        $msg = 'Successfully wrote ' . strlen( $content ) . ' bytes to ' . $args['path'] . '.';
        if ( $backup ) {
            $msg .= ' Backup saved.';
        }
        if ( true === $syntax_check && pathinfo( $path, PATHINFO_EXTENSION ) === 'php' ) {
            $msg .= ' PHP syntax validated.';
        }

        return $this->success_response( $msg );
    }

    /**
     * Tool: create_file
     * Create a new file (must NOT exist). Validates PHP syntax for .php files.
     */
    private function tool_create_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '', true );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( $this->filesystem->exists( $path ) ) {
            return $this->error_response( 'FILE_EXISTS', 'File already exists. Use write_file to modify.' );
        }

        $content = $args['content'] ?? '';

        // PHP syntax validation gate.
        $syntax_check = $this->validate_php_syntax( $content, $path );
        if ( is_wp_error( $syntax_check ) ) {
            return $this->error_response( 'PHP_SYNTAX_ERROR', $syntax_check->get_error_message() );
        }

        $result = $this->filesystem->create( $path, $content );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( 'CREATE_FAILED', $result->get_error_message() );
        }

        $msg = 'Created ' . $args['path'] . ' (' . strlen( $content ) . ' bytes).';
        if ( true === $syntax_check && pathinfo( $path, PATHINFO_EXTENSION ) === 'php' ) {
            $msg .= ' PHP syntax validated.';
        }

        return $this->success_response( $msg );
    }

    /**
     * Tool: delete_file
     * Delete a file. Creates a backup first.
     */
    private function tool_delete_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! $this->filesystem->exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', "File does not exist: {$args['path']}" );
        }

        // Create backup before deletion.
        $this->security->create_backup( $path );

        $result = $this->filesystem->delete( $path );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( 'DELETE_FAILED', $result->get_error_message() );
        }

        return $this->success_response( "Deleted {$args['path']}. Backup was created." );
    }

    /**
     * Tool: list_directory
     * List files and directories at a given path.
     */
    private function tool_list_directory( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! is_dir( $path ) ) {
            return $this->error_response( 'NOT_A_DIRECTORY', "Path is not a directory: {$args['path']}" );
        }

        $items    = array();
        $iterator = new \DirectoryIterator( $path );
        $count    = 0;

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

    /**
     * Tool: search_files
     * Search file contents recursively for a text query.
     */
    private function tool_search_files( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! is_dir( $path ) ) {
            return $this->error_response( 'NOT_A_DIRECTORY', 'Path is not a directory.' );
        }

        $query = $args['query'] ?? '';
        if ( empty( $query ) ) {
            return $this->error_response( 'MISSING_QUERY', 'Search query is required.' );
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

        $output = 'Found ' . count( $matches ) . " match(es):\n";
        foreach ( $matches as $m ) {
            $rel     = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $m['file'] ) );
            $output .= "\n{$rel}:{$m['line']} — {$m['content']}";
        }

        return $this->success_response( $output );
    }

    /**
     * Tool: replace_in_file
     * Search and replace text inside a file. Creates a backup first.
     * Validates PHP syntax after replacement.
     */
    private function tool_replace_in_file( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! $this->filesystem->exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', 'File does not exist.' );
        }

        $content = $this->filesystem->read( $path );
        if ( is_wp_error( $content ) ) {
            return $this->error_response( 'READ_FAILED', $content->get_error_message() );
        }

        $search  = $args['search'] ?? '';
        $replace = $args['replace'] ?? '';

        if ( strpos( $content, $search ) === false ) {
            return $this->error_response( 'NOT_FOUND', 'Search text not found in file.' );
        }

        $count       = 0;
        $new_content = str_replace( $search, $replace, $content, $count );

        // PHP syntax validation on resulting content.
        $syntax_check = $this->validate_php_syntax( $new_content, $path );
        if ( is_wp_error( $syntax_check ) ) {
            return $this->error_response( 'PHP_SYNTAX_ERROR', 'Replacement would produce invalid PHP: ' . $syntax_check->get_error_message() );
        }

        // Create backup before writing.
        $this->security->create_backup( $path );

        $result = $this->filesystem->write( $path, $new_content );
        if ( is_wp_error( $result ) ) {
            return $this->error_response( 'WRITE_FAILED', $result->get_error_message() );
        }

        return $this->success_response( "Replaced {$count} occurrence(s) in {$args['path']}. Backup created." );
    }

    /**
     * Tool: preview_file_change
     * Compute a diff between the current file and proposed new content.
     * Does NOT write anything — safe, read-only preview.
     */
    private function tool_preview_file_change( $args ) {
        $path = $this->resolve_and_validate_path( $args['path'] ?? '' );
        if ( is_array( $path ) && isset( $path['success'] ) ) {
            return $path;
        }

        if ( ! $this->filesystem->exists( $path ) ) {
            return $this->error_response( 'FILE_NOT_FOUND', "File does not exist: {$args['path']}" );
        }

        $old_content = $this->filesystem->read( $path );
        if ( is_wp_error( $old_content ) ) {
            return $this->error_response( 'READ_FAILED', $old_content->get_error_message() );
        }

        $new_content = $args['new_content'] ?? '';
        $diff        = $this->compute_diff( $old_content, $new_content );

        if ( empty( $diff ) ) {
            return $this->success_response( 'No changes detected. New content is identical to existing file.' );
        }

        $output  = "Diff preview for {$args['path']}:\n";
        $output .= "Lines changed: " . count( array_filter( $diff, function( $h ) { return $h['type'] !== 'context'; } ) ) . "\n\n";
        $output .= $this->format_diff_for_display( $diff );

        return $this->success_response( $output );
    }

    /**
     * Tool: get_wordpress_info
     * Return environment info to help the agent orient itself.
     */
    private function tool_get_wordpress_info( $args ) {
        $abspath   = rtrim( ABSPATH, '/' );
        $theme_dir = get_stylesheet_directory();
        $theme_rel = ltrim( str_replace( wp_normalize_path( $abspath ), '', wp_normalize_path( $theme_dir ) ), '/' );

        // Build relative allowed paths for the AI.
        $allowed_abs = get_option( 'bosscode_ai_allowed_paths', array() );
        $allowed_rel = array();
        foreach ( (array) $allowed_abs as $abs ) {
            $rel = ltrim( str_replace( wp_normalize_path( $abspath ), '', wp_normalize_path( $abs ) ), '/' );
            if ( $rel ) $allowed_rel[] = $rel;
        }

        // Active plugins with relative paths.
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_dirs    = array();
        foreach ( $active_plugins as $plugin_file ) {
            $plugin_dirs[] = 'wp-content/plugins/' . dirname( $plugin_file );
        }

        $info = array(
            'wp_version'         => get_bloginfo( 'version' ),
            'php_version'        => phpversion(),
            'active_theme'       => wp_get_theme()->get( 'Name' ),
            'theme_dir_relative' => $theme_rel,
            'allowed_paths'      => $allowed_rel,
            'active_plugin_dirs' => $plugin_dirs,
            'site_url'           => site_url(),
            'abspath'            => ABSPATH,
            'fs_method'          => $this->filesystem->get_method(),
            'USAGE_NOTE'         => 'Use paths from allowed_paths or theme_dir_relative. Do NOT use paths outside those.',
        );

        $cpts = get_post_types( array( '_builtin' => false ), 'names' );
        $info['custom_post_types'] = array_values( $cpts );

        return $this->success_response( wp_json_encode( $info, JSON_PRETTY_PRINT ) );
    }

    // ─── P5: WordPress Content Tools ─────────────────────────────────

    /** Tool: list_posts */
    private function tool_list_posts( $args ) {
        $query_args = array(
            'post_type'      => sanitize_text_field( $args['post_type'] ?? 'post' ),
            'post_status'    => sanitize_text_field( $args['status'] ?? 'any' ),
            'posts_per_page' => min( absint( $args['limit'] ?? 20 ), 50 ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }
        $query = new WP_Query( $query_args );
        $posts = array();
        foreach ( $query->posts as $p ) {
            $posts[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'type' => $p->post_type, 'slug' => $p->post_name, 'date' => $p->post_date, 'url' => get_permalink( $p->ID ) );
        }
        return $this->success_response( wp_json_encode( $posts, JSON_PRETTY_PRINT ) );
    }

    /** Tool: get_post */
    private function tool_get_post( $args ) {
        $id   = absint( $args['id'] ?? 0 );
        $post = get_post( $id );
        if ( ! $post ) return $this->error_response( 'NOT_FOUND', "Post ID {$id} not found." );
        $data = array(
            'id' => $post->ID, 'title' => $post->post_title, 'content' => $post->post_content,
            'excerpt' => $post->post_excerpt, 'status' => $post->post_status, 'type' => $post->post_type,
            'slug' => $post->post_name, 'url' => get_permalink( $post->ID ),
            'template' => get_page_template_slug( $post->ID ) ?: 'default',
            'meta' => get_post_meta( $post->ID ),
        );
        return $this->success_response( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
    }

    /** Tool: create_post */
    private function tool_create_post( $args ) {
        $postarr = array(
            'post_title'   => sanitize_text_field( $args['title'] ?? 'Untitled' ),
            'post_content' => $args['content'] ?? '',
            'post_status'  => sanitize_text_field( $args['status'] ?? 'draft' ),
            'post_type'    => sanitize_text_field( $args['post_type'] ?? 'post' ),
        );
        if ( ! empty( $args['excerpt'] ) ) $postarr['post_excerpt'] = $args['excerpt'];
        if ( ! empty( $args['slug'] ) )    $postarr['post_name']    = sanitize_title( $args['slug'] );

        $id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $id ) ) return $this->error_response( 'CREATE_FAILED', $id->get_error_message() );
        return $this->success_response( "Created {$postarr['post_type']} ID {$id}: \"{$postarr['post_title']}\" ({$postarr['post_status']})." );
    }

    /** Tool: update_post */
    private function tool_update_post( $args ) {
        $id = absint( $args['id'] ?? 0 );
        if ( ! get_post( $id ) ) return $this->error_response( 'NOT_FOUND', "Post ID {$id} not found." );
        $postarr = array( 'ID' => $id );
        if ( isset( $args['title'] ) )   $postarr['post_title']   = sanitize_text_field( $args['title'] );
        if ( isset( $args['content'] ) ) $postarr['post_content'] = $args['content'];
        if ( isset( $args['status'] ) )  $postarr['post_status']  = sanitize_text_field( $args['status'] );
        if ( isset( $args['excerpt'] ) ) $postarr['post_excerpt'] = $args['excerpt'];

        $result = wp_update_post( $postarr, true );
        if ( is_wp_error( $result ) ) return $this->error_response( 'UPDATE_FAILED', $result->get_error_message() );
        return $this->success_response( "Updated post ID {$id}." );
    }

    /** Tool: delete_post */
    private function tool_delete_post( $args ) {
        $id    = absint( $args['id'] ?? 0 );
        $force = ! empty( $args['force'] );
        $post  = get_post( $id );
        if ( ! $post ) return $this->error_response( 'NOT_FOUND', "Post ID {$id} not found." );
        $result = wp_delete_post( $id, $force );
        if ( ! $result ) return $this->error_response( 'DELETE_FAILED', 'Failed to delete post.' );
        return $this->success_response( ( $force ? 'Permanently deleted' : 'Trashed' ) . " post ID {$id}." );
    }

    /** Tool: list_plugins */
    private function tool_list_plugins( $args ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all    = get_plugins();
        $active = get_option( 'active_plugins', array() );
        $list   = array();
        foreach ( $all as $file => $data ) {
            $list[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => in_array( $file, $active, true ) );
        }
        return $this->success_response( wp_json_encode( $list, JSON_PRETTY_PRINT ) );
    }

    /** Tool: list_themes */
    private function tool_list_themes( $args ) {
        $themes      = wp_get_themes();
        $active_slug = get_stylesheet();
        $list = array();
        foreach ( $themes as $slug => $theme ) {
            $list[] = array( 'slug' => $slug, 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'active' => $slug === $active_slug );
        }
        return $this->success_response( wp_json_encode( $list, JSON_PRETTY_PRINT ) );
    }

    /** Tool: list_media */
    private function tool_list_media( $args ) {
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => min( absint( $args['limit'] ?? 20 ), 50 ),
        ) );
        $items = array();
        foreach ( $query->posts as $a ) {
            $items[] = array( 'id' => $a->ID, 'title' => $a->post_title, 'url' => wp_get_attachment_url( $a->ID ), 'type' => $a->post_mime_type );
        }
        return $this->success_response( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
    }

    /** Tool: get_site_options */
    private function tool_get_site_options( $args ) {
        $safe_options = array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'permalink_structure', 'template', 'stylesheet', 'WPLANG' );
        $result = array();
        foreach ( $safe_options as $opt ) {
            $result[ $opt ] = get_option( $opt );
        }
        return $this->success_response( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
    }

    /** Tool: update_site_option */
    private function tool_update_site_option( $args ) {
        $allowed = array( 'blogname', 'blogdescription', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'permalink_structure' );
        $option  = sanitize_text_field( $args['option'] ?? '' );
        if ( ! in_array( $option, $allowed, true ) ) {
            return $this->error_response( 'OPTION_NOT_ALLOWED', "Cannot modify option: {$option}. Allowed: " . implode( ', ', $allowed ) );
        }
        $value = sanitize_text_field( $args['value'] ?? '' );
        update_option( $option, $value );
        return $this->success_response( "Updated option '{$option}' to '{$value}'." );
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Resolve a raw path argument and validate it against the security whitelist.
     *
     * @param string $raw_path  The raw path from tool arguments.
     * @param bool   $allow_new If true, allows paths to non-existent files (for create_file).
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
            return $this->error_response(
                'PATH_NOT_ALLOWED',
                "Access denied for path: {$raw_path}. Path is outside allowed directories. Call get_wordpress_info to discover valid paths."
            );
        }

        return $sanitized;
    }

    /**
     * Validate PHP syntax before writing .php files.
     * Uses `php -l` via exec() if available; falls back to token_get_all().
     *
     * @param string $content  File content to validate.
     * @param string $path     File path (used to determine if it's a .php file).
     * @return true|WP_Error  True if valid (or non-PHP file), WP_Error on syntax failure.
     */
    private function validate_php_syntax( $content, $path ) {
        // Only validate .php files.
        if ( pathinfo( $path, PATHINFO_EXTENSION ) !== 'php' ) {
            return true;
        }

        // Method 1: Use php -l via exec() for definitive validation.
        $exec_available = function_exists( 'exec' )
            && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );

        if ( $exec_available ) {
            $tmp = wp_tempnam( 'bosscode_phplint' );
            // Use raw file_put_contents for temp file only (not a WordPress-managed file).
            file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $output    = array();
            $exit_code = 0;
            @exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1', $output, $exit_code ); // phpcs:ignore
            @unlink( $tmp ); // phpcs:ignore

            if ( $exit_code !== 0 ) {
                $error_msg = implode( "\n", $output );
                return new WP_Error( 'php_syntax_error', 'PHP syntax error: ' . $error_msg );
            }
            return true;
        }

        // Method 2: Fallback — use token_get_all() for basic syntactic check.
        // This catches obvious parse errors but is less comprehensive than php -l.
        $previous_handler = set_error_handler( '__return_false' );
        try {
            $tokens = @token_get_all( $content, TOKEN_PARSE ); // phpcs:ignore
        } catch ( \ParseError $e ) {
            restore_error_handler();
            if ( $previous_handler ) set_error_handler( $previous_handler );
            return new WP_Error( 'php_syntax_error', 'PHP parse error (token_get_all): ' . $e->getMessage() );
        }
        restore_error_handler();
        if ( $previous_handler ) set_error_handler( $previous_handler );

        // Note: token_get_all is not 100% reliable — exec() is preferred.
        return true;
    }

    /**
     * Compute a simple line-based diff between two strings.
     *
     * @param string $old Original content.
     * @param string $new New content.
     * @return array Array of {type: 'add'|'remove'|'context', line: int, content: string}
     */
    private function compute_diff( $old, $new ) {
        $old_lines = explode( "\n", $old );
        $new_lines = explode( "\n", $new );
        $diff      = array();
        $context   = 3;

        // Build LCS matrix.
        $m = count( $old_lines );
        $n = count( $new_lines );

        // For large files, use a simpler heuristic.
        if ( $m > 500 || $n > 500 ) {
            // Fast path: just mark all old as removed and all new as added.
            foreach ( $old_lines as $i => $line ) {
                $diff[] = array( 'type' => 'remove', 'line' => $i + 1, 'content' => $line );
            }
            foreach ( $new_lines as $i => $line ) {
                $diff[] = array( 'type' => 'add', 'line' => $i + 1, 'content' => $line );
            }
            return $diff;
        }

        // Standard LCS diff.
        $lcs = array();
        for ( $i = 0; $i <= $m; $i++ ) {
            $lcs[ $i ] = array_fill( 0, $n + 1, 0 );
        }
        for ( $i = 1; $i <= $m; $i++ ) {
            for ( $j = 1; $j <= $n; $j++ ) {
                if ( $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
                    $lcs[ $i ][ $j ] = $lcs[ $i - 1 ][ $j - 1 ] + 1;
                } else {
                    $lcs[ $i ][ $j ] = max( $lcs[ $i - 1 ][ $j ], $lcs[ $i ][ $j - 1 ] );
                }
            }
        }

        // Traceback to build diff.
        $raw_diff = array();
        $i = $m; $j = $n;
        while ( $i > 0 || $j > 0 ) {
            if ( $i > 0 && $j > 0 && $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
                array_unshift( $raw_diff, array( 'type' => 'context', 'old_line' => $i, 'new_line' => $j, 'content' => $old_lines[ $i - 1 ] ) );
                $i--; $j--;
            } elseif ( $j > 0 && ( $i === 0 || $lcs[ $i ][ $j - 1 ] >= $lcs[ $i - 1 ][ $j ] ) ) {
                array_unshift( $raw_diff, array( 'type' => 'add', 'old_line' => $i, 'new_line' => $j, 'content' => $new_lines[ $j - 1 ] ) );
                $j--;
            } else {
                array_unshift( $raw_diff, array( 'type' => 'remove', 'old_line' => $i, 'new_line' => $j, 'content' => $old_lines[ $i - 1 ] ) );
                $i--;
            }
        }

        // Filter to only include context lines near changes.
        $changed_indices = array();
        foreach ( $raw_diff as $idx => $entry ) {
            if ( $entry['type'] !== 'context' ) {
                for ( $c = max( 0, $idx - $context ); $c <= min( count( $raw_diff ) - 1, $idx + $context ); $c++ ) {
                    $changed_indices[ $c ] = true;
                }
            }
        }

        foreach ( $raw_diff as $idx => $entry ) {
            if ( isset( $changed_indices[ $idx ] ) ) {
                $diff[] = array(
                    'type'    => $entry['type'],
                    'line'    => $entry['type'] === 'remove' ? $entry['old_line'] : $entry['new_line'],
                    'content' => $entry['content'],
                );
            }
        }

        return $diff;
    }

    /**
     * Format a diff array as a readable unified-diff string.
     *
     * @param array $diff Output from compute_diff().
     * @return string Formatted diff string.
     */
    private function format_diff_for_display( $diff ) {
        $out = '';
        foreach ( $diff as $entry ) {
            switch ( $entry['type'] ) {
                case 'add':
                    $out .= "+ {$entry['line']}: {$entry['content']}\n";
                    break;
                case 'remove':
                    $out .= "- {$entry['line']}: {$entry['content']}\n";
                    break;
                case 'context':
                    $out .= "  {$entry['line']}: {$entry['content']}\n";
                    break;
            }
        }
        return $out;
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
                if ( in_array( $item, array( 'node_modules', '.git', 'vendor', 'bosscode-backups', 'cache', 'dist', 'build' ), true ) ) continue;
                $this->search_recursive( $full, $query, $extensions, $matches, $limit );
            } elseif ( is_file( $full ) ) {
                $ext = pathinfo( $full, PATHINFO_EXTENSION );
                if ( ! in_array( $ext, $extensions, true ) ) continue;

                // Use raw file() for search reads — not modifying, just reading line by line.
                $lines = @file( $full, FILE_IGNORE_NEW_LINES ); // phpcs:ignore
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
