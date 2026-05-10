<?php
/**
 * BossCode_Context_Manager — Token Counting & Context Window Truncation
 *
 * Ensures conversation history never exceeds the LLM's context window.
 * Uses a simple chars-per-token heuristic (4 chars ≈ 1 token) and truncates
 * from the middle of the conversation, always preserving the system message
 * and the most recent user messages.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Context_Manager {

    /**
     * Approximate characters per token.
     * GPT/Claude tokenizers average ~4 chars per token for English text.
     */
    const CHARS_PER_TOKEN = 4;

    /**
     * Estimate token count for a string.
     *
     * @param string $text Input text.
     * @return int Estimated token count.
     */
    public static function estimate_tokens( $text ) {
        if ( empty( $text ) ) {
            return 0;
        }
        return (int) ceil( mb_strlen( $text, 'UTF-8' ) / self::CHARS_PER_TOKEN );
    }

    /**
     * Estimate total tokens in a messages array.
     *
     * Each message contributes its content plus a small overhead for role/name fields.
     *
     * @param array $messages Array of message arrays with 'role' and 'content'.
     * @return int Total estimated tokens.
     */
    public static function count_messages_tokens( $messages ) {
        $total = 0;
        foreach ( $messages as $msg ) {
            // Content tokens.
            $content = isset( $msg['content'] ) ? $msg['content'] : '';
            $total  += self::estimate_tokens( $content );

            // Tool calls in assistant messages can be large.
            if ( ! empty( $msg['tool_calls'] ) ) {
                $total += self::estimate_tokens( wp_json_encode( $msg['tool_calls'] ) );
            }

            // Per-message overhead (role, formatting tokens).
            $total += 4;
        }
        return $total;
    }

    /**
     * Truncate messages array to fit within a token limit.
     *
     * Strategy:
     * 1. Always keep the system message (index 0).
     * 2. Always keep the last N user/assistant messages (at least last 4 messages).
     * 3. Drop oldest assistant+tool message pairs from the middle.
     * 4. Insert a "[context truncated]" notice in the gap.
     *
     * @param array $messages   Full messages array.
     * @param int   $max_tokens Maximum tokens allowed for the conversation history.
     * @return array Truncated messages array that fits within the token budget.
     */
    public static function truncate( $messages, $max_tokens = 60000 ) {
        $current_tokens = self::count_messages_tokens( $messages );

        // If we're under the limit, no truncation needed.
        if ( $current_tokens <= $max_tokens ) {
            return $messages;
        }

        $count = count( $messages );
        if ( $count <= 3 ) {
            // Too few messages to truncate — return as-is.
            return $messages;
        }

        // Always keep: system (index 0) + at least the last 4 messages.
        $keep_tail = min( 4, $count - 1 );

        // Expand tail if the tail alone is under budget.
        $system_msg = $messages[0];
        $system_tokens = self::count_messages_tokens( array( $system_msg ) );

        // Try progressively including more tail messages.
        while ( $keep_tail < $count - 1 ) {
            $tail        = array_slice( $messages, $count - $keep_tail );
            $tail_tokens = self::count_messages_tokens( $tail );

            // Account for system message + truncation notice (~20 tokens).
            if ( $system_tokens + $tail_tokens + 20 > $max_tokens ) {
                break;
            }
            $keep_tail++;
        }

        // Build truncated array: system + notice + tail.
        $tail = array_slice( $messages, $count - $keep_tail );

        $dropped = $count - 1 - $keep_tail;
        $notice_msg = array(
            'role'    => 'system',
            'content' => "[Context truncated: {$dropped} earlier messages were removed to fit within the context window. " .
                         "The conversation continues from the most recent messages below.]",
        );

        return array_merge(
            array( $system_msg ),
            array( $notice_msg ),
            $tail
        );
    }

    /**
     * Get the context window token limit for a given model name.
     *
     * Falls back to 8000 for unknown models (safe default for local LLMs).
     *
     * @param string $model_name Model identifier string.
     * @return int Token limit.
     */
    public static function get_model_limit( $model_name ) {
        $limits = array(
            'gpt-4o'              => 128000,
            'gpt-4o-mini'         => 128000,
            'gpt-4-turbo'         => 128000,
            'gpt-4'               => 8192,
            'gpt-3.5-turbo'       => 16385,
            'claude-3-5-sonnet'   => 200000,
            'claude-3-5-haiku'    => 200000,
            'claude-3-haiku'      => 200000,
            'claude-3-opus'       => 200000,
            'claude-3-sonnet'     => 200000,
            'claude-4'            => 200000,
            'gemini-pro'          => 1048576,
            'gemini-1.5'          => 1048576,
            'gemini-2'            => 1048576,
            'llama3'              => 8192,
            'llama-3.1'           => 131072,
            'llama-3.2'           => 131072,
            'mistral'             => 32768,
            'mixtral'             => 32768,
            'qwen'                => 32768,
            'deepseek'            => 65536,
            'command-r'           => 128000,
        );

        $model_lower = strtolower( $model_name );

        foreach ( $limits as $name => $limit ) {
            if ( strpos( $model_lower, $name ) !== false ) {
                return $limit;
            }
        }

        return 8000; // Safe default for unknown/local models.
    }

    /**
     * Convenience: get the usable token budget for history,
     * after reserving space for the model's response.
     *
     * @param string $model_name    Model identifier.
     * @param int    $reserve_tokens Tokens to reserve for response (default 4000).
     * @return int Usable token budget for conversation history.
     */
    public static function get_history_budget( $model_name, $reserve_tokens = 4000 ) {
        return max( 2000, self::get_model_limit( $model_name ) - $reserve_tokens );
    }
}
