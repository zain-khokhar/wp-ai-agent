# BossCode AI Agent

> An agentic AI coding assistant embedded in WordPress — a full IDE with file explorer, Monaco editor, and tool-calling AI right in your dashboard.

## Features

- **🤖 Agentic AI Loop** — Multi-step tool-calling with automatic iteration (read → plan → execute → verify)
- **📁 File Explorer** — Browse your project structure with lazy-loaded tree navigation
- **✏️ Monaco Editor** — VS Code's editor engine with syntax highlighting, minimap, and dark theme
- **💬 Streaming Chat** — Real-time SSE streaming with iteration counter and tool status
- **🔍 RAG Search** — Semantic code search via vector embeddings for context-aware AI
- **🌐 Floating Widget** — Quick-access chat sidebar on every page of your site
- **⚡ Background Jobs** — Async task execution for RAG indexing and long operations
- **🔒 Security** — Encrypted API keys, path sandboxing, admin-only access, input sanitization

## Supported Providers

| Provider | Status | Notes |
|----------|--------|-------|
| GroqCloud | ✅ | Optimized params (temp=0.4, max_completion_tokens=8192) |
| OpenAI | ✅ | GPT-4, GPT-3.5 |
| Anthropic | ✅ | Claude 3.x series |
| Ollama | ✅ | Local models via OpenAI-compatible API |
| LM Studio | ✅ | Local models via OpenAI-compatible API |
| Any OpenAI-compatible | ✅ | Custom endpoint support |

## Installation

1. Download or clone this plugin into `/wp-content/plugins/bosscode-ai-agent/`
2. Activate the plugin from the WordPress admin dashboard
3. Navigate to **BossCode AI** in the admin menu
4. Open **Settings** (⚙️ button) and configure:
   - **Provider**: Select your AI provider
   - **API Key**: Enter your API key
   - **Model**: Enter the model name (e.g., `openai/gpt-oss-120b`)

## Architecture

```
bosscode-ai-agent/
├── bosscode-ai-agent.php          # Plugin entry point
├── uninstall.php                  # Cleanup on uninstall
├── README.md                      # This file
├── backend/
│   ├── class-bosscode-bootstrap.php      # Singleton orchestrator
│   ├── class-bosscode-settings.php       # Settings CRUD with encryption
│   ├── class-bosscode-security.php       # Encryption, path validation, backups
│   ├── class-bosscode-ai-client.php      # Multi-provider LLM client
│   ├── class-bosscode-tools.php          # Tool schema definitions
│   ├── class-bosscode-tool-executor.php  # Sandboxed tool execution
│   ├── class-bosscode-api-routes.php     # REST API endpoints
│   ├── class-bosscode-stream.php         # SSE streaming handler
│   ├── class-bosscode-job-manager.php    # Background job system
│   ├── class-bosscode-vector-store.php   # Embedding DB layer
│   └── class-bosscode-rag-engine.php     # Chunking + semantic search
└── frontend/
    ├── app.js                            # 3-panel IDE (React + Monaco)
    ├── floating-widget.js                # Global chat widget
    └── styles/
        ├── bosscode.css                  # IDE layout styles
        └── floating-widget.css           # Widget styles
```

## REST API

All endpoints require `manage_options` capability (admin only).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/bosscode-ai/v1/settings` | Get all settings (masked) |
| POST | `/bosscode-ai/v1/settings` | Update settings |
| POST | `/bosscode-ai/v1/chat` | Non-streaming chat |
| POST | `/bosscode-ai/v1/chat/stream` | Streaming chat (SSE) |
| GET | `/bosscode-ai/v1/files?path=` | List directory contents |
| GET | `/bosscode-ai/v1/file/read?path=` | Read file content |
| POST | `/bosscode-ai/v1/index/start` | Start RAG indexing (async) |
| GET | `/bosscode-ai/v1/index/status` | Get indexing status |
| GET | `/bosscode-ai/v1/job/status?id=` | Get background job status |

## Security

- **API keys** are encrypted at rest using `wp_salt()` + AES-equivalent via `openssl_encrypt`
- **Path sandboxing** restricts file access to configured `allowed_paths` only
- **Core protection** prevents modifications to `wp-config.php`, `.htaccess`, and WordPress core
- **Nonce verification** on all REST endpoints and AJAX handlers
- **Admin-only** access enforced via `current_user_can('manage_options')`
- **Input sanitization** via `sanitize_text_field`, `esc_url_raw`, `absint` on all inputs

## Requirements

- WordPress 5.9+
- PHP 7.4+
- An AI provider API key (or local model server)

## License

GPL v2 or later — same as WordPress.
