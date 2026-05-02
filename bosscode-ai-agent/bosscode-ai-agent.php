<?php
/**
 * Plugin Name: BossCode AI Agent
 * Description: An agentic IDE inside the WordPress dashboard — context-aware coding assistant with tool execution, RAG memory, and multi-provider LLM support.
 * Version: 2.0.1
 * Author: BossCode
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Plugin constants
define( 'BOSSCODE_VERSION', '2.0.1' );
define( 'BOSSCODE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOSSCODE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load the bootstrap class and initialize the plugin
require_once BOSSCODE_PLUGIN_DIR . 'backend/class-bosscode-bootstrap.php';

// Activation & deactivation hooks (must be registered before init)
register_activation_hook( __FILE__, array( 'BossCode_Bootstrap', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BossCode_Bootstrap', 'deactivate' ) );

// Initialize the plugin singleton
add_action( 'plugins_loaded', function() {
    BossCode_Bootstrap::get_instance();
} );
