<?php
/**
 * BossCode AI Agent — Multi-Provider AI Client
 *
 * Handles communication with various LLM providers (OpenAI, Anthropic,
 * Ollama, LM Studio, Gemini Auto) and normalizes responses to a common format.
 * Supports both regular chat completions and tool/function calling.
 *
 * @package BossCode_AI_Agent
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_AI_Client {

    /**
     * Settings manager instance.
     *
     * @var BossCode_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @param BossCode_Settings $settings Settings manager.
     */
    public function __construct( BossCode_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Send a chat completion request to the configured LLM.
     *
     * @param array $messages  Array of message objects with 'role' and 'content'.
     * @param array $tools     Optional. Array of tool definitions (OpenAI format).
     * @param array $options   Optional. Override options (model, temperature, etc.).
     * @return array|WP_Error  Normalized response array or WP_Error on failure.
     */
    public function chat_completion( $messages, $tools = array(), $options = array() ) {
        $provider = $this->settings->get( 'provider' );

        // Gemini Auto (Puppeteer automation)
        if ( 'gemini_auto' === $provider ) {
            return $this->call_gemini_auto( $messages, $tools, $options );
        }

        // Anthropic uses a different API format
        if ( 'anthropic' === $provider ) {
            return $this->call_anthropic( $messages, $tools, $options );
        }

        // Groq uses OpenAI-compatible format but with specific optimizations
        if ( 'groq' === $provider ) {
            return $this->call_groq( $messages, $tools, $options );
        }

        // All other providers use OpenAI-compatible format
        return $this->call_openai_compatible( $messages, $tools, $options );
    }

    /**
     * Send an embedding request.
     *
     * @param string|array $input Text or array of texts to embed.
     * @return array|WP_Error Array of float vectors, or WP_Error on failure.
     */
    public function create_embedding( $input ) {
        $base_url = rtrim( $this->settings->get( 'base_url' ), '/' );
        $api_key  = $this->settings->get( 'api_key' );
        $model    = $this->settings->get( 'embedding_model' );

        $endpoint = $base_url . '/embeddings';

        $body = array(
            'model' => $model,
            'input' => $input,
        );

        $response = $this->make_request( $endpoint, $body, $api_key );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Extract embedding vectors
        if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $embeddings = array();
            foreach ( $response['data'] as $item ) {
                if ( isset( $item['embedding'] ) ) {
                    $embeddings[] = $item['embedding'];
                }
            }
            return $embeddings;
        }

        return new WP_Error(
            'parse_error',
            'Could not parse embedding response.',
            array( 'status' => 500 )
        );
    }

    /**
     * Call the Gemini Automation server (Puppeteer bridge).
     *
     * Routes the conversation through the local Node.js automation
     * server running on localhost:3200. This bypasses API costs entirely.
     *
     * @param array $messages Messages array.
     * @param array $tools    Tool definitions (limited support — see note).
     * @param array $options  Override options.
     * @return array|WP_Error Normalized response.
     */
    private function call_gemini_auto( $messages, $tools, $options ) {
        $gemini_url = rtrim( $this->settings->get( 'gemini_auto_url' ), '/' );

        // First, check if the automation server is running
        $health = $this->check_gemini_health( $gemini_url );
        if ( is_wp_error( $health ) ) {
            return $health;
        }

        // Build system prompt from system messages
        $system_prompt = '';
        $user_prompt   = '';
        $context       = '';

        foreach ( $messages as $msg ) {
            if ( 'system' === $msg['role'] ) {
                $system_prompt .= $msg['content'] . "\n";
            } elseif ( 'user' === $msg['role'] ) {
                $user_prompt = $msg['content'];
            } elseif ( 'assistant' === $msg['role'] ) {
                // Include recent conversation context
                $context .= "Previous AI response: " . substr( $msg['content'], 0, 500 ) . "\n\n";
            } elseif ( 'tool' === $msg['role'] ) {
                $context .= "Tool result: " . substr( $msg['content'], 0, 500 ) . "\n\n";
            }
        }

        // For tool-calling support with Gemini, embed tool schemas in the prompt
        if ( ! empty( $tools ) ) {
            $system_prompt .= "\n\n## Available Tools\n";
            $system_prompt .= "You MUST respond with a JSON object when you want to use a tool.\n";
            $system_prompt .= "Format: {\"tool_calls\": [{\"name\": \"tool_name\", \"arguments\": {...}}]}\n";
            $system_prompt .= "If you don't need any tools, respond normally with text.\n\n";

            foreach ( $tools as $tool ) {
                if ( isset( $tool['function'] ) ) {
                    $system_prompt .= sprintf(
                        "- **%s**: %s\n  Parameters: %s\n",
                        $tool['function']['name'],
                        $tool['function']['description'],
                        wp_json_encode( $tool['function']['parameters'] )
                    );
                }
            }
        }

        $full_prompt = $user_prompt;
        if ( ! empty( $context ) ) {
            $full_prompt = "Previous context:\n{$context}\n\nCurrent request:\n{$user_prompt}";
        }

        // Send to Gemini automation server
        $body = array(
            'prompt'        => $full_prompt,
            'systemPrompt'  => trim( $system_prompt ),
            'cleanForAgent' => true,
            'expectJSON'    => ! empty( $tools ),
            'expectCode'    => false,
        );

        // Add file context if present in options
        if ( ! empty( $options['file_context'] ) ) {
            $body['context'] = $options['file_context'];
        }

        $response = $this->make_request( $gemini_url . '/chat', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['success'] ) ) {
            return new WP_Error(
                'gemini_auto_error',
                'Gemini automation error: ' . ( $response['error'] ?? 'Unknown error' ),
                array( 'status' => 500 )
            );
        }

        $raw_text = $response['response'] ?? '';

        // First: try to extract tool calls directly from raw text
        // (handles JSON embedded in Understand/Plan/Execute prose)
        $tool_calls_raw = $this->extract_tool_calls_from_text( $raw_text );
        if ( ! empty( $tool_calls_raw ) ) {
            return array(
                'content'       => '',
                'finish_reason' => 'tool_calls',
                'tool_calls'    => $tool_calls_raw,
                'usage'         => array(),
            );
        }

        // Clean the response for agent use
        $cleaned = $this->clean_gemini_response( $raw_text );

        // Try to parse tool calls from the cleaned response
        $tool_calls = $this->extract_tool_calls_from_text( $cleaned );

        if ( ! empty( $tool_calls ) ) {
            return array(
                'content'       => '',
                'finish_reason' => 'tool_calls',
                'tool_calls'    => $tool_calls,
                'usage'         => array(),
            );
        }

        // If response looks like structured agentic prose but no tool call found,
        // try to synthesize a tool call from the plan described in the text.
        if ( $this->is_agentic_prose( $raw_text ) ) {
            $synthesized = $this->synthesize_tool_call_from_prose( $raw_text );
            if ( ! empty( $synthesized ) ) {
                return array(
                    'content'       => '',
                    'finish_reason' => 'tool_calls',
                    'tool_calls'    => $synthesized,
                    'usage'         => array(),
                );
            }
        }

        return array(
            'content'       => $cleaned,
            'finish_reason' => 'stop',
            'tool_calls'    => array(),
            'usage'         => array(),
        );
    }

    /**
     * Check Gemini automation server health.
     *
     * @param string $base_url The automation server URL.
     * @return true|WP_Error True if healthy, WP_Error otherwise.
     */
    private function check_gemini_health( $base_url ) {
        add_filter( 'http_request_args', array( $this, 'allow_local_requests' ), 10, 2 );

        $response = wp_remote_get( $base_url . '/health', array(
            'timeout'   => 5,
            'sslverify' => false,
        ) );

        remove_filter( 'http_request_args', array( $this, 'allow_local_requests' ), 10 );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'gemini_offline',
                'Gemini automation server is not running. Start it with: cd gemini-automation && node server.js',
                array( 'status' => 503 )
            );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $data || $data['status'] === 'offline' ) {
            return new WP_Error(
                'gemini_not_ready',
                'Gemini automation server is not ready: ' . ( $data['message'] ?? 'Unknown' ),
                array( 'status' => 503 )
            );
        }

        return true;
    }

    /**
     * Clean Gemini browser response for agent consumption.
     *
     * Strips conversational fluff, preamble, postamble, and
     * normalizes the response for tool call parsing.
     *
     * @param string $text Raw response text.
     * @return string Cleaned text.
     */
    private function clean_gemini_response( $text ) {
        if ( empty( $text ) ) {
            return '';
        }

        // Strip common conversational preamble
        $preamble_patterns = array(
            '/^(sure|okay|of course|certainly|absolutely|great|no problem|happy to help)[,!.\s]*/i',
            "/^(here[']?s?|here is|here are|i[']?ve|i have|i will|let me|allow me)[^.]*[.:]\s*/i",
            "/^(below is|the following|this is|i[']?ll|i can|i would)[^.]*[.:]\s*/i",
            '/^(alright|right|so|well|now)[,!.\s]+/i',
            '/^(as requested|as you asked|based on your request)[^.]*[.:]\s*/i',
            '/^(i understand|got it|understood)[^.]*[.:]\s*/i',
        );

        foreach ( $preamble_patterns as $pattern ) {
            $text = preg_replace( $pattern, '', $text );
            $text = trim( $text );
        }

        // Strip conversational postamble
        $postamble_patterns = array(
            "/\n*(let me know|feel free|hope this helps|if you have|if you need|don[']?t hesitate)[^]*$/i",
            "/\n*(is there anything|would you like|do you want|shall i|want me to)[^]*$/i",
            "/\n*(please let|happy to|glad to|i[']?m here)[^]*$/i",
        );

        foreach ( $postamble_patterns as $pattern ) {
            $text = preg_replace( $pattern, '', $text );
            $text = trim( $text );
        }

        // Normalize whitespace
        $text = preg_replace( '/\n{4,}/', "\n\n\n", $text );
        $text = preg_replace( '/[ \t]+$/m', '', $text );

        return trim( $text );
    }

    /**
     * Detect if a Gemini response is structured agentic prose
     * (Understand/Plan/Execute sections) rather than bare JSON.
     *
     * @param string $text Response text.
     * @return bool
     */
    private function is_agentic_prose( $text ) {
        $patterns = array(
            '/###\s*\d+\.\s*(understand|plan|execute|read|write|verify)/i',
            '/^#+\s*(understand|plan|execute|think|action|step\s+\d)/im',
            '/\*\*(understand|plan|step\s+\d|action|execute)\*\*/i',
            '/^\d+\.\s+\*\*(understand|plan|execute)/im',
        );
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $text ) ) return true;
        }
        return false;
    }

    /**
     * Try to synthesize a tool call from agentic prose.
     * Looks for patterns like:
     *   "```json\n{\"tool_calls\":[...]}"`
     *   or a plan that mentions list_directory/read_file with a path.
     *
     * @param string $text Response text.
     * @return array Array of tool call objects, or empty array.
     */
    private function synthesize_tool_call_from_prose( $text ) {
        // Attempt 1: Extract any JSON code block (may contain the tool call)
        if ( preg_match( '/```(?:json)?\s*\n?([\s\S]*?)```/', $text, $match ) ) {
            $json_candidate = trim( $match[1] );
            $data = json_decode( $json_candidate, true );
            if ( $data && isset( $data['tool_calls'] ) && is_array( $data['tool_calls'] ) ) {
                return $this->normalize_tool_calls( $data['tool_calls'] );
            }
            // Might be a bare tool call format like {"name":"list_directory","arguments":{}}
            if ( $data && isset( $data['name'] ) ) {
                return array( array(
                    'id'        => wp_generate_uuid4(),
                    'name'      => $data['name'],
                    'arguments' => $data['arguments'] ?? array(),
                ) );
            }
        }

        // Attempt 2: Look for list_directory tool intention
        if ( preg_match( '/list_directory.*?[\'"`]([^\'"` ]+)[\'"`]/i', $text, $m ) ) {
            return array( array(
                'id'        => wp_generate_uuid4(),
                'name'      => 'list_directory',
                'arguments' => array( 'path' => $m[1] ),
            ) );
        }

        // Attempt 3: Look for read_file tool intention
        if ( preg_match( '/read_file.*?[\'"`]([^\'"` ]+)[\'"`]/i', $text, $m ) ) {
            return array( array(
                'id'        => wp_generate_uuid4(),
                'name'      => 'read_file',
                'arguments' => array( 'path' => $m[1] ),
            ) );
        }

        return array();
    }

    /**
     * Normalize raw tool_calls array from parsed JSON.
     *
     * @param array $raw_calls
     * @return array
     */
    private function normalize_tool_calls( $raw_calls ) {
        $calls = array();
        foreach ( $raw_calls as $tc ) {
            if ( isset( $tc['name'] ) ) {
                $calls[] = array(
                    'id'        => wp_generate_uuid4(),
                    'name'      => $tc['name'],
                    'arguments' => isset( $tc['arguments'] ) ? $tc['arguments'] : array(),
                );
            }
        }
        return $calls;
    }

    /**
     * Extract tool calls from Gemini text response.
     *
     * Handles all patterns:
     * 1. Clean JSON with tool_calls key
     * 2. JSON inside a code fence
     * 3. JSON buried in agentic prose (Understand/Plan/Execute)
     * 4. Balanced JSON extraction for malformed responses
     *
     * @param string $text Response text.
     * @return array Array of tool call objects, or empty array.
     */
    private function extract_tool_calls_from_text( $text ) {
        // Pattern 1: Fenced JSON with tool_calls
        if ( preg_match( '/```(?:json)?\s*\n?([\s\S]*?)```/', $text, $match ) ) {
            $data = json_decode( trim( $match[1] ), true );
            if ( $data && isset( $data['tool_calls'] ) && is_array( $data['tool_calls'] ) ) {
                $calls = $this->normalize_tool_calls( $data['tool_calls'] );
                if ( ! empty( $calls ) ) return $calls;
            }
        }

        // Pattern 2: Balanced JSON object containing tool_calls
        $balanced = $this->extract_balanced_json( $text );
        if ( $balanced ) {
            $data = json_decode( $balanced, true );
            if ( $data && isset( $data['tool_calls'] ) && is_array( $data['tool_calls'] ) ) {
                $calls = $this->normalize_tool_calls( $data['tool_calls'] );
                if ( ! empty( $calls ) ) return $calls;
            }
        }

        // Pattern 3: Any JSON substring with tool_calls
        if ( preg_match( '/(\{[\s\S]*?"tool_calls"[\s\S]*?\})/', $text, $match ) ) {
            $data = json_decode( $match[1], true );
            if ( $data && isset( $data['tool_calls'] ) && is_array( $data['tool_calls'] ) ) {
                $calls = $this->normalize_tool_calls( $data['tool_calls'] );
                if ( ! empty( $calls ) ) return $calls;
            }
        }

        return array();
    }

    /**
     * Extract the first balanced JSON object from text,
     * handling nested braces correctly.
     *
     * @param string $text
     * @return string|null
     */
    private function extract_balanced_json( $text ) {
        // Find the position of the first '{' that precedes 'tool_calls'
        $tool_pos = strpos( $text, '"tool_calls"' );
        if ( false === $tool_pos ) return null;

        // Search backward for the opening brace
        $start = false;
        for ( $i = $tool_pos; $i >= 0; $i-- ) {
            if ( $text[ $i ] === '{' ) { $start = $i; break; }
        }
        if ( false === $start ) return null;

        // Now walk forward counting braces
        $depth     = 0;
        $in_string = false;
        $escape    = false;
        $len       = strlen( $text );

        for ( $i = $start; $i < $len; $i++ ) {
            $ch = $text[ $i ];
            if ( $escape ) { $escape = false; continue; }
            if ( $ch === '\\' && $in_string ) { $escape = true; continue; }
            if ( $ch === '"' ) { $in_string = ! $in_string; continue; }
            if ( $in_string ) continue;
            if ( $ch === '{' || $ch === '[' ) $depth++;
            elseif ( $ch === '}' || $ch === ']' ) {
                $depth--;
                if ( $depth === 0 ) return substr( $text, $start, $i - $start + 1 );
            }
        }
        return null;
    }

    /**
     * Call an OpenAI-compatible endpoint.
     *
     * Works with OpenAI, Ollama, LM Studio, and any OpenAI-compatible API.
     *
     * @param array $messages Messages array.
     * @param array $tools    Tool definitions.
     * @param array $options  Override options.
     * @return array|WP_Error Normalized response.
     */
    private function call_openai_compatible( $messages, $tools, $options ) {
        $base_url = rtrim( $this->settings->get( 'base_url' ), '/' );
        $api_key  = $this->settings->get( 'api_key' );
        $model    = isset( $options['model'] ) ? $options['model'] : $this->settings->get( 'model' );

        $endpoint = $base_url . '/chat/completions';

        $body = array(
            'model'    => $model,
            'messages' => $messages,
        );

        // Only include tools if provided (some local models don't support them)
        if ( ! empty( $tools ) ) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        // Apply optional overrides
        if ( isset( $options['temperature'] ) ) {
            $body['temperature'] = (float) $options['temperature'];
        }
        if ( isset( $options['max_tokens'] ) ) {
            $body['max_tokens'] = (int) $options['max_tokens'];
        }

        $response = $this->make_request( $endpoint, $body, $api_key );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize_openai_response( $response );
    }

    /**
     * Call GroqCloud API with optimized parameters.
     *
     * Groq is OpenAI-compatible but benefits from specific tuning:
     * - Lower temperature (0.4) for deterministic code generation
     * - max_completion_tokens instead of max_tokens
     * - top_p for nucleus sampling
     * - reasoning_effort for supported models
     *
     * @param array $messages Messages array.
     * @param array $tools    Tool definitions.
     * @param array $options  Override options.
     * @return array|WP_Error Normalized response.
     */
    private function call_groq( $messages, $tools, $options ) {
        $api_key = $this->settings->get( 'api_key' );
        $model   = isset( $options['model'] ) ? $options['model'] : $this->settings->get( 'model' );

        // Groq's API base URL
        $endpoint = 'https://api.groq.com/openai/v1/chat/completions';

        $body = array(
            'model'                 => $model,
            'messages'              => $messages,
            'temperature'           => isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.4,
            'max_completion_tokens' => isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 8192,
            'top_p'                 => 1,
            'stream'                => false, // Non-streaming for agentic loop
            'stop'                  => null,
        );

        // Add tool definitions if provided
        if ( ! empty( $tools ) ) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }

        $response = $this->make_request( $endpoint, $body, $api_key );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize_openai_response( $response );
    }

    /**
     * Call the Anthropic Messages API.
     *
     * Translates the OpenAI-format messages to Anthropic's format
     * and normalizes the response back.
     *
     * @param array $messages Messages array (OpenAI format).
     * @param array $tools    Tool definitions (OpenAI format).
     * @param array $options  Override options.
     * @return array|WP_Error Normalized response.
     */
    private function call_anthropic( $messages, $tools, $options ) {
        $api_key = $this->settings->get( 'api_key' );
        $model   = isset( $options['model'] ) ? $options['model'] : $this->settings->get( 'model' );

        $endpoint = 'https://api.anthropic.com/v1/messages';

        // Extract system message (Anthropic uses a separate 'system' param)
        $system_prompt = '';
        $user_messages = array();
        foreach ( $messages as $msg ) {
            if ( 'system' === $msg['role'] ) {
                $system_prompt .= $msg['content'] . "\n";
            } else {
                $user_messages[] = $msg;
            }
        }

        $body = array(
            'model'      => $model,
            'max_tokens' => isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 4096,
            'messages'   => $user_messages,
        );

        if ( ! empty( $system_prompt ) ) {
            $body['system'] = trim( $system_prompt );
        }

        // Convert tool definitions from OpenAI format to Anthropic format
        if ( ! empty( $tools ) ) {
            $body['tools'] = $this->convert_tools_to_anthropic( $tools );
        }

        // Anthropic uses a different auth header
        $headers = array(
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        );

        $response = $this->make_request( $endpoint, $body, null, $headers );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize_anthropic_response( $response );
    }

    /**
     * Make an HTTP POST request to an LLM endpoint.
     *
     * @param string      $endpoint The full URL to call.
     * @param array       $body     The request body (will be JSON-encoded).
     * @param string|null $api_key  Optional. API key for Bearer auth.
     * @param callable|null $stream_callback Optional. Function to call for streaming.
     * @return array|WP_Error Decoded JSON response or WP_Error.
     */
    private function make_request( $endpoint, $body, $api_key = null, $headers = null, $stream_callback = null ) {
        if ( null === $headers ) {
            $headers = array( 'Content-Type' => 'application/json' );
            if ( ! empty( $api_key ) ) {
                $headers['Authorization'] = 'Bearer ' . $api_key;
            }
        }

        $do_request = function() use ( $endpoint, $headers, $body, $stream_callback ) {
            if ( $stream_callback && is_callable( $stream_callback ) ) {
                $body['stream'] = true;
                return $this->execute_streaming_request( $endpoint, $headers, $body, $stream_callback );
            }

            // Allow local requests (Ollama, LM Studio, Gemini Auto) by temporarily disabling SSRF protection
            add_filter( 'http_request_args', array( $this, 'allow_local_requests' ), 10, 2 );

            $response = wp_remote_post( $endpoint, array(
                'headers' => $headers,
                'body'    => wp_json_encode( $body ),
                'timeout' => 180, // Increased for Gemini automation
            ) );

            remove_filter( 'http_request_args', array( $this, 'allow_local_requests' ), 10 );

            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    'api_connection_error',
                    'Failed to connect to AI API: ' . $response->get_error_message(),
                    array( 'status' => 500 )
                );
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $raw_body    = wp_remote_retrieve_body( $response );
            $data        = json_decode( $raw_body, true );

            if ( $status_code < 200 || $status_code >= 300 ) {
                $error_msg = 'Unknown API error (HTTP ' . $status_code . ')';

                if ( isset( $data['error']['message'] ) ) {
                    $error_msg = $data['error']['message'];
                } elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
                    $error_msg = $data['error'];
                }

                return new WP_Error(
                    'api_error',
                    'LLM API Error: ' . $error_msg,
                    array( 'status' => $status_code )
                );
            }

            if ( null === $data ) {
                return new WP_Error(
                    'parse_error',
                    'Could not parse JSON response from AI endpoint.',
                    array( 'status' => 500 )
                );
            }

            return $data;
        };

        $bootstrap = BossCode_Bootstrap::get_instance();
        $error_handler = $bootstrap->get_service( 'error_handler' );

        if ( $error_handler ) {
            return $error_handler->with_retry( $do_request );
        }

        return $do_request();
    }

    /**
     * Executes a streaming request using cURL.
     *
     * @param string   $endpoint
     * @param array    $headers
     * @param array    $body
     * @param callable $stream_callback
     * @return array|WP_Error
     */
    private function execute_streaming_request( $endpoint, $headers, $body, $stream_callback ) {
        if ( ! function_exists( 'curl_init' ) ) {
            return new WP_Error( 'curl_missing', 'cURL is required for true token streaming.' );
        }

        $ch = curl_init( $endpoint );

        $curl_headers = array();
        foreach ( $headers as $k => $v ) {
            $curl_headers[] = $k . ': ' . $v;
        }

        $buffer = '';

        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );

        // Local development support (allow insecure requests to localhost/internal)
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );

        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $ch, $data ) use ( $stream_callback, &$buffer ) {
            $buffer .= $data;

            // Process line by line
            while ( ( $pos = strpos( $buffer, "\n" ) ) !== false ) {
                $line = substr( $buffer, 0, $pos );
                $buffer = substr( $buffer, $pos + 1 );

                $line = trim( $line );
                if ( empty( $line ) ) {
                    continue;
                }

                if ( strpos( $line, 'data: ' ) === 0 ) {
                    $json_str = substr( $line, 6 );
                    if ( $json_str === '[DONE]' ) {
                        continue;
                    }
                    $chunk = json_decode( $json_str, true );
                    if ( $chunk ) {
                        call_user_func( $stream_callback, $chunk );
                    }
                }
            }

            return strlen( $data );
        } );

        curl_exec( $ch );

        if ( curl_errno( $ch ) ) {
            $error = curl_error( $ch );
            curl_close( $ch );
            return new WP_Error( 'curl_error', 'Streaming error: ' . $error );
        }

        curl_close( $ch );

        // When streaming completes, return a dummy success (the actual content was streamed)
        return array( 'success' => true, 'streamed' => true );
    }

    /**
     * Normalize an OpenAI-compatible API response to our internal format.
     *
     * @param array $response Raw decoded response from the API.
     * @return array Normalized response.
     */
    private function normalize_openai_response( $response ) {
        $choice = isset( $response['choices'][0] ) ? $response['choices'][0] : array();

        $result = array(
            'content'       => isset( $choice['message']['content'] ) ? $choice['message']['content'] : '',
            'finish_reason' => isset( $choice['finish_reason'] ) ? $choice['finish_reason'] : 'stop',
            'tool_calls'    => array(),
            'usage'         => isset( $response['usage'] ) ? $response['usage'] : array(),
        );

        // Extract tool calls if present
        if ( isset( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] ) ) {
            $result['finish_reason'] = 'tool_calls';
            foreach ( $choice['message']['tool_calls'] as $tc ) {
                $result['tool_calls'][] = array(
                    'id'        => isset( $tc['id'] ) ? $tc['id'] : wp_generate_uuid4(),
                    'name'      => $tc['function']['name'],
                    'arguments' => json_decode( $tc['function']['arguments'], true ),
                );
            }
        }

        return $result;
    }

    /**
     * Normalize an Anthropic Messages API response to our internal format.
     *
     * @param array $response Raw decoded response from Anthropic.
     * @return array Normalized response.
     */
    private function normalize_anthropic_response( $response ) {
        $result = array(
            'content'       => '',
            'finish_reason' => isset( $response['stop_reason'] ) ? $response['stop_reason'] : 'stop',
            'tool_calls'    => array(),
            'usage'         => isset( $response['usage'] ) ? $response['usage'] : array(),
        );

        // Anthropic returns an array of content blocks
        if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
            foreach ( $response['content'] as $block ) {
                if ( 'text' === $block['type'] ) {
                    $result['content'] .= $block['text'];
                } elseif ( 'tool_use' === $block['type'] ) {
                    $result['finish_reason'] = 'tool_calls';
                    $result['tool_calls'][] = array(
                        'id'        => $block['id'],
                        'name'      => $block['name'],
                        'arguments' => $block['input'],
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Convert OpenAI-format tool definitions to Anthropic format.
     *
     * @param array $openai_tools Array of OpenAI tool definitions.
     * @return array Anthropic-format tool definitions.
     */
    private function convert_tools_to_anthropic( $openai_tools ) {
        $anthropic_tools = array();
        foreach ( $openai_tools as $tool ) {
            if ( isset( $tool['function'] ) ) {
                $anthropic_tools[] = array(
                    'name'         => $tool['function']['name'],
                    'description'  => $tool['function']['description'],
                    'input_schema' => $tool['function']['parameters'],
                );
            }
        }
        return $anthropic_tools;
    }

    /**
     * Filter callback to allow requests to localhost/local IPs.
     *
     * Required for connecting to Ollama/LM Studio on the same machine.
     *
     * @param array  $args HTTP request arguments.
     * @param string $url  The request URL.
     * @return array Modified arguments.
     */
    public function allow_local_requests( $args, $url ) {
        $args['reject_unsafe_urls'] = false;
        return $args;
    }
}
