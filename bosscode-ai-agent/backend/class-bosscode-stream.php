<?php
/**
 * BossCode AI Agent — SSE Stream Handler
 *
 * Manages Server-Sent Events (SSE) output for real-time streaming
 * of tokens, tool status, and agentic loop progress to the frontend.
 *
 * @package BossCode_AI_Agent
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Stream {

    /**
     * Whether SSE headers have been sent.
     *
     * @var bool
     */
    private $headers_sent = false;

    /**
     * Initialize SSE output.
     *
     * Sets appropriate headers and disables output buffering
     * for real-time streaming.
     */
    public function start() {
        if ( $this->headers_sent ) {
            return;
        }

        // Prevent PHP from timing out during long agentic loops
        if ( ! ini_get( 'safe_mode' ) ) {
            set_time_limit( 300 );
        }
        ignore_user_abort( true );

        // SSE headers
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' ); // Disable nginx buffering

        // Disable all output buffering layers
        while ( ob_get_level() > 0 ) {
            ob_end_flush();
        }

        $this->headers_sent = true;
    }

    /**
     * Send an SSE event.
     *
     * @param string $type Event type (used as data.type in JSON).
     * @param mixed  $data Event payload.
     */
    public function send( $type, $data = array() ) {
        if ( ! $this->headers_sent ) {
            $this->start();
        }

        $payload = array( 'type' => $type );

        if ( is_string( $data ) ) {
            $payload['content'] = $data;
        } elseif ( is_array( $data ) ) {
            $payload = array_merge( $payload, $data );
        }

        echo 'data: ' . wp_json_encode( $payload ) . "\n\n";

        if ( ob_get_level() > 0 ) {
            ob_flush();
        }
        flush();
    }

    /**
     * Send a token content event.
     *
     * @param string $content Token text.
     */
    public function send_token( $content ) {
        $this->send( 'token', array( 'content' => $content ) );
    }

    /**
     * Send a tool call event (before execution).
     *
     * @param string $name Tool name.
     * @param array  $args Tool arguments.
     */
    public function send_tool_call( $name, $args = array() ) {
        $this->send( 'tool_call', array(
            'name' => $name,
            'args' => $args,
        ) );
    }

    /**
     * Send a tool execution result event.
     *
     * @param string $name    Tool name.
     * @param bool   $success Whether the tool executed successfully.
     * @param string $result  Truncated result text.
     */
    public function send_tool_status( $name, $success, $result = '' ) {
        $this->send( 'tool_status', array(
            'name'    => $name,
            'success' => $success,
            'result'  => substr( $result, 0, 500 ),
        ) );
    }

    /**
     * Send an iteration progress event.
     *
     * @param int $current Current iteration number.
     * @param int $max     Maximum iterations allowed.
     */
    public function send_iteration( $current, $max ) {
        $this->send( 'iteration', array(
            'current' => $current,
            'max'     => $max,
        ) );
    }

    /**
     * Send a confirmation request for destructive tools.
     *
     * @param string $tool_call_id The tool call ID.
     * @param string $name         Tool name.
     * @param array  $args         Tool arguments.
     */
    public function send_confirm_required( $tool_call_id, $name, $args ) {
        $this->send( 'confirm_required', array(
            'tool_call_id' => $tool_call_id,
            'name'         => $name,
            'args'         => $args,
        ) );
    }

    /**
     * Send an error event.
     *
     * @param string $message Error message.
     */
    public function send_error( $message ) {
        $this->send( 'error', array( 'message' => $message ) );
    }

    /**
     * Send the final done event and close the stream.
     *
     * @param string $content Final response text.
     * @param array  $tool_log Array of tool execution logs.
     * @param int    $iterations Number of loop iterations.
     */
    public function send_done( $content, $tool_log = array(), $iterations = 0 ) {
        $this->send( 'done', array(
            'content'    => $content,
            'tool_log'   => $tool_log,
            'iterations' => $iterations,
        ) );
    }

    /**
     * Check if the client connection is still alive.
     *
     * @return bool
     */
    public function is_connected() {
        return ! connection_aborted();
    }
}
