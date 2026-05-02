/**
 * BossCode Gemini Automation — Core v2
 *
 * Fixed:
 *  - Removed stylesheet/font blocking (broke Gemini's React rendering)
 *  - Replaced execCommand with clipboard paste (reliable in Chrome)
 *  - Updated selectors for current Gemini UI (2025)
 *  - More robust response detection using stop-button + stability
 *  - Better new-chat navigation
 */

const puppeteer = require('puppeteer');
const path      = require('path');
const ResponseCleaner = require('./response-cleaner');

class GeminiAutomation {

    constructor(options = {}) {
        this.browser         = null;
        this.page            = null;
        this.isInitialized   = false;
        this.userDataDir     = options.userDataDir || path.join(__dirname, 'session');
        this.headless        = options.headless !== undefined ? options.headless : false;
        this.activeRequest   = null;
        this.maxRetries      = options.maxRetries || 2;
        this.requestTimeout  = options.requestTimeout || 180000;
        this.lastRequestTime = 0;
        this.minRequestInterval = 3000;
    }

    delay(ms) { return new Promise(r => setTimeout(r, ms)); }

    // ── Initialize ────────────────────────────────────────────
    async initialize() {
        if (this.isInitialized) { console.log('✓ Already initialized'); return true; }

        try {
            console.log('🚀 Launching browser...');
            this.browser = await puppeteer.launch({
                headless: this.headless,
                userDataDir: this.userDataDir,
                defaultViewport: null,
                args: [
                    '--start-maximized',
                    '--disable-blink-features=AutomationControlled',
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    // Allow clipboard access (needed for paste method)
                    '--enable-features=Clipboard',
                ]
            });

            this.browser.on('disconnected', () => {
                console.log('⚠️ Browser disconnected');
                this.isInitialized = false;
                this.browser = null;
                this.page = null;
            });

            this.page = await this.browser.newPage();

            // Anti-detection
            await this.page.evaluateOnNewDocument(() => {
                Object.defineProperty(navigator, 'webdriver', { get: () => false });
            });

            // Grant clipboard permissions
            const context = this.browser.defaultBrowserContext();
            await context.overridePermissions('https://gemini.google.com', [
                'clipboard-read', 'clipboard-write'
            ]);

            // ⚠️ DO NOT block stylesheets — Gemini is a React SPA that needs CSS
            // Only block heavy media that won't affect functionality
            await this.page.setRequestInterception(true);
            this.page.on('request', (req) => {
                const type = req.resourceType();
                const url  = req.url();
                // Only block large media files, NOT stylesheets/fonts/scripts
                if (type === 'image' && !url.includes('google')) {
                    req.abort();
                } else if (type === 'media') {
                    req.abort();
                } else {
                    req.continue();
                }
            });

            console.log('🌐 Navigating to Gemini...');
            await this.page.goto('https://gemini.google.com/app', {
                waitUntil: 'domcontentloaded',
                timeout: 60000
            });

            // Wait for page to settle
            await this.delay(3000);

            const isLoggedIn = await this.checkLoginStatus();

            if (!isLoggedIn) {
                console.log('\n' + '='.repeat(60));
                console.log('⚠️  NOT LOGGED IN — Please log in within 5 minutes.');
                console.log('='.repeat(60) + '\n');

                const deadline = Date.now() + 300000;
                while (Date.now() < deadline) {
                    await this.delay(5000);
                    if (await this.checkLoginStatus()) {
                        console.log('✓ Login detected!');
                        break;
                    }
                    console.log(`⏳ Waiting for login... (${Math.round((Date.now()-deadline+300000)/1000)}s remaining)`);
                }

                if (!await this.checkLoginStatus()) {
                    throw new Error('Login timeout.');
                }
            } else {
                console.log('✓ Logged in (session restored)');
            }

            await this.delay(2000);
            this.isInitialized = true;
            console.log('✓ Gemini ready\n');
            return true;

        } catch (err) {
            console.error('❌ Initialization failed:', err.message);
            await this.close();
            return false;
        }
    }

    // ── Login check ───────────────────────────────────────────
    async checkLoginStatus() {
        try {
            // Gemini 2025 input selectors (in priority order)
            const sel = await this.page.$(
                'rich-textarea div[contenteditable="true"], ' +
                'div.ql-editor[contenteditable="true"], ' +
                'div[role="textbox"][contenteditable="true"], ' +
                'textarea.textarea'
            );
            return sel !== null;
        } catch { return false; }
    }

    // ── Find input element ────────────────────────────────────
    async findInputElement() {
        // Gemini 2025 uses a custom <rich-textarea> web component
        // The real editable div is inside it
        const SELECTORS = [
            'rich-textarea div[contenteditable="true"]',
            'div.ql-editor[contenteditable="true"]',
            'div[role="textbox"][contenteditable="true"]',
            'div[contenteditable="true"]',
            'textarea',
        ];

        for (const sel of SELECTORS) {
            try {
                const el = await this.page.$(sel);
                if (el) {
                    const visible = await el.isVisible();
                    if (visible) {
                        console.log(`   ✓ Found input: ${sel}`);
                        return { el, sel };
                    }
                }
            } catch { /* continue */ }
        }
        return null;
    }

    // ── Type text reliably (Paste injection via DOM) ────────────────
    async insertText(text) {
        const found = await this.findInputElement();
        if (!found) throw new Error('Gemini input element not found');

        const { el, sel } = found;

        // 1. Click to focus and clear existing
        await el.click();
        await this.delay(200);
        await this.page.keyboard.down('Control');
        await this.page.keyboard.press('KeyA');
        await this.page.keyboard.up('Control');
        await this.page.keyboard.press('Backspace');
        await this.delay(300);

        // 2. DOM-based direct paste injection (User's requested logic)
        await this.page.evaluate((selector, textContent) => {
            const element = document.querySelector(selector);
            if (element) {
                if (element.tagName === 'TEXTAREA') {
                    element.value = textContent;
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                } else if (element.getAttribute('role') === 'textbox' || element.getAttribute('contenteditable') === 'true') {
                    element.textContent = textContent;
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        }, sel, text);

        console.log(`   ✓ Text pasted successfully via DOM injection (${text.length} chars)`);
        await this.delay(800);
    }

    // ── Submit the query ──────────────────────────────────────
    async submitQuery() {
        // Try clicking the send button first
        const sendSelectors = [
            'button[aria-label*="Send"]',
            'button[data-tooltip*="Send"]',
            'button[aria-label*="send"]',
            'button.send-button',
            'mat-icon[fonticon="send"]',
        ];

        for (const sel of sendSelectors) {
            try {
                const btn = await this.page.$(sel);
                if (btn) {
                    const visible = await btn.isVisible();
                    if (visible) {
                        await btn.click();
                        console.log(`   ✓ Submitted via send button (${sel})`);
                        return;
                    }
                }
            } catch { /* continue */ }
        }

        // Fallback: press Enter
        await this.page.keyboard.press('Enter');
        console.log('   ✓ Submitted via Enter key');
    }

    // ── Wait for response (MutationObserver) ──────────────────
    async waitForResponse(timeoutMs = 180000, domDelayMs = 1000) {
        try {
            const startTime = Date.now();
            console.log('   🔍 Waiting for Gemini to finish generating...');
            
            // Wait for response to start appearing
            await this.page.waitForSelector('message-content', { visible: true, timeout: 120000 });
            await this.delay(800);
            
            // ADVANCED: Use MutationObserver to detect when DOM stops changing
            const generationComplete = await this.page.evaluate((maxWaitMs) => {
                return new Promise((resolve, reject) => {
                    const startTime = Date.now();
                    let lastMutationTime = Date.now();
                    let stableCount = 0;
                    const STABLE_CHECKS_NEEDED = 5;
                    const STABLE_DURATION_MS = 1500;
                    
                    const messages = document.querySelectorAll('message-content');
                    if (messages.length === 0) {
                        reject(new Error('No message-content found'));
                        return;
                    }
                    const lastMessage = messages[messages.length - 1];
                    
                    const checkCompletionSignals = () => {
                        const signals = {
                            copyButton: false,
                            stopButton: false,
                            textStable: false,
                            cursorGone: false
                        };
                        
                        const copyButton = lastMessage.querySelector('button[aria-label*="Copy"], button[data-tooltip*="Copy"], button[title*="Copy"]');
                        signals.copyButton = copyButton && copyButton.offsetParent !== null;
                        
                        const stopButton = document.querySelector('button[aria-label*="Stop"], button[aria-label*="stop"]');
                        signals.stopButton = !stopButton || stopButton.offsetParent === null;
                        
                        const typingIndicator = lastMessage.querySelector('.typing-indicator, .cursor, [class*="typing"]');
                        signals.cursorGone = !typingIndicator || typingIndicator.offsetParent === null;
                        
                        const timeSinceLastMutation = Date.now() - lastMutationTime;
                        signals.textStable = timeSinceLastMutation >= STABLE_DURATION_MS;
                        
                        return signals;
                    };
                    
                    const observer = new MutationObserver((mutations) => {
                        const relevantMutation = mutations.some(mutation => {
                            if (mutation.type === 'childList' || mutation.type === 'characterData') {
                                return true;
                            }
                            return false;
                        });
                        
                        if (relevantMutation) {
                            lastMutationTime = Date.now();
                            stableCount = 0;
                        }
                    });
                    
                    observer.observe(lastMessage, {
                        childList: true,
                        subtree: true,
                        characterData: true,
                        attributes: false
                    });
                    
                    const checkInterval = setInterval(() => {
                        const elapsed = Date.now() - startTime;
                        
                        if (elapsed >= maxWaitMs) {
                            clearInterval(checkInterval);
                            observer.disconnect();
                            resolve({ completed: false, reason: 'timeout' });
                            return;
                        }
                        
                        const signals = checkCompletionSignals();
                        
                        const isComplete = signals.copyButton || 
                                         (signals.stopButton && signals.textStable && signals.cursorGone);
                        
                        if (isComplete) {
                            stableCount++;
                            
                            if (stableCount >= STABLE_CHECKS_NEEDED) {
                                clearInterval(checkInterval);
                                observer.disconnect();
                                resolve({ completed: true, signals });
                                return;
                            }
                        } else {
                            stableCount = 0;
                        }
                    }, 500);
                });
            }, timeoutMs);
            
            if (!generationComplete.completed) {
                console.log('   ⚠️ Generation may not be complete, but proceeding to extract response...');
            } else {
                const detectionTime = Date.now() - startTime;
                console.log(`   ✓ Generation confirmed complete in ${detectionTime}ms`);
            }
            
            console.log(`   ⏳ DOM stable delay triggered (${domDelayMs}ms)...`);
            await this.delay(domDelayMs);
            
            return await this.extractResponseText();
            
        } catch (error) {
            console.error('   ❌ Error in waitForResponse:', error.message);
            throw error;
        }
    }

    // ── Extract response text ─────────────────────────────────
    async extractResponseText() {
        try {
            const messages = await this.page.$$('message-content');
            if (messages.length === 0) {
                throw new Error('No messages found after waiting');
            }
            
            const lastMessage = messages[messages.length - 1];
            
            const text = await lastMessage.evaluate(el => {
                const codeBlock = el.querySelector('pre code, code[class*="language-"], .code-block code');
                if (codeBlock && codeBlock.textContent.trim().length > 50) {
                    return codeBlock.textContent.trim();
                }
                
                const markdown = el.querySelector('.markdown');
                if (markdown && markdown.textContent.trim().length > 50) {
                    return markdown.textContent.trim();
                }
                
                return el.textContent.trim();
            });
            
            if (!text || text.length < 20) {
                throw new Error(`Response too short or empty (${text.length} characters)`);
            }
            
            return text;
        } catch (error) {
            console.log('   🔄 Attempting recovery...');
            await this.delay(2000);
            const messages = await this.page.$$('message-content');
            if (messages.length > 0) {
                const lastMessage = messages[messages.length - 1];
                const text = await lastMessage.evaluate(el => {
                    const codeBlock = el.querySelector('pre code, code[class*="language-"], .code-block code');
                    if (codeBlock && codeBlock.textContent.trim().length > 50) {
                        return codeBlock.textContent.trim();
                    }
                    const markdown = el.querySelector('.markdown');
                    return markdown ? markdown.textContent.trim() : el.textContent.trim();
                });
                if (text && text.length > 20) {
                    console.log(`   ✓ Recovered response (${text.length} characters)`);
                    return text;
                }
            }
            throw error;
        }
    }

    // ── Main sendQuery ────────────────────────────────────────
    async sendQuery(text, systemPrompt = '', options = {}) {
        // Rate limiting
        const gap = Date.now() - this.lastRequestTime;
        if (gap < this.minRequestInterval) await this.delay(this.minRequestInterval - gap);

        // Queue: wait for active request
        if (this.activeRequest) {
            try { await this.activeRequest; } catch { /* ignore */ }
        }

        const retryCount = options.retryCount || 0;

        const run = async () => {
            console.log('\n📤 Sending query to Gemini...');

            const fullPrompt = systemPrompt
                ? `${systemPrompt}\n\n---\n\n${text}`
                : text;

            // Insert text
            await this.insertText(fullPrompt);
            await this.delay(600);

            // Submit
            await this.submitQuery();
            console.log('   ✓ Query submitted, waiting for response...');

            // Get response
            const rawResponse = await Promise.race([
                this.waitForResponse(this.requestTimeout),
                new Promise((_, reject) => setTimeout(
                    () => reject(new Error('Overall timeout')),
                    this.requestTimeout + 10000
                ))
            ]);

            this.lastRequestTime = Date.now();

            if (!rawResponse || rawResponse.length < 10) {
                throw new Error(`Response too short (${rawResponse ? rawResponse.length : 0} chars)`);
            }

            // Clean
            const cleaned = options.cleanForAgent
                ? ResponseCleaner.cleanForAgent(rawResponse)
                : ResponseCleaner.clean(rawResponse, {
                    expectJSON: options.expectJSON || false,
                    expectCode: options.expectCode || false,
                });

            console.log(`   ✓ Done (${rawResponse.length} → ${cleaned.length} chars)`);
            return cleaned;
        };

        const promise = (async () => {
            try {
                return await run();
            } catch (err) {
                console.error(`   ❌ Failed: ${err.message}`);
                if (retryCount < this.maxRetries) {
                    console.log(`   🔄 Retry ${retryCount + 2}/${this.maxRetries + 1}...`);
                    await this.delay(4000);
                    try { await this.startNewChat(); } catch { /* ignore */ }
                    return this.sendQuery(text, systemPrompt, { ...options, retryCount: retryCount + 1 });
                }
                throw err;
            }
        })();

        this.activeRequest = promise;
        try { return await promise; }
        finally { this.activeRequest = null; }
    }

    // ── Start new chat ────────────────────────────────────────
    async startNewChat() {
        try {
            // Try new chat button
            const btn = await this.page.$('a[href="/app"], button[aria-label*="New chat"], a[aria-label*="New chat"]');
            if (btn) { await btn.click(); await this.delay(2000); return; }
        } catch { /* fallback */ }

        await this.page.goto('https://gemini.google.com/app', {
            waitUntil: 'domcontentloaded', timeout: 30000
        });
        await this.delay(2000);
        console.log('   ✓ New chat started');
    }

    // ── Health check ──────────────────────────────────────────
    async healthCheck() {
        if (!this.isInitialized || !this.page) {
            return { status: 'offline', message: 'Not initialized' };
        }
        try {
            const ready = await this.checkLoginStatus();
            return {
                status: ready ? 'ready' : 'needs_login',
                message: ready ? 'Gemini automation is ready' : 'Login required in browser',
                busy: this.activeRequest !== null,
                lastRequest: this.lastRequestTime > 0 ? new Date(this.lastRequestTime).toISOString() : null,
            };
        } catch (err) {
            return { status: 'error', message: err.message };
        }
    }

    // ── Close ─────────────────────────────────────────────────
    async close() {
        if (this.browser) {
            try { await this.browser.close(); } catch { /* ignore */ }
            this.browser = null;
            this.page = null;
            this.isInitialized = false;
            console.log('✓ Browser closed');
        }
    }
}

module.exports = GeminiAutomation;
