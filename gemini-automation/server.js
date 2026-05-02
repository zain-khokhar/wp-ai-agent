/**
 * BossCode Gemini Automation — HTTP Server
 *
 * Express server that bridges WordPress plugin ↔ Puppeteer Gemini automation.
 * Run: node server.js [--headless] [--port 3200]
 *
 * Endpoints:
 *   GET  /health     — Check if automation is alive
 *   POST /chat       — Send a query and get a response
 *   POST /new-chat   — Start a fresh Gemini conversation
 *   POST /shutdown   — Gracefully stop the server
 */

const express = require('express');
const GeminiAutomation = require('./gemini_core');
const path = require('path');

// ─── Parse CLI Arguments ────────────────────────────────────────
const args = process.argv.slice(2);
const PORT = parseInt(getArg('--port', '3200'));
const HEADLESS = args.includes('--headless');
const SESSION_DIR = getArg('--session', path.join(__dirname, 'session'));

function getArg(name, defaultValue) {
    const idx = args.indexOf(name);
    if (idx !== -1 && args[idx + 1]) return args[idx + 1];
    return defaultValue;
}

// ─── Initialize ─────────────────────────────────────────────────
const gemini = new GeminiAutomation({
    headless: HEADLESS,
    userDataDir: SESSION_DIR,
    maxRetries: 2,
    requestTimeout: 180000,
});

const app = express();
app.use(express.json({ limit: '10mb' })); // Large payloads for file context

// CORS — allow WordPress to call from any origin
app.use((req, res, next) => {
    res.header('Access-Control-Allow-Origin', '*');
    res.header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    if (req.method === 'OPTIONS') return res.sendStatus(200);
    next();
});

// ─── Routes ─────────────────────────────────────────────────────

/**
 * GET /health — Check automation status
 */
app.get('/health', async (req, res) => {
    try {
        const health = await gemini.healthCheck();
        res.json(health);
    } catch (error) {
        res.status(500).json({ status: 'error', message: error.message });
    }
});

/**
 * POST /chat — Send a prompt and get a response
 *
 * Body: {
 *   prompt: string,
 *   systemPrompt?: string,
 *   context?: string,        // File contents or page context to include
 *   cleanForAgent?: boolean,  // Aggressive cleaning for agentic use
 *   expectJSON?: boolean,     // Try to extract JSON from response
 *   expectCode?: boolean,     // Try to extract code blocks
 * }
 */
app.post('/chat', async (req, res) => {
    const {
        prompt,
        systemPrompt = '',
        context = '',
        cleanForAgent = true,
        expectJSON = false,
        expectCode = false,
    } = req.body;

    if (!prompt) {
        return res.status(400).json({ success: false, error: 'prompt is required' });
    }

    // Build the full prompt with context
    let fullPrompt = prompt;
    if (context) {
        fullPrompt = `<context>\n${context}\n</context>\n\n${prompt}`;
    }

    try {
        console.log(`\n${'─'.repeat(60)}`);
        console.log(`📨 Incoming request: ${prompt.substring(0, 100)}...`);

        const response = await gemini.sendQuery(fullPrompt, systemPrompt, {
            cleanForAgent,
            expectJSON,
            expectCode,
        });

        res.json({
            success: true,
            response: response,
            meta: {
                promptLength: fullPrompt.length,
                responseLength: response.length,
                timestamp: new Date().toISOString(),
            }
        });

    } catch (error) {
        console.error('❌ Chat error:', error.message);
        res.status(500).json({
            success: false,
            error: error.message,
            retryable: true,
        });
    }
});

/**
 * POST /new-chat — Start a fresh conversation
 */
app.post('/new-chat', async (req, res) => {
    try {
        await gemini.startNewChat();
        res.json({ success: true, message: 'New chat started' });
    } catch (error) {
        res.status(500).json({ success: false, error: error.message });
    }
});

/**
 * POST /shutdown — Graceful shutdown
 */
app.post('/shutdown', async (req, res) => {
    res.json({ success: true, message: 'Shutting down...' });
    setTimeout(async () => {
        await gemini.close();
        process.exit(0);
    }, 500);
});

// ─── Start Server ───────────────────────────────────────────────
async function main() {
    console.log(`
╔══════════════════════════════════════════════════╗
║       BossCode Gemini Automation Server          ║
║                                                  ║
║  Bridges WordPress ↔ Gemini via Puppeteer        ║
╚══════════════════════════════════════════════════╝
`);
    console.log(`📋 Config:`);
    console.log(`   Port:     ${PORT}`);
    console.log(`   Headless: ${HEADLESS}`);
    console.log(`   Session:  ${SESSION_DIR}`);
    console.log('');

    // Initialize Gemini browser
    const success = await gemini.initialize();
    if (!success) {
        console.error('❌ Failed to initialize Gemini. Exiting.');
        process.exit(1);
    }

    // Start HTTP server
    app.listen(PORT, () => {
        console.log(`\n🟢 Server listening on http://localhost:${PORT}`);
        console.log(`   Health:   GET  http://localhost:${PORT}/health`);
        console.log(`   Chat:     POST http://localhost:${PORT}/chat`);
        console.log(`   New Chat: POST http://localhost:${PORT}/new-chat`);
        console.log(`   Shutdown: POST http://localhost:${PORT}/shutdown`);
        console.log('');
    });
}

// Graceful shutdown on CTRL+C
process.on('SIGINT', async () => {
    console.log('\n\n🛑 Shutting down gracefully...');
    await gemini.close();
    process.exit(0);
});

process.on('SIGTERM', async () => {
    await gemini.close();
    process.exit(0);
});

// Catch unhandled errors
process.on('unhandledRejection', (reason) => {
    console.error('Unhandled rejection:', reason);
});

main().catch(err => {
    console.error('Fatal error:', err);
    process.exit(1);
});
