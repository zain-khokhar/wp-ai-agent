# BossCode WP-IDE — Complete Production Breakdown
**For: Antigravity AI Agent Implementation**
**Audit Date:** May 2026 | **Codebase Version:** 2.0.2

---

## 1. OVERALL COMPLETION ESTIMATE

| Layer | Completion | Confidence |
|---|---|---|
| Plugin architecture & bootstrap | 90% | High |
| Security & path validation | 70% | High |
| AI client (multi-provider) | 75% | High |
| File tools (read/write/create/delete) | 65% | Medium |
| Agent loop (chat + streaming) | 60% | Medium |
| RAG / context retrieval | 55% | Medium |
| Background job system | 75% | High |
| Settings management | 85% | High |
| Frontend UI (React + Monaco) | 55% | Medium |
| Session / conversation persistence | 15% | High |
| WordPress content tools (posts, menus, etc.) | 10% | High |
| File access via WP_Filesystem API | 0% | High |
| Human-in-the-loop confirmation | 20% | Medium |
| Rate limiting & audit logging | 0% | High |
| Context window / token management | 20% | Medium |
| Rollback / undo UI | 5% | High |
| Diff/patch system | 0% | High |
| Agent planning & reflection loop | 15% | Medium |
| Error recovery & retry system | 35% | Medium |
| Permissions / capability model | 60% | Medium |

### **Global Completion: ~48%**

The architecture is solid. The plugin boots cleanly, the service container is well-structured, and the agent loop works. But ~52% of what makes this production-level and comparable to a VS Code AI extension is missing or fragile. The largest gaps are: file access reliability, conversation persistence, WordPress-native content tools, human confirmation, and a proper context management strategy.

---

## 2. WHAT IS ALREADY IMPLEMENTED (THE GOOD)

### 2.1 Plugin Skeleton & Service Container
- `bosscode-ai-agent.php` — clean entry point with activation/deactivation hooks
- `BossCode_Bootstrap` — singleton service container wiring all 10 backend classes
- Proper `plugins_loaded` hook, admin menu, script enqueue with nonce localization
- Floating widget injected into both admin and frontend for `manage_options` users
- Page builder detection (Elementor, WPBakery) — page context passed to widget

### 2.2 Settings Manager
- Full CRUD for 9 settings options with typed defaults
- AES-256-CBC encryption for API keys using WordPress auth salts as key material
- Masked display of sensitive values (last 4 chars visible)
- Key normalization (shorthand `api_key` → `bosscode_ai_api_key`)
- Graceful skip of masked/unchanged API key on update

### 2.3 Security Module
- Path sanitization: null-byte stripping, `..` rejection, cross-platform absolute detection
- `realpath()` resolution for existing files, parent-directory resolution for new files
- Whitelist enforcement via `bosscode_ai_allowed_paths` option
- Hard block on: `wp-config.php`, `.htaccess`, `wp-settings.php`, plugin's own `backend/` dir
- Timestamped file backups to `wp-content/bosscode-backups/` before any write/delete

### 2.4 AI Client
- Unified `chat_completion()` routing to: OpenAI-compatible, Anthropic, Groq, Gemini Auto
- Embedding endpoint via `create_embedding()`
- Anthropic messages API with correct `tool_use` / `tool_result` format
- Gemini Auto bridge (Puppeteer) — intentionally kept but should be isolated
- Normalized response envelope: `{finish_reason, content, tool_calls[]}`

### 2.5 Tool System
- `BossCode_Tools` — schema registry with 8 tools in OpenAI function-calling format
- `BossCode_Tool_Executor` — dispatches by method name `tool_{name}()`
- Tools: `read_file`, `write_file`, `create_file`, `delete_file`, `list_directory`, `search_files`, `replace_in_file`, `get_wordpress_info`
- `get_wordpress_info` returns allowed paths, theme dir, active plugins, custom post types — critical for agent orientation

### 2.6 Agent Loop
- Iterative tool-call loop up to `max_loop_iterations` (default 15, clamp 1–50)
- Circuit breaker: 2 consecutive `PATH_NOT_ALLOWED` errors auto-inject `get_wordpress_info` recovery message; 3rd triggers abort
- SSE streaming version with `send_iteration`, `send_tool_call`, `send_tool_status`, `send_done`
- `connection_aborted()` check on each iteration

### 2.7 RAG Engine (Keyword-Based)
- No AI API calls during indexing — pure PHP TF-IDF keyword extraction
- Chunking with configurable `MAX_CHUNK_TOKENS=800`, `OVERLAP_TOKENS=100`
- File hash change detection — only re-indexes modified files
- Skips `node_modules`, `.git`, `vendor`, `bosscode-backups`, `cache`, `dist`, `build`
- Indexes: `php, js, css, html, json, txt, jsx, ts, tsx, scss`

### 2.8 Background Job Manager
- UUID-based job creation stored in WordPress transients
- Non-blocking loopback via `wp_remote_post` with `timeout: 0.01` (fire-and-forget)
- Job lifecycle: `pending → running → complete | failed`
- Progress updates with `current/total/message`
- Crash recovery: `admin_init` hook detects jobs running > 5 min and marks them failed
- 1-hour TTL with automatic cleanup

### 2.9 Frontend (React + Monaco)
- Three-panel layout: File Tree | Monaco Editor | Chat
- `@`-mention system for pages, posts, plugins as context injection
- Attachment chips with lazy file loading
- SSE streaming consumer (EventSource)
- Monaco editor with language detection by file extension
- Floating widget on all admin pages

---

## 3. WHAT IS MISSING — FULL SPECIFICATION

---

### 3.1 🔴 CRITICAL: WordPress Filesystem API (WP_Filesystem)
**Status: 0% implemented — this is the root cause of all file access errors**

**The Problem:**
The plugin uses raw PHP `file_get_contents()`, `file_put_contents()`, `unlink()`, `wp_mkdir_p()` directly. On managed WordPress hosts (WP Engine, Kinsta, Flywheel, SiteGround, Pantheon), the web server process (www-data / nginx) often does NOT own the files. The files are owned by the deployment user (sftp user). Direct PHP file I/O silently fails or throws permission errors.

**The Solution — Implement `BossCode_Filesystem` wrapper:**

```php
<?php
/**
 * BossCode_Filesystem — WP_Filesystem abstraction layer.
 *
 * AGENT IMPLEMENTATION INSTRUCTIONS:
 * - Create file: backend/class-bosscode-filesystem.php
 * - Inject into BossCode_Tool_Executor via constructor
 * - Replace ALL raw file I/O in tool_read_file, tool_write_file,
 *   tool_create_file, tool_delete_file with calls to this class
 */
class BossCode_Filesystem {

    /** @var WP_Filesystem_Base */
    private $fs;

    /**
     * Initialize WP_Filesystem.
     *
     * Must be called after 'admin_init' hook.
     * Handles: Direct, FTP, SSH2, FTPext transport methods.
     * Falls back gracefully with a WP_Error if credentials needed.
     *
     * @return true|WP_Error
     */
    public function init() {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Try credential-less direct init first (most common on VPS/managed hosts)
        $credentials = request_filesystem_credentials( '', '', false, false, null );

        if ( false === $credentials ) {
            // Host requires FTP/SSH credentials — return WP_Error
            return new WP_Error(
                'fs_credentials_required',
                'WordPress filesystem requires FTP/SSH credentials. ' .
                'Please define FS_METHOD, FTP_HOST, FTP_USER, FTP_PASS in wp-config.php ' .
                'or set FS_METHOD to "direct" if the web server owns the files.'
            );
        }

        if ( ! WP_Filesystem( $credentials ) ) {
            return new WP_Error( 'fs_init_failed', 'WP_Filesystem initialization failed.' );
        }

        global $wp_filesystem;
        $this->fs = $wp_filesystem;
        return true;
    }

    /**
     * Read a file.
     *
     * @param string $abs_path Absolute path.
     * @return string|WP_Error File contents or error.
     */
    public function read( $abs_path ) {
        $this->ensure_init();

        if ( ! $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_not_found', "File does not exist: {$abs_path}" );
        }

        if ( ! $this->fs->is_readable( $abs_path ) ) {
            return new WP_Error( 'permission_denied', "Cannot read: {$abs_path}" );
        }

        $size = $this->fs->size( $abs_path );
        if ( $size > 512000 ) { // 500KB limit
            return new WP_Error( 'file_too_large', "File exceeds 500KB ({$size} bytes)." );
        }

        $content = $this->fs->get_contents( $abs_path );
        if ( false === $content ) {
            return new WP_Error( 'read_failed', "Failed to read: {$abs_path}" );
        }

        return $content;
    }

    /**
     * Write a file (must already exist).
     *
     * @param string $abs_path Absolute path.
     * @param string $content  New file contents.
     * @return true|WP_Error
     */
    public function write( $abs_path, $content ) {
        $this->ensure_init();

        if ( ! $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_not_found', 'Use create() for new files.' );
        }

        $result = $this->fs->put_contents( $abs_path, $content, FS_CHMOD_FILE );
        if ( ! $result ) {
            return new WP_Error( 'write_failed', "Failed to write: {$abs_path}. Check file ownership/permissions." );
        }

        return true;
    }

    /**
     * Create a new file (must NOT exist).
     *
     * @param string $abs_path Absolute path.
     * @param string $content  Initial contents.
     * @return true|WP_Error
     */
    public function create( $abs_path, $content = '' ) {
        $this->ensure_init();

        if ( $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_exists', 'File already exists. Use write() to modify.' );
        }

        // Ensure parent directory exists
        $dir = dirname( $abs_path );
        if ( ! $this->fs->is_dir( $dir ) ) {
            if ( ! $this->fs->mkdir( $dir, FS_CHMOD_DIR, true ) ) {
                return new WP_Error( 'mkdir_failed', "Cannot create directory: {$dir}" );
            }
        }

        $result = $this->fs->put_contents( $abs_path, $content, FS_CHMOD_FILE );
        if ( ! $result ) {
            return new WP_Error( 'create_failed', "Failed to create: {$abs_path}" );
        }

        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $abs_path Absolute path.
     * @return true|WP_Error
     */
    public function delete( $abs_path ) {
        $this->ensure_init();

        if ( ! $this->fs->exists( $abs_path ) ) {
            return new WP_Error( 'file_not_found', "File does not exist: {$abs_path}" );
        }

        if ( ! $this->fs->delete( $abs_path ) ) {
            return new WP_Error( 'delete_failed', "Failed to delete: {$abs_path}" );
        }

        return true;
    }

    /**
     * Check if WP_Filesystem is initialized. Throw on failure.
     */
    private function ensure_init() {
        if ( ! $this->fs ) {
            $result = $this->init();
            if ( is_wp_error( $result ) ) {
                throw new \RuntimeException( $result->get_error_message() );
            }
        }
    }

    /**
     * Get the filesystem transport method in use.
     * Useful for diagnostics in settings page.
     *
     * @return string 'direct', 'ftpext', 'ssh2', etc.
     */
    public function get_method() {
        return defined( 'FS_METHOD' ) ? FS_METHOD : get_filesystem_method();
    }
}
```

**wp-config.php guidance to provide users:**
```php
// For VPS/dedicated where web server owns files (most common):
define( 'FS_METHOD', 'direct' );

// For shared hosting with FTP-only access:
define( 'FTP_HOST', 'ftp.yoursite.com' );
define( 'FTP_USER', 'yourftpuser' );
define( 'FTP_PASS', 'yourftppassword' );
define( 'FS_METHOD', 'ftpext' );
```

**File permissions required:**
- Theme files: `755` (dirs), `644` (files), owned by web user
- Plugin files: `755` (dirs), `644` (files), owned by web user
- Backup dir (`wp-content/bosscode-backups/`): `755`, owned by web user
- `wp-content/` must be writable by web user

**Agent: Replace all raw PHP file I/O in `BossCode_Tool_Executor` with `BossCode_Filesystem` calls.**

---

### 3.2 🔴 CRITICAL: Conversation Persistence (Session Management)
**Status: 15% — chat history lives only in the browser's React state**

**The Problem:**
Refreshing the page loses all chat history. There is no server-side conversation record. Long agent runs produce no permanent audit trail. Multi-step tasks that span multiple user visits are impossible.

**Required: New database table + `BossCode_Session_Manager` class**

```sql
-- Add to activation hook via dbDelta()
CREATE TABLE IF NOT EXISTS {prefix}bosscode_sessions (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    session_uuid VARCHAR(36) NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT 'New Session',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_uuid (session_uuid),
    KEY idx_user (user_id)
) {charset_collate};

CREATE TABLE IF NOT EXISTS {prefix}bosscode_messages (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    session_uuid VARCHAR(36) NOT NULL,
    role ENUM('system','user','assistant','tool') NOT NULL,
    content LONGTEXT NOT NULL,
    tool_calls JSON DEFAULT NULL,          -- assistant tool_calls array
    tool_call_id VARCHAR(64) DEFAULT NULL, -- for tool result messages
    tool_name VARCHAR(128) DEFAULT NULL,   -- for display in UI
    iteration INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session (session_uuid),
    KEY idx_created (created_at)
) {charset_collate};
```

**`BossCode_Session_Manager` must implement:**
```php
// Core methods
create_session( $user_id, $title = '' ) : array   // Returns session record
get_session( $uuid ) : array|false
list_sessions( $user_id, $limit = 20 ) : array
delete_session( $uuid ) : bool
rename_session( $uuid, $title ) : bool

// Message methods
add_message( $session_uuid, $role, $content, $meta = [] ) : int
get_messages( $session_uuid, $limit = 100 ) : array   // Returns in LLM format
get_messages_for_display( $session_uuid ) : array     // Returns UI format with tool_name etc.
truncate_to_token_limit( $messages, $max_tokens ) : array  // See 3.7
```

**REST API additions needed:**
```
GET  /bosscode-ai/v1/sessions          → list_sessions()
POST /bosscode-ai/v1/sessions          → create_session()
GET  /bosscode-ai/v1/sessions/{uuid}   → get_session() + get_messages()
DELETE /bosscode-ai/v1/sessions/{uuid} → delete_session()
PATCH /bosscode-ai/v1/sessions/{uuid}  → rename_session()
```

**Chat endpoint changes:**
- Accept `session_uuid` param. If provided, load history from DB instead of `history[]` from request.
- Save every user message, assistant response, and tool result to `bosscode_messages`.
- Return `session_uuid` in every response so frontend can link to it.

---

### 3.3 🔴 CRITICAL: WordPress Content Tools (The Agent's Real Power)
**Status: 10% — currently only file I/O tools exist**

The agent can only read/write files. A truly agentic WordPress IDE must be able to manage the CMS itself. This is the feature gap that makes the difference between a "file editor with AI" and an actual "AI that builds and manages your site."

**Add these tools to `BossCode_Tools` and `BossCode_Tool_Executor`:**

#### Group A — Post/Page Management
```php
// Tool: create_post
// Args: title (string), content (string, HTML), post_type (string, default 'post'),
//       status (string: 'draft'|'publish'|'private'), excerpt (string?),
//       meta (object?)
// Implementation: wp_insert_post()

// Tool: update_post
// Args: post_id (int), title?, content?, status?, excerpt?, meta?
// Implementation: wp_update_post()

// Tool: delete_post
// Args: post_id (int), force_delete (bool, default false)
// Implementation: wp_delete_post() or wp_trash_post()

// Tool: get_post
// Args: post_id (int)
// Implementation: get_post() + get_post_meta()

// Tool: list_posts
// Args: post_type (string), status (string), limit (int), search (string?)
// Implementation: WP_Query
```

#### Group B — Menu Management
```php
// Tool: get_menus
// Returns all nav menus and their items
// Implementation: wp_get_nav_menus() + wp_get_nav_menu_items()

// Tool: add_menu_item
// Args: menu_id (int), object_type (string: 'post'|'page'|'custom'),
//       object_id (int?), title (string?), url (string?), parent_id (int?)
// Implementation: wp_update_nav_menu_item()

// Tool: reorder_menu_items
// Args: menu_id (int), items (array of {item_id, menu_order, parent_id})
// Implementation: wp_update_nav_menu_item() for each
```

#### Group C — Widget & Sidebar Management
```php
// Tool: get_sidebars
// Returns all registered sidebars and current widget assignments
// Implementation: $GLOBALS['wp_registered_sidebars'], get_option('sidebars_widgets')

// Tool: add_widget_to_sidebar
// Args: sidebar_id (string), widget_id_base (string), widget_settings (object)
// Implementation: update_option('widget_{base}', ...) + update_option('sidebars_widgets', ...)
```

#### Group D — Plugin/Theme Management
```php
// Tool: list_plugins
// Returns installed plugins with active status, version, description
// Implementation: get_plugins(), is_plugin_active()

// Tool: activate_plugin
// Args: plugin_slug (string, e.g. 'woocommerce/woocommerce.php')
// Implementation: activate_plugin() — requires 'activate_plugins' cap check

// Tool: deactivate_plugin
// Args: plugin_slug (string)
// Implementation: deactivate_plugins()

// Tool: list_themes
// Returns installed themes with active status
// Implementation: wp_get_themes(), get_stylesheet()

// Tool: switch_theme
// Args: theme_stylesheet (string)
// Implementation: switch_theme() — DANGEROUS, require confirmation
```

#### Group E — Site Settings & Options
```php
// Tool: get_site_options
// Args: option_names (array of strings)
// Implementation: get_option() for each — whitelist allowed option names

// Tool: update_site_option
// Args: option_name (string), value (mixed)
// STRICT WHITELIST — only allow non-dangerous options:
// ['blogname','blogdescription','date_format','time_format',
//  'posts_per_page','default_comment_status','default_ping_status']
// Implementation: update_option()

// Tool: get_customizer_settings
// Returns active theme's customize settings
// Implementation: get_theme_mods()

// Tool: update_customizer_setting
// Args: setting_key (string), value (string)
// Implementation: set_theme_mod()
```

#### Group F — Media Library
```php
// Tool: list_media
// Args: mime_type? (string), limit (int), search (string?)
// Implementation: WP_Query with post_type='attachment'

// Tool: get_media_item
// Args: attachment_id (int)
// Returns: URL, alt, caption, metadata
// Implementation: get_post() + wp_get_attachment_metadata()

// Tool: upload_from_url
// Args: url (string), title? (string), alt? (string)
// Implementation: media_sideload_image() — requires 'upload_files' cap
```

#### Group G — WP-CLI Bridge (Advanced)
```php
// Tool: run_wp_cli
// Args: command (string, e.g. 'cache flush')
// STRICT WHITELIST of safe commands:
// ['cache flush', 'rewrite flush', 'transient delete --all',
//  'search-replace --dry-run ...', 'post list ...', 'option get ...']
// Implementation: exec() or proc_open() — gated behind capability check
//                 Only available if exec() is not disabled
// Returns: stdout, stderr, exit_code
```

---

### 3.4 🔴 CRITICAL: Human-in-the-Loop Confirmation System
**Status: 20% — `send_confirm_required` SSE event exists but is never triggered**

The stream class has `send_confirm_required()` but `handle_chat_stream()` never calls it. Destructive tools execute without any confirmation. This is a data-safety requirement for production.

**Required architecture:**

```
AGENT FLOW WITH CONFIRMATION:

1. Agent loop reaches a tool call.
2. If tool is DESTRUCTIVE (write_file, delete_file, replace_in_file,
   switch_theme, activate_plugin, update_post, etc.):
   a. Emit SSE event: confirm_required { tool_call_id, name, args, preview }
   b. PAUSE the loop — store pending state in transient.
   c. Wait for resume signal from frontend.
3. Frontend shows confirmation modal with:
   - Tool name and arguments
   - File diff preview (for file writes — see 3.5)
   - [Approve] [Deny] [Approve All Remaining] buttons
4. Frontend POSTs to: POST /bosscode-ai/v1/confirm
   Body: { session_uuid, tool_call_id, approved: true|false }
5. Backend resumes loop from paused state.
```

**Required: `BossCode_Confirmation_Manager`**
```php
class BossCode_Confirmation_Manager {
    // Store pending confirmation using transient
    public function request( $session_uuid, $tool_call_id, $name, $args, $preview ) : string;
    // Returns confirmation token

    public function resolve( $token, $approved ) : bool;
    // frontend calls this

    public function wait_for_resolution( $token, $timeout_seconds = 120 ) : bool|null;
    // returns true (approved), false (denied), null (timeout)
    // Uses usleep() polling loop — checks every 500ms
}
```

**REST API:**
```
POST /bosscode-ai/v1/confirm
Body: { token: string, approved: bool }
Permission: manage_options + nonce
```

---

### 3.5 🟠 HIGH: Diff/Patch System
**Status: 0% — writes replace entire file content; no preview**

Before any file write, the agent should be able to show what will change. This is table-stakes for an IDE.

**Required: `BossCode_Diff` utility class**
```php
class BossCode_Diff {
    /**
     * Generate unified diff between two strings.
     *
     * @param string $old     Original file content.
     * @param string $new     New file content.
     * @param string $context Lines of context around each change.
     * @return array Array of hunks: [{type:'add'|'remove'|'context', line, content}]
     */
    public static function compute( $old, $new, $context = 3 ) : array;

    /**
     * Apply a diff patch to a string.
     *
     * @param string $original Original content.
     * @param array  $hunks    Diff hunks from compute().
     * @return string|WP_Error Patched content or error.
     */
    public static function apply( $original, $hunks ) : string|WP_Error;

    /**
     * Format diff as a string for sending to AI as tool result.
     *
     * @param array $hunks Diff hunks.
     * @return string Human-readable diff.
     */
    public static function format_for_display( $hunks ) : string;
}
```

**Use PHP's `FineDiff` library or implement a simple line-based LCS diff.**

**New tool: `preview_file_change`**
```php
// Before write_file, AI can call preview_file_change first.
// Args: path (string), new_content (string)
// Returns: unified diff showing what would change
// Does NOT write anything — only computes diff.
// Agent should call this before write_file when possible.
```

**Frontend:** Render diff in Monaco's `createDiffEditor()` inside the confirmation modal.

---

### 3.6 🟠 HIGH: Context Window / Token Management
**Status: 20% — no token counting or truncation**

Long conversations will eventually exceed the model's context window. The agent loop currently appends messages indefinitely.

**Required: `BossCode_Context_Manager`**

```php
class BossCode_Context_Manager {

    // Approximate tokens (4 chars = 1 token)
    const CHARS_PER_TOKEN = 4;

    /**
     * Estimate token count for a string.
     */
    public static function estimate_tokens( $text ) : int {
        return (int) ceil( strlen( $text ) / self::CHARS_PER_TOKEN );
    }

    /**
     * Estimate total tokens in a messages array.
     */
    public static function count_messages_tokens( $messages ) : int;

    /**
     * Truncate messages array to fit within token limit.
     *
     * Strategy:
     * 1. Always keep system message (index 0).
     * 2. Always keep last N user messages (min 2).
     * 3. Drop oldest assistant+tool pairs from the middle.
     * 4. Insert a "context truncated" notice in the gap.
     *
     * @param array $messages  Full messages array.
     * @param int   $max_tokens Maximum tokens for history.
     * @return array Truncated messages array.
     */
    public static function truncate( $messages, $max_tokens = 60000 ) : array;

    /**
     * Get the token limit for a given model name.
     * Falls back to 8000 for unknown models.
     */
    public static function get_model_limit( $model_name ) : int {
        $limits = [
            'gpt-4o'          => 128000,
            'gpt-4-turbo'     => 128000,
            'gpt-3.5-turbo'   => 16385,
            'claude-3-5-sonnet' => 200000,
            'claude-3-haiku'  => 200000,
            'llama3'          => 8192,
            'mistral'         => 32768,
        ];
        foreach ( $limits as $name => $limit ) {
            if ( strpos( strtolower( $model_name ), $name ) !== false ) return $limit;
        }
        return 8000; // safe default for unknown local models
    }
}
```

**Agent integration point:** In `handle_chat()` and `handle_chat_stream()`, before each LLM call, run:
```php
$reserve_tokens  = 4000; // Reserve for response
$max_history     = BossCode_Context_Manager::get_model_limit( $model ) - $reserve_tokens;
$messages        = BossCode_Context_Manager::truncate( $messages, $max_history );
```

---

### 3.7 🟠 HIGH: Agent Planning & Reflection Loop
**Status: 15% — flat tool-call loop; no planning step**

Currently the agent jumps straight into tool calls. Production AI agents use a Plan → Execute → Reflect pattern to reduce wasted iterations and improve task quality.

**Required system prompt upgrade (to be injected in `build_system_prompt()`):**
```
You are BossCode, an agentic AI IDE for WordPress. You manage websites autonomously.

OPERATING PROTOCOL:
1. UNDERSTAND: Before using any tools, briefly state what you understand the user wants.
2. PLAN: List the exact steps you will take (numbered). Check get_wordpress_info first.
3. EXECUTE: Carry out the plan using tools. One tool call per step.
4. VERIFY: After each write operation, read the file back to confirm correctness.
5. REPORT: Summarize what was done, what changed, and any follow-up recommendations.

RULES:
- Always call get_wordpress_info before accessing any file path.
- Never guess a file path. Use list_directory to explore structure.
- Always call preview_file_change before write_file for files > 50 lines.
- Never write broken PHP — validate syntax mentally before writing.
- If a task requires more than 10 tool calls, stop and ask the user to confirm continuation.
- If you encounter an error twice on the same operation, stop and explain the problem.
```

**Required: `BossCode_Agent_Planner` class**
```php
class BossCode_Agent_Planner {
    /**
     * Extract a structured plan from the AI's first response.
     * Used to display planned steps in the UI before execution.
     *
     * @param string $response Raw AI text response.
     * @return array|null Parsed plan steps, or null if no plan found.
     */
    public function extract_plan( $response ) : ?array;

    /**
     * Inject a plan as a structured SSE event so the frontend
     * can render a "Step Tracker" UI panel.
     *
     * @param BossCode_Stream $stream
     * @param array           $plan   Array of step strings.
     */
    public function stream_plan( $stream, $plan ) : void;
}
```

**Frontend: New "Plan" panel** — shows numbered steps, highlights the current step with a progress indicator during execution.

---

### 3.8 🟠 HIGH: Rate Limiting & Audit Logging
**Status: 0%**

**Rate Limiting — `BossCode_Rate_Limiter`:**
```php
class BossCode_Rate_Limiter {
    // Limits per user:
    // - Max 60 chat requests per hour
    // - Max 10 file write operations per 5 minutes
    // - Max 3 concurrent agent loops per user
    //
    // Implementation: WordPress transients keyed by user_id
    // Key pattern: 'bosscode_rl_{user_id}_{action}_{window}'
    // Window: floor(time() / 300) for 5-min windows, floor(time() / 3600) for hourly

    public function check( $user_id, $action ) : true|WP_Error;
    public function increment( $user_id, $action ) : void;
}
```

**Audit Log — new DB table:**
```sql
CREATE TABLE {prefix}bosscode_audit (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    session_uuid VARCHAR(36),
    action VARCHAR(100) NOT NULL,   -- 'write_file', 'delete_file', 'create_post', etc.
    target TEXT,                    -- file path or post ID
    meta JSON,                      -- args snapshot
    result ENUM('success','failure','denied'),
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_created (created_at)
);
```

**Every tool execution must log to audit table.** This is also the data source for the "Undo" feature.

---

### 3.9 🟠 HIGH: Rollback / Undo System
**Status: 5% — backups are created but there is no way to restore them**

**Required: `BossCode_Rollback_Manager`:**
```php
class BossCode_Rollback_Manager {

    /**
     * List all backups for a given file path.
     *
     * @param string $relative_path Relative path from ABSPATH.
     * @return array Array of {backup_path, created_at, size}
     */
    public function list_backups( $relative_path ) : array;

    /**
     * Restore a file to a backup version.
     *
     * @param string $backup_path   Full path to the backup file.
     * @param string $original_path Full path to the live file to restore.
     * @return true|WP_Error
     */
    public function restore( $backup_path, $original_path ) : true|WP_Error;

    /**
     * Purge backups older than N days.
     *
     * @param int $days Default 30.
     * @return int Number of files deleted.
     */
    public function purge_old_backups( $days = 30 ) : int;

    /**
     * Get total size of backup directory.
     *
     * @return int Bytes.
     */
    public function get_backup_size() : int;
}
```

**REST API:**
```
GET    /bosscode-ai/v1/backups?path={relative_path}  → list_backups()
POST   /bosscode-ai/v1/backups/restore               → restore()
DELETE /bosscode-ai/v1/backups/purge                 → purge_old_backups()
```

**Frontend:** Add "History" tab in file panel — shows timestamped backup list for open file, with one-click restore and diff-preview before restore.

---

### 3.10 🟡 MEDIUM: PHP Syntax Validation Before Write
**Status: 0%**

Writing broken PHP to WordPress is catastrophic — it triggers a fatal error and the site goes down (white screen of death).

**Required: Validation gate in `tool_write_file` and `tool_create_file`:**
```php
private function validate_php_syntax( $content, $path ) : true|WP_Error {
    // Only validate .php files
    if ( pathinfo( $path, PATHINFO_EXTENSION ) !== 'php' ) {
        return true;
    }

    // Write to a temp file and run php -l
    $tmp = wp_tempnam( 'bosscode_validate' );
    file_put_contents( $tmp, $content );

    $output   = array();
    $exit_code = 0;
    @exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1', $output, $exit_code );
    unlink( $tmp );

    // If exec() is disabled, skip validation with a warning
    if ( ! function_exists( 'exec' ) || ! @exec( 'echo 1' ) ) {
        return true; // Cannot validate — allow but warn in tool result
    }

    if ( $exit_code !== 0 ) {
        return new WP_Error(
            'php_syntax_error',
            'PHP syntax validation failed: ' . implode( "\n", $output )
        );
    }

    return true;
}
```

**If exec() is unavailable (common on shared hosting):** Use `token_get_all()` for a lighter syntactic check, and include a warning in the tool result that deep syntax validation was skipped.

---

### 3.11 🟡 MEDIUM: Proper Permissions/Capability Model
**Status: 60% — currently everything gated behind `manage_options` only**

**Required: Granular capability model:**
```php
// Add to bootstrap or a dedicated BossCode_Capabilities class:
define( 'BOSSCODE_CAP_USE',          'bosscode_use' );           // Chat with agent
define( 'BOSSCODE_CAP_READ_FILES',   'bosscode_read_files' );    // Read files
define( 'BOSSCODE_CAP_WRITE_FILES',  'bosscode_write_files' );   // Write files
define( 'BOSSCODE_CAP_MANAGE_POSTS', 'bosscode_manage_posts' );  // CRUD posts
define( 'BOSSCODE_CAP_MANAGE_SITE',  'bosscode_manage_site' );   // Switch themes, plugins
define( 'BOSSCODE_CAP_ADMIN',        'bosscode_admin' );         // Settings, paths, audit

// Assign defaults on activation:
$admin_role = get_role( 'administrator' );
$admin_role->add_cap( BOSSCODE_CAP_USE );
$admin_role->add_cap( BOSSCODE_CAP_READ_FILES );
$admin_role->add_cap( BOSSCODE_CAP_WRITE_FILES );
$admin_role->add_cap( BOSSCODE_CAP_MANAGE_POSTS );
$admin_role->add_cap( BOSSCODE_CAP_MANAGE_SITE );
$admin_role->add_cap( BOSSCODE_CAP_ADMIN );

// Editors get read + posts:
$editor_role = get_role( 'editor' );
$editor_role->add_cap( BOSSCODE_CAP_USE );
$editor_role->add_cap( BOSSCODE_CAP_READ_FILES );
$editor_role->add_cap( BOSSCODE_CAP_MANAGE_POSTS );
```

**Each REST route's `permission_callback` must check the specific capability**, not just `manage_options`.

---

### 3.12 🟡 MEDIUM: Streaming Token Output from LLM
**Status: partial — SSE infrastructure exists but tokens come from whole responses**

Currently the AI client does a full HTTP round-trip and then sends the complete response as a single SSE event. True streaming (token-by-token) requires the AI client to make a `stream: true` request and forward each token chunk via SSE.

**Required changes to `BossCode_AI_Client`:**
```php
/**
 * Stream a chat completion, calling $chunk_callback for each token chunk.
 *
 * @param array    $messages
 * @param array    $tools
 * @param callable $chunk_callback  Called with ($token_text, $is_done, $tool_calls)
 * @return void
 */
public function stream_chat_completion( $messages, $tools, $chunk_callback ) : void {
    // Use wp_remote_request() with stream => true, or curl with CURLOPT_WRITEFUNCTION
    // Parse SSE chunks from provider, extract delta content
    // Call $chunk_callback( $delta_text ) for each token
    // On finish_reason='tool_calls', call $chunk_callback( '', true, $tool_calls )
}
```

**Note:** WordPress's `wp_remote_request` does not natively stream. Use `curl` directly with `CURLOPT_WRITEFUNCTION` callback.

---

### 3.13 🟡 MEDIUM: Frontend Architecture — Build System
**Status: 0% — app.js is raw vanilla JS, hard to maintain at scale**

The current `app.js` (570 lines) is readable but will become unmanageable as features grow. At VS Code scale, you need:

**Required:**
- Introduce a build step: **Vite** (lightweight, fast)
- Split into component files: `FileTree.jsx`, `ChatPanel.jsx`, `EditorPanel.jsx`, `SettingsPanel.jsx`, `PlanTracker.jsx`, `ConfirmModal.jsx`, `SessionList.jsx`, `DiffViewer.jsx`
- Proper `package.json` with `vite build` outputting to `frontend/dist/`
- WordPress enqueues `frontend/dist/app.js` (the bundled output)
- Source maps in development mode

**Interim approach (no build system):** At minimum, split `app.js` into logical sections using immediately-invoked functions and a lightweight namespace (`window.BossCode = {}`).

---

### 3.14 🟡 MEDIUM: Error Recovery & Retry Logic
**Status: 35% — circuit breaker exists for paths, nothing else**

**Required: Comprehensive `BossCode_Error_Handler`**

```php
class BossCode_Error_Handler {

    // Transient errors that should be retried:
    const RETRYABLE_ERRORS = [
        'http_request_failed',     // Network blip
        'rest_no_route',           // Transient REST issue
        'timeout',                 // AI provider timeout
    ];

    // Errors that should abort immediately:
    const FATAL_ERRORS = [
        'invalid_api_key',
        'insufficient_quota',
        'model_not_found',
    ];

    /**
     * Retry a callable up to $max_attempts times with exponential backoff.
     *
     * @param callable $fn             Function to retry.
     * @param int      $max_attempts   Max retries (default 3).
     * @param int      $base_delay_ms  Base delay in milliseconds (default 1000).
     * @return mixed|WP_Error
     */
    public function with_retry( $fn, $max_attempts = 3, $base_delay_ms = 1000 );

    /**
     * Classify a WP_Error or exception and determine if it is retryable.
     */
    public function is_retryable( $error ) : bool;

    /**
     * Format a user-friendly error message for display in chat.
     */
    public function format_for_display( $error ) : string;
}
```

**Apply retry logic specifically to:**
- AI client HTTP requests (provider API down/throttled)
- Filesystem write operations (transient lock conflicts)
- Loopback requests in `BossCode_Job_Manager::spawn_async()`

---

### 3.15 🟡 MEDIUM: Settings Page — Filesystem Diagnostics Panel

**Status: 0%**

Users must be able to see and fix the filesystem configuration without guessing. Add a "Diagnostics" tab to the settings page that runs and displays:

```
✅ WP_Filesystem method: direct
✅ Theme directory writable: /wp-content/themes/mytheme/
✅ Plugins directory writable: /wp-content/plugins/
✅ Backup directory exists: /wp-content/bosscode-backups/
✅ PHP exec() available (PHP syntax validation: enabled)
⚠️  wp-config.php is group-writable (recommend chmod 640)
❌ /wp-content/plugins/ not writable by web user
   → Fix: chown www-data:www-data /wp-content/plugins && chmod 755 /wp-content/plugins
```

**REST endpoint:**
```
GET /bosscode-ai/v1/diagnostics
Returns: { filesystem_method, writable_paths, blocked_paths, php_capabilities, recommendations[] }
```

---

### 3.16 🟡 MEDIUM: Multi-Session / Concurrent Agent Guard
**Status: 0%**

If the user opens two tabs and runs two agent sessions simultaneously, they can create conflicting file writes. Guard against this:

```php
// In handle_chat_stream():
$lock_key = 'bosscode_agent_lock_' . get_current_user_id();
$existing = get_transient( $lock_key );
if ( $existing && $existing !== $session_uuid ) {
    $this->stream->send_error( 'Another agent session is already running. Please wait.' );
    exit;
}
set_transient( $lock_key, $session_uuid, 300 ); // 5-minute lock
// Clear lock in send_done() and send_error()
```

---

## 4. FILE ACCESS: COMPLETE DESIGN

This section is the definitive specification for how file access must work in the production plugin. This directly addresses the repeated permission errors from previous development.

### 4.1 The Three-Tier Access Model

```
TIER 1: Read-only exploration
  - list_directory, search_files, get_wordpress_info
  - Uses: WP_Filesystem::dirlist(), WP_Filesystem::get_contents()
  - Permission required: BOSSCODE_CAP_READ_FILES
  - Constraint: path must be in allowed_paths whitelist

TIER 2: File write operations
  - read_file, write_file, create_file, delete_file, replace_in_file
  - Uses: WP_Filesystem::put_contents(), WP_Filesystem::delete()
  - Permission required: BOSSCODE_CAP_WRITE_FILES
  - Constraint: path in whitelist + PHP syntax check for .php files
  - Requires: Backup created before every write
  - Triggers: Human confirmation (see 3.4)
  - Logs: Audit log entry

TIER 3: WordPress content operations
  - create_post, delete_post, switch_theme, activate_plugin, etc.
  - Uses: WordPress functions (wp_insert_post, activate_plugin, etc.)
  - Permission required: BOSSCODE_CAP_MANAGE_POSTS or BOSSCODE_CAP_MANAGE_SITE
  - Triggers: Human confirmation for destructive ops
  - Logs: Audit log entry
```

### 4.2 Path Resolution Flow (Definitive)

```
INPUT: path string from AI tool call (e.g. "wp-content/themes/mytheme/style.css")

STEP 1: Null byte check → reject if contains \0
STEP 2: Backslash normalize → str_replace('\\', '/', $path)
STEP 3: Double-dot check → reject if contains '..'
STEP 4: Absolute path detection
  - If starts with '/' or matches /^[A-Za-z]:\// → treat as absolute
  - Else → prepend ABSPATH

STEP 5: realpath() resolution
  - If file exists → realpath() → use result
  - If file does not exist (create_file) → realpath(dirname()) + '/' + basename
  - If parent dir does not exist → reject with PARENT_NOT_FOUND error

STEP 6: Whitelist check
  - Normalize resolved path with wp_normalize_path()
  - Check if it starts with any allowed path (also normalized + trailing slash)
  - Check blocked basenames: wp-config.php, .htaccess, wp-settings.php
  - Check not inside BOSSCODE_PLUGIN_DIR/backend/

STEP 7: Return resolved absolute path for WP_Filesystem operations
```

### 4.3 Required wp-config.php Constants (Document for Users)

```php
// REQUIRED for most VPS/Cloud hosting (web server owns the files):
define( 'FS_METHOD', 'direct' );

// REQUIRED for shared hosting where files are owned by FTP user:
define( 'FTP_BASE',     '/home/youruser/public_html/' );
define( 'FTP_CONTENT_DIR', '/home/youruser/public_html/wp-content/' );
define( 'FTP_PLUGIN_DIR',  '/home/youruser/public_html/wp-content/plugins/' );
define( 'FTP_HOST',    'ftp.yourhost.com' );
define( 'FTP_USER',    'yourftpuser' );
define( 'FTP_PASS',    'yourftppassword' );
define( 'FS_METHOD',   'ftpext' );
```

### 4.4 File Ownership Matrix (What to tell users)

| Hosting Type | Web User | Fix Command |
|---|---|---|
| Ubuntu VPS (Nginx) | `www-data` | `chown -R www-data:www-data wp-content/themes/ wp-content/plugins/` |
| Ubuntu VPS (Apache) | `www-data` | Same as above |
| CentOS/RHEL VPS | `apache` | `chown -R apache:apache wp-content/` |
| cPanel shared hosting | `cpanelusername` | Use cPanel File Manager → Right-click → Change Permissions |
| WP Engine | `www-data` | Files managed via Git push — use `FS_METHOD=direct`, deploy writes via sftp |
| Kinsta | `www-data` | Same as WP Engine recommendation |
| Local by Flywheel | Current OS user | No config needed — direct works |
| XAMPP/WAMP (Windows) | IIS_IUSRS | Set folder permissions via Properties → Security tab |

---

## 5. IMPLEMENTATION PRIORITY ORDER FOR ANTIGRAVITY AGENT

Execute the following in strict order. Each item is a discrete, testable unit of work.

### Phase 1 — Foundation Fixes (Do First, Unblocks Everything)
```
[P1-01] Create backend/class-bosscode-filesystem.php (BossCode_Filesystem)
[P1-02] Inject BossCode_Filesystem into BossCode_Tool_Executor via constructor
[P1-03] Replace all file_get_contents/file_put_contents/unlink in Tool_Executor with BossCode_Filesystem methods
[P1-04] Add init() call to BossCode_Filesystem in BossCode_Bootstrap::init_services()
[P1-05] Add diagnostics REST endpoint (GET /diagnostics) returning filesystem status
[P1-06] Add "Diagnostics" tab to settings UI with filesystem status and fix instructions
```

### Phase 2 — Session & Persistence
```
[P2-01] Add bosscode_sessions and bosscode_messages tables to activation hook (dbDelta)
[P2-02] Create backend/class-bosscode-session-manager.php
[P2-03] Add session REST endpoints to BossCode_API_Routes
[P2-04] Modify handle_chat() and handle_chat_stream() to accept session_uuid, load/save history from DB
[P2-05] Add SessionList UI panel to frontend (list previous sessions, click to resume)
```

### Phase 3 — Safety Systems
```
[P3-01] Create backend/class-bosscode-diff.php (line-based diff computation)
[P3-02] Add tool_preview_file_change() to BossCode_Tool_Executor
[P3-03] Register preview_file_change tool in BossCode_Tools
[P3-04] Create backend/class-bosscode-confirmation-manager.php
[P3-05] Wire send_confirm_required() into the streaming loop for destructive tools
[P3-06] Add POST /confirm REST endpoint
[P3-07] Add confirmation modal to frontend (with Monaco diff viewer for file writes)
[P3-08] Add PHP syntax validation (php -l via exec() with fallback to token_get_all())
```

### Phase 4 — Context & Planning
```
[P4-01] Create backend/class-bosscode-context-manager.php (token counting + truncation)
[P4-02] Wire truncation into handle_chat() before every LLM call
[P4-03] Update build_system_prompt() with the Plan→Execute→Verify→Report instructions
[P4-04] Create backend/class-bosscode-agent-planner.php
[P4-05] Stream plan steps as a structured SSE event type='plan'
[P4-06] Add PlanTracker component to frontend
```

### Phase 5 — WordPress Content Tools
```
[P5-01] Add create_post, update_post, delete_post, get_post, list_posts tools
[P5-02] Add get_menus, add_menu_item, reorder_menu_items tools
[P5-03] Add list_plugins, activate_plugin, deactivate_plugin, list_themes tools
[P5-04] Add get_site_options, update_site_option, get_customizer_settings tools
[P5-05] Add list_media, get_media_item tools
[P5-06] Add get_sidebars, add_widget_to_sidebar tools
```

### Phase 6 — Audit, Rollback, Rate Limiting
```
[P6-01] Add bosscode_audit table to activation hook
[P6-02] Create backend/class-bosscode-rate-limiter.php
[P6-03] Add rate limiting checks to check_permissions() or per-route
[P6-04] Log every tool execution to audit table in tool executor
[P6-05] Create backend/class-bosscode-rollback-manager.php
[P6-06] Add backup/restore REST endpoints
[P6-07] Add "File History" panel to frontend with restore button
```

### Phase 7 — Production Polish
```
[P7-01] Granular capability model (BOSSCODE_CAP_* constants, role assignment on activate)
[P7-02] Multi-session concurrent lock (transient-based agent lock per user)
[P7-03] True token streaming via curl CURLOPT_WRITEFUNCTION in AI Client
[P7-04] Backup purge scheduled via wp_cron (daily, 30-day retention)
[P7-05] Error recovery with retry + exponential backoff in AI Client
[P7-06] Migrate frontend to Vite build system with component split
```

---

## 6. COMPLETE FILE MANIFEST — FINAL STATE

```
bosscode-ai-agent/
├── bosscode-ai-agent.php                    ✅ exists
├── uninstall.php                            ✅ exists
├── README.md                                ✅ exists
├── package.json                             ➕ new (Vite build system)
├── vite.config.js                           ➕ new
│
├── backend/
│   ├── class-bosscode-bootstrap.php         ✅ exists (needs updates)
│   ├── class-bosscode-security.php          ✅ exists
│   ├── class-bosscode-filesystem.php        ➕ NEW — WP_Filesystem abstraction
│   ├── class-bosscode-settings.php          ✅ exists
│   ├── class-bosscode-ai-client.php         ✅ exists (needs streaming upgrade)
│   ├── class-bosscode-tools.php             ✅ exists (needs 20+ new tools)
│   ├── class-bosscode-tool-executor.php     ✅ exists (needs refactor for FS + new tools)
│   ├── class-bosscode-api-routes.php        ✅ exists (needs new routes)
│   ├── class-bosscode-stream.php            ✅ exists
│   ├── class-bosscode-job-manager.php       ✅ exists
│   ├── class-bosscode-rag-engine.php        ✅ exists
│   ├── class-bosscode-search-index.php      ✅ exists
│   ├── class-bosscode-vector-store.php      ✅ exists
│   ├── class-bosscode-session-manager.php   ➕ NEW — DB conversation persistence
│   ├── class-bosscode-context-manager.php   ➕ NEW — token counting + truncation
│   ├── class-bosscode-agent-planner.php     ➕ NEW — Plan/Reflect loop
│   ├── class-bosscode-diff.php              ➕ NEW — line diff computation
│   ├── class-bosscode-confirmation-manager.php ➕ NEW — human-in-the-loop
│   ├── class-bosscode-rollback-manager.php  ➕ NEW — backup restore
│   ├── class-bosscode-rate-limiter.php      ➕ NEW — per-user rate limits
│   ├── class-bosscode-error-handler.php     ➕ NEW — retry + classification
│   └── class-bosscode-capabilities.php      ➕ NEW — granular cap model
│
├── frontend/
│   ├── src/
│   │   ├── main.jsx                         ➕ new Vite entry
│   │   ├── components/
│   │   │   ├── App.jsx                      (refactored from app.js)
│   │   │   ├── FileTree.jsx                 (extracted)
│   │   │   ├── ChatPanel.jsx                (extracted)
│   │   │   ├── EditorPanel.jsx              (extracted)
│   │   │   ├── SessionList.jsx              ➕ NEW
│   │   │   ├── PlanTracker.jsx              ➕ NEW
│   │   │   ├── ConfirmModal.jsx             ➕ NEW
│   │   │   ├── DiffViewer.jsx               ➕ NEW
│   │   │   ├── FileHistory.jsx              ➕ NEW (backup restore)
│   │   │   ├── DiagnosticsPanel.jsx         ➕ NEW
│   │   │   └── SettingsPanel.jsx            (extracted)
│   │   └── utils/
│   │       ├── api.js                       ➕ centralized fetch wrapper
│   │       └── tokens.js                    ➕ client-side token estimator
│   ├── dist/                                (Vite build output — gitignored)
│   │   ├── app.js
│   │   └── app.css
│   ├── app.js                               ✅ exists (interim, replace with dist)
│   ├── floating-widget.js                   ✅ exists
│   └── styles/
│       ├── bosscode.css                     ✅ exists
│       └── floating-widget.css              ✅ exists
```

---

## 7. CRITICAL FACTS FOR THE IMPLEMENTING AGENT

1. **Never use `file_get_contents()` or `file_put_contents()` directly.** Always route through `BossCode_Filesystem`. This single rule fixes the majority of historical permission errors.

2. **Always call `get_wordpress_info` as the first tool** in any agent run that involves filesystem paths. The agent must know the allowed paths before trying to access anything.

3. **`wp_normalize_path()` is mandatory** before any path comparison. Mixing Windows backslashes and Unix slashes causes whitelist bypass bugs.

4. **`realpath()` returns `false` for non-existent files.** Always handle this in `sanitize_path()` for the `create_file` case by resolving the parent directory instead.

5. **The backup system must run before `BossCode_Filesystem::write()`**, not inside `tool_write_file()`. The filesystem layer is the right place for atomicity guarantees.

6. **Transients can be lost** on servers that disable object caching or use ephemeral storage. For anything critical (session history, audit log), use a proper DB table, not transients.

7. **The Gemini Auto code** lives in `call_gemini_auto()` inside `BossCode_AI_Client`. Do not delete it, but do not add new features to it. Keep it isolated behind the `gemini_auto` provider check.

8. **REST nonce (`X-WP-Nonce`)** expires in 24 hours. The frontend must handle `403` responses from the REST API by refreshing the nonce via a separate `wp_create_nonce('wp_rest')` endpoint, or by prompting the user to reload the page.

9. **`set_time_limit(300)` is not available in safe mode** and is silently ignored on many managed hosts. Use `ignore_user_abort(true)` + background jobs for any operation expected to take > 30 seconds.

10. **The `bosscode_ai_allowed_paths` option is an array of absolute paths.** Never compare paths as strings — always normalize both sides with `wp_normalize_path()` and add trailing slash before `strpos()` comparison to prevent prefix collision (e.g., `/plugins/myboss` matching `/plugins/mybosscode`).

---

*End of BossCode WP-IDE Production Breakdown — v1.0*
*Generated for Antigravity Agent Implementation*
