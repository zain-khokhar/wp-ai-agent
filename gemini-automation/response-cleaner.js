/**
 * BossCode Gemini Response Cleaner v2
 *
 * Strips conversational fluff, formatting artifacts, and normalizes
 * output from browser-based Gemini responses for agentic consumption.
 *
 * v2 ADDS: Intent-based tool-call extraction. When Gemini sends a
 * structured prose response (Understand/Plan/Execute) that embeds a
 * JSON tool call inside it, we extract and return ONLY the tool call
 * so the PHP agentic loop can continue without breaking.
 */

class ResponseCleaner {

    /**
     * Preamble patterns — conversational openers that add no value.
     */
    static PREAMBLE_PATTERNS = [
        /^(sure|okay|of course|certainly|absolutely|great|no problem|happy to help)[,!.\s]*/i,
        /^(here['']?s?|here is|here are|i['']?ve|i have|i will|let me|allow me)[^.]*[.:]\s*/i,
        /^(below is|the following|this is|i['']?ll|i can|i would)[^.]*[.:]\s*/i,
        /^(alright|right|so|well|now)[,!.\s]+/i,
        /^(as requested|as you asked|based on your request)[^.]*[.:]\s*/i,
        /^(i understand|got it|understood)[^.]*[.:]\s*/i,
    ];

    /**
     * Postamble patterns — conversational closers that add no value.
     */
    static POSTAMBLE_PATTERNS = [
        /\n*(let me know|feel free|hope this helps|if you have|if you need|don['']?t hesitate)[^]*$/i,
        /\n*(is there anything|would you like|do you want|shall i|want me to)[^]*$/i,
        /\n*(please let|happy to|glad to|i['']?m here)[^]*$/i,
        /\n*\*\*Note:\*\*\s*(this|please|make sure|remember|keep in mind)[^]*$/i,
    ];

    /**
     * Patterns that indicate Gemini is responding with a structured
     * Understand/Plan/Execute workflow instead of bare JSON.
     */
    static AGENTIC_PROSE_PATTERNS = [
        /###\s*\d+\.\s*(understand|plan|execute|read|write|verify)/i,
        /^#+\s*(understand|plan|execute|think|action|step\s+\d)/im,
        /\*\*(understand|plan|step\s+\d|action|execute)\*\*/i,
        /^\d+\.\s+\*\*(understand|plan|execute)/im,
    ];

    /**
     * Known Gemini tool invocation patterns inside prose.
     * Example: ```json\n{"tool_calls": [...]}```
     * or the explicit JSON syntax block.
     */
    static TOOL_CALL_EXTRACTION_PATTERNS = [
        // Fenced JSON block with tool_calls key
        /```(?:json)?\s*\n?\s*(\{[\s\S]*?"tool_calls"[\s\S]*?\})\s*```/,
        // Bare JSON object on its own line(s)
        /(\{"tool_calls"\s*:[\s\S]*?\})\s*(?:$|```)/,
        // Fenced JSON with tool_calls array format
        /```(?:json)?\s*\n?(\{"tool_calls"\s*:[\s\S]*?\})\s*```/i,
        // Any fenced JSON block (fallback — may contain the call)
        /```(?:json)?\s*\n?([\s\S]*?)\s*```/,
    ];

    /**
     * Main cleaning pipeline.
     *
     * @param {string} raw - Raw response from Gemini browser.
     * @param {object} options - Cleaning options.
     * @returns {string} Cleaned response.
     */
    static clean(raw, options = {}) {
        if (!raw || typeof raw !== 'string') return '';

        let text = raw.trim();

        // Step 0: If this is a structured agentic prose response, try to
        // extract the embedded tool call FIRST before any other processing.
        if (options.expectJSON || this.isAgenticProse(text)) {
            const toolCallJSON = this.extractEmbeddedToolCall(text);
            if (toolCallJSON) {
                console.log('[ResponseCleaner] Extracted embedded tool call from agentic prose');
                return toolCallJSON;
            }
        }

        // Step 1: If the entire response is a single code block, extract it
        if (options.expectCode) {
            const extracted = this.extractCodeBlock(text);
            if (extracted) return extracted;
        }

        // Step 2: Strip preamble
        text = this.stripPreamble(text);

        // Step 3: Strip postamble
        text = this.stripPostamble(text);

        // Step 4: Normalize whitespace
        text = this.normalizeWhitespace(text);

        // Step 5: Fix common markdown issues from browser extraction
        text = this.fixMarkdown(text);

        // Step 6: Handle tool-call JSON extraction if expected
        if (options.expectJSON) {
            const json = this.extractJSON(text);
            if (json) return json;
        }

        return text.trim();
    }

    /**
     * Detect if the response is a structured agentic prose response
     * (Understand/Plan/Execute pattern that Gemini prefers when it thinks).
     *
     * @param {string} text
     * @returns {boolean}
     */
    static isAgenticProse(text) {
        return this.AGENTIC_PROSE_PATTERNS.some(pattern => pattern.test(text));
    }

    /**
     * Extract an embedded tool_calls JSON block from within prose text.
     * Gemini often writes a plan and THEN includes the tool call JSON.
     *
     * Returns the raw JSON string if found, otherwise null.
     *
     * @param {string} text
     * @returns {string|null}
     */
    static extractEmbeddedToolCall(text) {
        for (const pattern of this.TOOL_CALL_EXTRACTION_PATTERNS) {
            const match = text.match(pattern);
            if (!match) continue;

            const candidate = (match[1] || '').trim();
            if (!candidate) continue;

            try {
                const parsed = JSON.parse(candidate);
                // Must have tool_calls key with at least one entry
                if (parsed && Array.isArray(parsed.tool_calls) && parsed.tool_calls.length > 0) {
                    return candidate;
                }
            } catch (e) {
                // Not valid JSON — try extracting a balanced JSON object manually
                const balanced = this.extractBalancedJSON(text);
                if (balanced) {
                    try {
                        const parsed = JSON.parse(balanced);
                        if (parsed && Array.isArray(parsed.tool_calls) && parsed.tool_calls.length > 0) {
                            return balanced;
                        }
                    } catch (e2) { /* continue */ }
                }
            }
        }
        return null;
    }

    /**
     * Extract the first balanced JSON object { ... } from text,
     * handling nested objects/arrays correctly.
     *
     * @param {string} text
     * @returns {string|null}
     */
    static extractBalancedJSON(text) {
        // Find the first '{' that starts a potential tool_calls block
        const starts = [];
        for (let i = 0; i < text.length; i++) {
            if (text[i] === '{' && text.slice(i).includes('"tool_calls"')) {
                starts.push(i);
                break;
            }
        }

        for (const start of starts) {
            let depth = 0;
            let inString = false;
            let escape = false;

            for (let i = start; i < text.length; i++) {
                const ch = text[i];

                if (escape) { escape = false; continue; }
                if (ch === '\\' && inString) { escape = true; continue; }
                if (ch === '"') { inString = !inString; continue; }
                if (inString) continue;

                if (ch === '{' || ch === '[') depth++;
                else if (ch === '}' || ch === ']') {
                    depth--;
                    if (depth === 0) {
                        return text.slice(start, i + 1);
                    }
                }
            }
        }
        return null;
    }

    /**
     * Strip conversational preamble.
     */
    static stripPreamble(text) {
        let result = text;
        let changed = true;
        let iterations = 0;

        while (changed && iterations < 5) {
            changed = false;
            iterations++;
            for (const pattern of this.PREAMBLE_PATTERNS) {
                const before = result;
                result = result.replace(pattern, '');
                if (result !== before) {
                    changed = true;
                    result = result.trim();
                }
            }
        }

        return result;
    }

    /**
     * Strip conversational postamble.
     */
    static stripPostamble(text) {
        let result = text;
        for (const pattern of this.POSTAMBLE_PATTERNS) {
            result = result.replace(pattern, '');
        }
        return result.trim();
    }

    /**
     * Extract a code block if the response is predominantly code.
     *
     * @param {string} text
     * @returns {string|null}
     */
    static extractCodeBlock(text) {
        const codeBlockMatch = text.match(/```[\w]*\n([\s\S]*?)```/);
        if (codeBlockMatch) {
            const code = codeBlockMatch[1].trim();
            const nonCodeLength = text.length - codeBlockMatch[0].length;
            if (nonCodeLength < code.length * 0.3) {
                return code;
            }
        }
        return null;
    }

    /**
     * Extract JSON from a response that may contain surrounding text.
     *
     * @param {string} text
     * @returns {string|null} JSON string or null.
     */
    static extractJSON(text) {
        // Try to find JSON in code block first
        const codeBlockJSON = text.match(/```(?:json)?\s*\n?([\s\S]*?)```/);
        if (codeBlockJSON) {
            try {
                JSON.parse(codeBlockJSON[1].trim());
                return codeBlockJSON[1].trim();
            } catch (e) { /* not valid JSON */ }
        }

        // Try balanced extraction
        const balanced = this.extractBalancedJSON(text);
        if (balanced) {
            try {
                JSON.parse(balanced);
                return balanced;
            } catch (e) { /* not valid JSON */ }
        }

        // Try to find raw JSON object or array
        const jsonMatch = text.match(/(\{[\s\S]*\}|\[[\s\S]*\])/);
        if (jsonMatch) {
            try {
                JSON.parse(jsonMatch[1].trim());
                return jsonMatch[1].trim();
            } catch (e) { /* not valid JSON */ }
        }

        return null;
    }

    /**
     * Normalize whitespace oddities from browser text extraction.
     */
    static normalizeWhitespace(text) {
        text = text.replace(/\n{4,}/g, '\n\n\n');
        text = text.replace(/\t/g, '    ');
        text = text.replace(/[ \t]+$/gm, '');
        return text;
    }

    /**
     * Fix common markdown formatting issues from browser extraction.
     */
    static fixMarkdown(text) {
        text = text.replace(/\*\* /g, '**');
        text = text.replace(/ \*\*/g, '**');
        text = text.replace(/` /g, '`');
        text = text.replace(/ `/g, '`');
        return text;
    }

    /**
     * Aggressive cleaning for agent-mode responses.
     * v2: Prioritizes extracting embedded tool calls from agentic prose.
     *
     * @param {string} raw
     * @returns {string}
     */
    static cleanForAgent(raw) {
        if (!raw) return '';

        // Priority 1: If this looks like an agentic prose response with
        // an embedded tool call, extract just the tool call JSON.
        if (this.isAgenticProse(raw)) {
            const embedded = this.extractEmbeddedToolCall(raw);
            if (embedded) {
                console.log('[ResponseCleaner] cleanForAgent: extracted tool call from agentic prose');
                return embedded;
            }
        }

        // Priority 2: Standard cleaning pipeline
        let text = this.clean(raw, { expectCode: true, expectJSON: true });
        return text;
    }
}

module.exports = ResponseCleaner;
