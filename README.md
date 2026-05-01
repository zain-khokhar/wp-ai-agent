# BossCode AI Agent

**BossCode AI Agent** is a WordPress admin dashboard plugin that embeds an agentic IDE experience powered by local AI models. It supports direct integration with local LLM endpoints such as Ollama or LM Studio and provides a clean chat-based interface for AI-assisted development inside WordPress.

## 🚀 Key Features

- WordPress admin plugin for AI-assisted coding and content workflows
- Local AI model support via OpenAI-compatible endpoints
- Built-in REST API routes for secure chat and settings management
- React-based dashboard interface loaded inside the WordPress admin
- Configurable API base URL and optional API key
- Designed for local LLM development environments and self-hosted workflows

## 🔧 Installation

1. Copy the `bosscode-ai-agent` folder into your WordPress `wp-content/plugins/` directory.
2. Activate **BossCode AI Agent** from the WordPress admin plugin screen.
3. Open the new **BossCode AI** admin menu item.

## ⚙️ Setup

1. In the BossCode AI admin page, switch to the **Settings** tab.
2. Set the **API Base URL** for your local AI model endpoint.
   - Example for Ollama: `http://localhost:11434/v1`
   - Example for LM Studio: `http://127.0.0.1:1234/v1`
3. Optionally add an API key if your local model requires authentication.
4. Click **Save Settings**.

## 💬 Usage

- Navigate to the **Agent Chat** tab.
- Enter your prompt and send the message.
- The plugin relays the prompt to your configured local AI model and displays the response.

## 🧠 How It Works

- `bosscode-ai-agent/bosscode-ai-agent.php`
  - Registers the admin menu and renders the React app container.
  - Enqueues React, ReactDOM, and Babel for runtime JSX rendering.
  - Localizes REST settings and nonce values for frontend API access.
- `bosscode-ai-agent/backend/api-routes.php`
  - Registers REST endpoints for settings retrieval, saving, and chat handling.
  - Enforces admin-only access via `manage_options` capability.
- `bosscode-ai-agent/backend/ai-handler.php`
  - Sends chat prompts to a local OpenAI-compatible LLM endpoint.
  - Handles local request filtering and error parsing.
- `bosscode-ai-agent/frontend/app.js`
  - Implements the admin UI with settings and chat flow.
  - Handles prompt submission, chat history, and backend API calls.

## ✅ Supported Local AI Workflows

- Ollama local API
- LM Studio local API
- Any OpenAI-compatible local LLM endpoint

## 💡 Best Practices

- Use a secure local network and keep your API key private.
- Run the plugin only in development or staging until production hardening is complete.
- Replace runtime Babel usage with a built production bundle for performance and security.

## 🛠️ Troubleshooting

- If chat fails, verify the **API Base URL** is reachable from the WordPress server.
- Ensure the local model endpoint returns a valid OpenAI-compatible `/chat/completions` response.
- Check WordPress REST permissions and nonce validation if settings fail to save.

## 📁 Plugin Structure

- `bosscode-ai-agent/bosscode-ai-agent.php` — Plugin entry point and admin page registration
- `bosscode-ai-agent/backend/ai-handler.php` — AI request handling and local model support
- `bosscode-ai-agent/backend/api-routes.php` — REST endpoints for chat and settings
- `bosscode-ai-agent/frontend/app.js` — React frontend UI loaded inside the admin page

## 📌 License

This repository is ready for extension and customization for your WordPress AI plugin needs.
