/**
 * BossCode Gemini Response Cleaner
 *
 * Strips conversational fluff, formatting artifacts, and normalizes
 * output from browser-based Gemini responses for agentic consumption.
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
     * Main cleaning pipeline.
     *
     * @param {string} raw - Raw response from Gemini browser.
     * @param {object} options - Cleaning options.
     * @returns {string} Cleaned response.
     */
    static clean(raw, options = {}) {
        if (!raw || typeof raw !== 'string') return '';

        let text = raw.trim();

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
        // Match triple-backtick code blocks
        const codeBlockMatch = text.match(/```[\w]*\n([\s\S]*?)```/);
        if (codeBlockMatch) {
            const code = codeBlockMatch[1].trim();
            // Only extract if the code block is the majority of the response
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
        // Remove excessive blank lines (more than 2 consecutive)
        text = text.replace(/\n{4,}/g, '\n\n\n');

        // Fix tabs mixed with spaces
        text = text.replace(/\t/g, '    ');

        // Remove trailing whitespace per line
        text = text.replace(/[ \t]+$/gm, '');

        return text;
    }

    /**
     * Fix common markdown formatting issues from browser extraction.
     */
    static fixMarkdown(text) {
        // Fix broken bold markers
        text = text.replace(/\*\* /g, '**');
        text = text.replace(/ \*\*/g, '**');

        // Fix broken inline code
        text = text.replace(/` /g, '`');
        text = text.replace(/ `/g, '`');

        return text;
    }

    /**
     * Aggressive cleaning for agent-mode responses.
     * Strips ALL conversational text and returns only actionable content.
     *
     * @param {string} raw
     * @returns {string}
     */
    static cleanForAgent(raw) {
        let text = this.clean(raw, { expectCode: true, expectJSON: true });

        // Additional agent-specific cleaning
        // Remove any line that's purely conversational (no code, no structure)
        const lines = text.split('\n');
        const filtered = lines.filter(line => {
            const trimmed = line.trim();
            if (!trimmed) return true; // Keep blank lines for formatting

            // Keep code lines (indented or code fence)
            if (trimmed.startsWith('```') || trimmed.startsWith('    ') || trimmed.startsWith('\t')) return true;

            // Keep markdown headers
            if (trimmed.startsWith('#')) return true;

            // Keep list items
            if (/^[-*+•]\s/.test(trimmed) || /^\d+[.)]\s/.test(trimmed)) return true;

            // Keep lines with code-like content
            if (/[{}\[\]();=<>]/.test(trimmed)) return true;

            // Keep file paths
            if (/[\/\\][\w.]+/.test(trimmed)) return true;

            return true; // Keep everything else for now — too aggressive filtering loses info
        });

        return filtered.join('\n').trim();
    }
}

module.exports = ResponseCleaner;
