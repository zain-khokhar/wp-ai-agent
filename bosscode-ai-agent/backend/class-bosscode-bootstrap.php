<?php
/**
 * BossCode AI Agent — Bootstrap & Initialization (v2)
 *
 * @package BossCode_AI_Agent
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Bootstrap {

    private static $instance = null;
    private $services = array();

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_services();
        $this->register_hooks();
    }

    private function __clone() {}
    public function __wakeup() { throw new Exception( 'Cannot unserialize singleton.' ); }

    private function load_dependencies() {
        $backend_dir = BOSSCODE_PLUGIN_DIR . 'backend/';

        require_once $backend_dir . 'class-bosscode-security.php';
        require_once $backend_dir . 'class-bosscode-filesystem.php';   // P1-01: WP_Filesystem abstraction
        require_once $backend_dir . 'class-bosscode-settings.php';
        require_once $backend_dir . 'class-bosscode-ai-client.php';
        require_once $backend_dir . 'class-bosscode-tools.php';
        require_once $backend_dir . 'class-bosscode-tool-executor.php';
        require_once $backend_dir . 'class-bosscode-vector-store.php';
        require_once $backend_dir . 'class-bosscode-search-index.php';
        require_once $backend_dir . 'class-bosscode-rag-engine.php';
        require_once $backend_dir . 'class-bosscode-stream.php';
        require_once $backend_dir . 'class-bosscode-job-manager.php';
        require_once $backend_dir . 'class-bosscode-session-manager.php'; // P2-02: conversation persistence
        require_once $backend_dir . 'class-bosscode-context-manager.php'; // P4-01: token management
        require_once $backend_dir . 'class-bosscode-confirmation-manager.php'; // P3-04: human-in-the-loop
        require_once $backend_dir . 'class-bosscode-rate-limiter.php';    // P6-02: request throttling
        require_once $backend_dir . 'class-bosscode-rollback-manager.php'; // P6-05: file backup/restore
        require_once $backend_dir . 'class-bosscode-error-handler.php';   // P7-05: error recovery
        require_once $backend_dir . 'class-bosscode-capabilities.php';    // P7-01: capabilities
        require_once $backend_dir . 'class-bosscode-concurrency.php';     // P7-02: concurrency locks
        require_once $backend_dir . 'class-bosscode-api-routes.php';
    }

    private function init_services() {
        $this->services['security']        = new BossCode_Security();
        // P1-04: Initialize WP_Filesystem abstraction layer.
        $this->services['filesystem']      = new BossCode_Filesystem();
        $this->services['filesystem']->init(); // Best-effort init; will lazy-init on first use if needed.
        $this->services['settings']        = new BossCode_Settings( $this->services['security'] );
        $this->services['ai_client']       = new BossCode_AI_Client( $this->services['settings'] );
        $this->services['tools']           = new BossCode_Tools();
        // P1-02: Inject BossCode_Filesystem into BossCode_Tool_Executor.
        $this->services['tool_executor']   = new BossCode_Tool_Executor(
            $this->services['security'],
            $this->services['filesystem']
        );
        $this->services['vector_store']    = new BossCode_Vector_Store();
        $this->services['search_index']    = new BossCode_Search_Index();
        // RAG engine uses SearchIndex instead of AI Client.
        $this->services['rag_engine']      = new BossCode_RAG_Engine(
            $this->services['search_index'],
            $this->services['vector_store'],
            $this->services['settings']
        );
        $this->services['job_manager']     = new BossCode_Job_Manager();
        // P2-02: Conversation session manager.
        $this->services['session_manager'] = new BossCode_Session_Manager();
        // P3-04: Confirmation manager for destructive tool calls.
        $this->services['confirm_manager'] = new BossCode_Confirmation_Manager();
        // P6-02: Rate limiter.
        $this->services['rate_limiter']    = new BossCode_Rate_Limiter();
        // P6-05: Rollback / backup manager.
        $this->services['rollback']        = new BossCode_Rollback_Manager( $this->services['filesystem'] );
        $this->services['error_handler']   = new BossCode_Error_Handler();
        $this->services['api_routes']      = new BossCode_API_Routes(
            $this->services['settings'],
            $this->services['ai_client'],
            $this->services['tools'],
            $this->services['tool_executor'],
            $this->services['rag_engine'],
            $this->services['job_manager'],
            $this->services['session_manager'],
            $this->services['filesystem']
        );
    }

    /**
     * Get a service from the DI container.
     *
     * @param string $name Service name.
     * @return object|null Service instance or null if not found.
     */
    public function get_service( $name ) {
        return isset( $this->services[ $name ] ) ? $this->services[ $name ] : null;
    }

    private function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_print_footer_scripts', array( $this, 'print_footer_scripts' ) );
        add_action( 'rest_api_init', array( $this->services['api_routes'], 'register_routes' ) );
        add_action( 'wp_ajax_' . BossCode_Job_Manager::AJAX_ACTION, array( $this, 'handle_async_job' ) );
        add_action( 'admin_init', array( $this, 'maybe_recover_jobs' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_floating_widget' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_floating_widget_frontend' ) );
        // P7-04: Daily backup purge cron.
        add_action( 'bosscode_daily_purge', array( $this, 'run_backup_purge' ) );
    }

    /**
     * P7-04: Purge old backups (called by wp_cron daily).
     */
    public function run_backup_purge() {
        $rollback = $this->get_service( 'rollback' );
        if ( $rollback ) {
            $rollback->purge_old( 30 );
        }
    }

    /**
     * Activation — fix default paths to include themes AND plugins.
     */
    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Embeddings table (existing) ──────────────────────────────
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bosscode_embeddings (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            file_path VARCHAR(500) NOT NULL,
            chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
            chunk_content LONGTEXT NOT NULL,
            embedding LONGTEXT DEFAULT NULL,
            token_count INT UNSIGNED NOT NULL DEFAULT 0,
            file_hash VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_file_path (file_path(191)),
            KEY idx_file_hash (file_hash)
        ) {$charset_collate};";
        dbDelta( $sql );

        // ── P2-01: Sessions table ────────────────────────────────────
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bosscode_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_uuid VARCHAR(36) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT 'New Session',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_uuid (session_uuid),
            KEY idx_user (user_id)
        ) {$charset_collate};";
        dbDelta( $sql );

        // ── P2-01: Messages table ────────────────────────────────────
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bosscode_messages (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_uuid VARCHAR(36) NOT NULL,
            role VARCHAR(20) NOT NULL,
            content LONGTEXT NOT NULL,
            tool_calls LONGTEXT DEFAULT NULL,
            tool_call_id VARCHAR(64) DEFAULT NULL,
            tool_name VARCHAR(128) DEFAULT NULL,
            iteration INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session (session_uuid),
            KEY idx_created (created_at)
        ) {$charset_collate};";
        dbDelta( $sql );

        // ── P6-01: Audit log table ───────────────────────────────────
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bosscode_audit (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            session_uuid VARCHAR(36) DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            target TEXT DEFAULT NULL,
            meta LONGTEXT DEFAULT NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'success',
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) {$charset_collate};";
        dbDelta( $sql );

        // ── Default options ──────────────────────────────────────────
        add_option( 'bosscode_ai_provider', 'openai_compatible' );
        add_option( 'bosscode_ai_base_url', 'http://localhost:11434/v1' );
        add_option( 'bosscode_ai_api_key', '' );
        add_option( 'bosscode_ai_model', 'local-model' );
        add_option( 'bosscode_ai_max_loop_iterations', 15 );
        add_option( 'bosscode_ai_embedding_model', 'text-embedding-3-small' );
        add_option( 'bosscode_ai_gemini_auto_url', 'http://localhost:3200' );
        add_option( 'bosscode_ai_gemini_auto_enabled', false );

        // Include both themes AND plugins directories by default.
        $allowed_paths = array(
            get_stylesheet_directory(),
            WP_CONTENT_DIR . '/plugins',
        );

        // If parent theme differs from child theme, add it too.
        if ( get_template_directory() !== get_stylesheet_directory() ) {
            $allowed_paths[] = get_template_directory();
        }

        add_option( 'bosscode_ai_allowed_paths', $allowed_paths );

        // Update existing installations that only have theme dir.
        $existing = get_option( 'bosscode_ai_allowed_paths' );
        if ( is_array( $existing ) && count( $existing ) === 1 ) {
            $plugins_dir = WP_CONTENT_DIR . '/plugins';
            if ( ! in_array( $plugins_dir, $existing, true ) ) {
                $existing[] = $plugins_dir;
                update_option( 'bosscode_ai_allowed_paths', $existing );
            }
        }

        update_option( 'bosscode_ai_version', BOSSCODE_VERSION );
        flush_rewrite_rules();

        // P7-04: Schedule daily backup purge.
        if ( ! wp_next_scheduled( 'bosscode_daily_purge' ) ) {
            wp_schedule_event( time(), 'daily', 'bosscode_daily_purge' );
        }

        BossCode_Capabilities::add_caps_to_admin();
    }

    /**
     * Deactivation — clean up cron events and transients.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'bosscode_daily_purge' );
        delete_transient( 'bosscode_index_progress' );
        delete_transient( 'bosscode_active_jobs' );
        flush_rewrite_rules();

        BossCode_Capabilities::remove_caps_from_admin();
    }

    public function handle_async_job() {
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( $_POST['job_id'] ) : '';
        $nonce  = isset( $_POST['nonce'] )  ? sanitize_text_field( $_POST['nonce'] ) : '';

        if ( empty( $job_id ) || ! wp_verify_nonce( $nonce, 'bosscode_job_' . $job_id ) ) {
            wp_die( 'Unauthorized', 403 );
        }

        $jm  = $this->services['job_manager'];
        $job = $jm->get( $job_id );

        if ( ! $job || self::is_terminal_status( $job['status'] ) ) {
            wp_die( 'Invalid job' );
        }

        if ( ! ini_get( 'safe_mode' ) ) {
            set_time_limit( 300 );
        }
        ignore_user_abort( true );

        $jm->start( $job_id );

        switch ( $job['type'] ) {
            case 'index':
                $this->run_index_job( $job_id, $job );
                break;
            default:
                $jm->fail( $job_id, 'Unknown job type: ' . $job['type'] );
                break;
        }

        wp_die();
    }

    private function run_index_job( $job_id, $job ) {
        $jm  = $this->services['job_manager'];
        $rag = $this->services['rag_engine'];

        if ( ! BossCode_Concurrency_Locks::acquire( 'rag_index', 600 ) ) {
            $jm->fail( $job_id, 'Another indexing job is currently running.' );
            return;
        }

        try {
            $result = $rag->index_all( function( $current, $total, $file ) use ( $jm, $job_id ) {
                $jm->update_progress( $job_id, $current, $total, basename( $file ) );
            } );
            $jm->complete( $job_id, $result );
        } catch ( \Exception $e ) {
            $jm->fail( $job_id, $e->getMessage() );
        }

        BossCode_Concurrency_Locks::release( 'rag_index' );
    }

    public function maybe_recover_jobs() {
        $last = get_transient( 'bosscode_recovery_check' );
        if ( $last ) return;
        set_transient( 'bosscode_recovery_check', 1, 60 );
        $this->services['job_manager']->recover_crashed();
    }

    private static function is_terminal_status( $status ) {
        return in_array( $status, array( BossCode_Job_Manager::COMPLETE, BossCode_Job_Manager::FAILED ), true );
    }

    public function add_admin_menu() {
        add_menu_page( 'BossCode AI Agent', 'BossCode AI', 'manage_options', 'bosscode-ai', array( $this, 'render_admin_page' ), 'dashicons-superhero', 25 );
    }

    public function render_admin_page() {
        echo '<div class="wrap"><div id="bosscode-ai-app"></div></div>';
    }

    public function enqueue_scripts( $hook ) {
        if ( 'toplevel_page_bosscode-ai' !== $hook ) return;

        wp_enqueue_script( 'bosscode-app', BOSSCODE_PLUGIN_URL . 'frontend/dist/app.js', array(), BOSSCODE_VERSION, true );
        wp_enqueue_style( 'bosscode-ai-styles', BOSSCODE_PLUGIN_URL . 'frontend/styles/bosscode.css', array(), BOSSCODE_VERSION );

        wp_localize_script( 'bosscode-app', 'bosscodeAI', array(
            'restUrl' => esc_url_raw( rest_url( 'bosscode-ai/v1' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'version' => BOSSCODE_VERSION,
        ) );
    }

    public function print_footer_scripts() {
        return; // Legacy — no longer needed
    }

    public function enqueue_floating_widget( $hook ) {
        if ( 'toplevel_page_bosscode-ai' === $hook ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        $this->register_floating_widget_assets();
    }

    public function enqueue_floating_widget_frontend() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
        $this->register_floating_widget_assets();
    }

    private function register_floating_widget_assets() {
        wp_enqueue_style( 'bosscode-floating-widget', BOSSCODE_PLUGIN_URL . 'frontend/styles/floating-widget.css', array(), BOSSCODE_VERSION );
        wp_enqueue_script( 'bosscode-floating-widget', BOSSCODE_PLUGIN_URL . 'frontend/floating-widget.js', array(), BOSSCODE_VERSION, true );

        wp_localize_script( 'bosscode-floating-widget', 'bosscodeWidget', array(
            'restUrl'     => esc_url_raw( rest_url( 'bosscode-ai/v1' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'adminUrl'    => admin_url( 'admin.php?page=bosscode-ai' ),
            'siteUrl'     => site_url(),
            'postId'      => $this->get_current_post_id(),
            'isEditor'    => $this->is_page_builder_active(),
            'currentPage' => $this->get_current_page_context(),
        ) );
    }

    private function get_current_page_context() {
        if ( is_admin() ) {
            $screen = get_current_screen();
            return $screen ? 'WordPress Admin: ' . $screen->id : 'WordPress Admin';
        }
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( home_url( $_SERVER['REQUEST_URI'] ) ) : home_url();
    }

    /**
     * Get the current post ID if editing a post/page.
     * Reads $_GET['post'] directly so it works in Elementor/Gutenberg/WPBakery.
     */
    private function get_current_post_id() {
        // Direct URL param — works for Elementor (?post=123&action=elementor)
        if ( isset( $_GET['post'] ) && absint( $_GET['post'] ) > 0 ) {
            return absint( $_GET['post'] );
        }
        // Gutenberg / classic editor
        if ( isset( $_GET['post_id'] ) && absint( $_GET['post_id'] ) > 0 ) {
            return absint( $_GET['post_id'] );
        }
        // Fallback: global $post object
        if ( is_admin() ) {
            global $post;
            if ( $post && $post->ID ) return $post->ID;
        }
        return 0;
    }

    /**
     * Detect if a page builder is active on the current screen.
     */
    private function is_page_builder_active() {
        // Elementor
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) return 'elementor';
        // WPBakery
        if ( isset( $_GET['vc_action'] ) || isset( $_GET['vcv-action'] ) ) return 'wpbakery';
        return false;
    }
}
