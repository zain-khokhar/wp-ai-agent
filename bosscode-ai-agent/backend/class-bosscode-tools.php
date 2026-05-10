<?php
/**
 * BossCode AI Agent — Tool Registry
 *
 * Defines all available tools as OpenAI-compatible JSON schemas.
 *
 * @package BossCode_AI_Agent
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Tools {

    private $tools = array();

    private $destructive_tools = array( 'write_file', 'delete_file', 'replace_in_file', 'create_post', 'update_post', 'delete_post', 'update_site_option' );

    public function __construct() {
        $this->register_builtin_tools();
    }

    private function register_builtin_tools() {
        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'read_file',
                'description' => 'Read the contents of a file at the given path.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'path' => array( 'type' => 'string', 'description' => 'Relative path from WordPress root.' ),
                    ),
                    'required' => array( 'path' ),
                ),
            ),
        ) );

        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'write_file',
                'description' => 'Write content to an existing file, replacing its contents. A backup is created automatically.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'path' => array( 'type' => 'string', 'description' => 'Relative path from WordPress root.' ),
                        'content' => array( 'type' => 'string', 'description' => 'The complete new content.' ),
                    ),
                    'required' => array( 'path', 'content' ),
                ),
            ),
        ) );

        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'create_file',
                'description' => 'Create a new file. Fails if the file already exists.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'path' => array( 'type' => 'string', 'description' => 'Relative path for the new file.' ),
                        'content' => array( 'type' => 'string', 'description' => 'Content for the new file.' ),
                    ),
                    'required' => array( 'path', 'content' ),
                ),
            ),
        ) );

        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'delete_file',
                'description' => 'Delete a file. Requires user confirmation. A backup is created.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'path' => array( 'type' => 'string', 'description' => 'Relative path from WordPress root.' ),
                    ),
                    'required' => array( 'path' ),
                ),
            ),
        ) );

        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'list_directory',
                'description' => 'List files and subdirectories within a directory.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'path' => array( 'type' => 'string', 'description' => 'Relative directory path.' ),
                    ),
                    'required' => array( 'path' ),
                ),
            ),
        ) );

        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'search_files',
                'description' => 'Search for a text pattern across files in a directory.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'query' => array( 'type' => 'string', 'description' => 'Text pattern to search for.' ),
                        'path' => array( 'type' => 'string', 'description' => 'Directory to search within.' ),
                        'file_extensions' => array( 'type' => 'string', 'description' => 'Comma-separated extensions to filter (e.g. "php,js,css").' ),
                    ),
                    'required' => array( 'query', 'path' ),
                ),
            ),
        ) );

        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'replace_in_file',
                'description' => 'Find and replace text within a file. A backup is created.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'path' => array( 'type' => 'string', 'description' => 'Relative path from WordPress root.' ),
                        'search' => array( 'type' => 'string', 'description' => 'Exact text to find.' ),
                        'replace' => array( 'type' => 'string', 'description' => 'Replacement text.' ),
                    ),
                    'required' => array( 'path', 'search', 'replace' ),
                ),
            ),
        ) );

        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'get_wordpress_info',
                'description' => 'Get WordPress version, active theme, plugins, PHP version, and post types.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ),
            ),
        ) );

        // P3-03: preview_file_change — diff preview before writing.
        $this->register( array(
            'type' => 'function',
            'function' => array(
                'name' => 'preview_file_change',
                'description' => 'Preview what would change in a file before writing. Shows a unified diff. Does NOT write anything — read-only.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'path'        => array( 'type' => 'string', 'description' => 'Relative path to the file.' ),
                        'new_content' => array( 'type' => 'string', 'description' => 'The proposed new file content.' ),
                    ),
                    'required' => array( 'path', 'new_content' ),
                ),
            ),
        ) );

        // ─── P5: WordPress Content Tools ─────────────────────────────

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'list_posts', 'description' => 'List posts/pages. Supports filtering by type, status, search.',
            'parameters' => array( 'type' => 'object', 'properties' => array(
                'post_type' => array( 'type' => 'string', 'description' => 'Post type: post, page, or custom.' ),
                'status'    => array( 'type' => 'string', 'description' => 'Status filter: publish, draft, any.' ),
                'search'    => array( 'type' => 'string', 'description' => 'Search keyword.' ),
                'limit'     => array( 'type' => 'integer', 'description' => 'Max results (default 20, max 50).' ),
            ) ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'get_post', 'description' => 'Get full details of a post/page by ID.',
            'parameters' => array( 'type' => 'object', 'properties' => array(
                'id' => array( 'type' => 'integer', 'description' => 'Post ID.' ),
            ), 'required' => array( 'id' ) ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'create_post', 'description' => 'Create a new post or page.',
            'parameters' => array( 'type' => 'object', 'properties' => array(
                'title'     => array( 'type' => 'string', 'description' => 'Post title.' ),
                'content'   => array( 'type' => 'string', 'description' => 'Post content (HTML).' ),
                'status'    => array( 'type' => 'string', 'description' => 'draft or publish.' ),
                'post_type' => array( 'type' => 'string', 'description' => 'post or page.' ),
                'slug'      => array( 'type' => 'string', 'description' => 'URL slug.' ),
                'excerpt'   => array( 'type' => 'string', 'description' => 'Post excerpt.' ),
            ), 'required' => array( 'title' ) ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'update_post', 'description' => 'Update an existing post/page by ID.',
            'parameters' => array( 'type' => 'object', 'properties' => array(
                'id'      => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'title'   => array( 'type' => 'string', 'description' => 'New title.' ),
                'content' => array( 'type' => 'string', 'description' => 'New content.' ),
                'status'  => array( 'type' => 'string', 'description' => 'New status.' ),
                'excerpt' => array( 'type' => 'string', 'description' => 'New excerpt.' ),
            ), 'required' => array( 'id' ) ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'delete_post', 'description' => 'Delete (trash) a post/page by ID.',
            'parameters' => array( 'type' => 'object', 'properties' => array(
                'id'    => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'force' => array( 'type' => 'boolean', 'description' => 'True to permanently delete.' ),
            ), 'required' => array( 'id' ) ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'list_plugins', 'description' => 'List all installed plugins with active status.',
            'parameters' => array( 'type' => 'object', 'properties' => new \stdClass() ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'list_themes', 'description' => 'List all installed themes with active status.',
            'parameters' => array( 'type' => 'object', 'properties' => new \stdClass() ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'list_media', 'description' => 'List media library items.',
            'parameters' => array( 'type' => 'object', 'properties' => array(
                'limit' => array( 'type' => 'integer', 'description' => 'Max items (default 20).' ),
            ) ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'get_site_options', 'description' => 'Get safe WordPress site options (blogname, etc.).',
            'parameters' => array( 'type' => 'object', 'properties' => new \stdClass() ),
        ) ) );

        $this->register( array( 'type' => 'function', 'function' => array(
            'name' => 'update_site_option', 'description' => 'Update a safe site option (blogname, description, etc.).',
            'parameters' => array( 'type' => 'object', 'properties' => array(
                'option' => array( 'type' => 'string', 'description' => 'Option name (blogname, blogdescription, etc.).' ),
                'value'  => array( 'type' => 'string', 'description' => 'New value.' ),
            ), 'required' => array( 'option', 'value' ) ),
        ) ) );
    }

    public function register( $tool_definition ) {
        $name = $tool_definition['function']['name'];
        $this->tools[ $name ] = $tool_definition;
    }

    public function get_all_schemas() {
        return array_values( $this->tools );
    }

    public function get_schema( $name ) {
        return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
    }

    public function exists( $name ) {
        return isset( $this->tools[ $name ] );
    }

    public function is_destructive( $name ) {
        return in_array( $name, $this->destructive_tools, true );
    }

    public function get_tool_names() {
        return array_keys( $this->tools );
    }
}
