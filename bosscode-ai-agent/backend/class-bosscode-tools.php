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

    private $destructive_tools = array( 'write_file', 'delete_file', 'replace_in_file' );

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
