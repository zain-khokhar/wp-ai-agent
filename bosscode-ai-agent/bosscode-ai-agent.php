<?php
/**
 * Plugin Name: BossCode AI Agent
 * Description: An agentic IDE inside the WordPress dashboard with support for local AI models.
 * Version: 1.0.0
 * Author: BossCode
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'BOSSCODE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOSSCODE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include backend files
require_once BOSSCODE_PLUGIN_DIR . 'backend/ai-handler.php';
require_once BOSSCODE_PLUGIN_DIR . 'backend/api-routes.php';

// Hook to add admin menu
add_action( 'admin_menu', 'bosscode_ai_add_admin_menu' );

function bosscode_ai_add_admin_menu() {
    add_menu_page(
        'BossCode AI Agent',             // Page title
        'BossCode AI',                   // Menu title
        'manage_options',                // Capability required
        'bosscode-ai',                   // Menu slug
        'bosscode_ai_render_admin_page', // Callback function to render page
        'dashicons-superhero',           // Icon url (using WordPress dashicon)
        25                               // Position
    );
}

// Render the container for our React app
function bosscode_ai_render_admin_page() {
    echo '<div class="wrap"><div id="bosscode-ai-app"></div></div>';
}

// Enqueue scripts (React + Babel for prototype JSX compiling)
add_action( 'admin_enqueue_scripts', 'bosscode_ai_enqueue_scripts' );

function bosscode_ai_enqueue_scripts( $hook ) {
    // Only load on our plugin's admin page to prevent conflicts
    if ( $hook !== 'toplevel_page_bosscode-ai' ) {
        return;
    }

    // Since this is a prototype and we want the JSX file to be run without a build step,
    // we use React, ReactDOM from CDNs, and Babel standalone to parse JSX on the fly.
    // In production, you would pre-build the React app into the `build/` folder.
    wp_enqueue_script( 'react', 'https://unpkg.com/react@18/umd/react.production.min.js', array(), '18.0.0', true );
    wp_enqueue_script( 'react-dom', 'https://unpkg.com/react-dom@18/umd/react-dom.production.min.js', array('react'), '18.0.0', true );
    wp_enqueue_script( 'babel', 'https://unpkg.com/babel-standalone@6/babel.min.js', array(), '6.26.0', true );

    // Pass necessary localized variables (Nonce, REST URL) to JavaScript
    $nonce = wp_create_nonce( 'wp_rest' );
    wp_localize_script( 'react', 'bosscodeAI', array(
        'restUrl' => esc_url_raw( rest_url( 'bosscode-ai/v1' ) ),
        'nonce'   => $nonce
    ) );
}

// Output the frontend App script using type="text/babel"
add_action('admin_print_footer_scripts', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_bosscode-ai' ) {
        return;
    }
    ?>
    <!-- The app.js script loaded with text/babel so Babel compiles the JSX in the browser -->
    <script src="<?php echo esc_url(BOSSCODE_PLUGIN_URL . 'frontend/app.js'); ?>" type="text/babel"></script>
    <?php
});
