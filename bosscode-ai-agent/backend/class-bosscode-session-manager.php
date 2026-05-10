<?php
/**
 * BossCode_Session_Manager — Conversation Persistence
 *
 * Stores and retrieves chat sessions and messages from the WordPress database.
 * Every user/assistant/tool message is persisted so that conversations survive
 * page reloads and can be resumed later.
 *
 * Tables: {prefix}bosscode_sessions, {prefix}bosscode_messages
 * Both created via dbDelta() in BossCode_Bootstrap::activate().
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Session_Manager {

    /**
     * Create a new session.
     *
     * @param int    $user_id WordPress user ID.
     * @param string $title   Optional session title.
     * @return array Session record: {session_uuid, user_id, title, created_at, updated_at}
     */
    public function create_session( $user_id, $title = '' ) {
        global $wpdb;

        $uuid = wp_generate_uuid4();
        if ( empty( $title ) ) {
            $title = 'New Session';
        }

        $wpdb->insert(
            $wpdb->prefix . 'bosscode_sessions',
            array(
                'session_uuid' => $uuid,
                'user_id'      => absint( $user_id ),
                'title'        => sanitize_text_field( $title ),
                'created_at'   => current_time( 'mysql', true ),
                'updated_at'   => current_time( 'mysql', true ),
            ),
            array( '%s', '%d', '%s', '%s', '%s' )
        );

        return $this->get_session( $uuid );
    }

    /**
     * Get a single session by UUID.
     *
     * @param string $uuid Session UUID.
     * @return array|false Session record or false if not found.
     */
    public function get_session( $uuid ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bosscode_sessions';
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE session_uuid = %s", $uuid ),
            ARRAY_A
        );

        return $row ?: false;
    }

    /**
     * List sessions for a user, most recent first.
     *
     * @param int $user_id WordPress user ID.
     * @param int $limit   Max sessions to return.
     * @return array Array of session records.
     */
    public function list_sessions( $user_id, $limit = 20 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bosscode_sessions';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",
                absint( $user_id ),
                absint( $limit )
            ),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Delete a session and all its messages.
     *
     * @param string $uuid Session UUID.
     * @return bool True on success.
     */
    public function delete_session( $uuid ) {
        global $wpdb;

        $uuid = sanitize_text_field( $uuid );

        // Delete messages first (FK-like cleanup).
        $wpdb->delete(
            $wpdb->prefix . 'bosscode_messages',
            array( 'session_uuid' => $uuid ),
            array( '%s' )
        );

        // Delete the session.
        $wpdb->delete(
            $wpdb->prefix . 'bosscode_sessions',
            array( 'session_uuid' => $uuid ),
            array( '%s' )
        );

        return true;
    }

    /**
     * Rename a session.
     *
     * @param string $uuid  Session UUID.
     * @param string $title New title.
     * @return bool True on success.
     */
    public function rename_session( $uuid, $title ) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'bosscode_sessions',
            array(
                'title'      => sanitize_text_field( $title ),
                'updated_at' => current_time( 'mysql', true ),
            ),
            array( 'session_uuid' => sanitize_text_field( $uuid ) ),
            array( '%s', '%s' ),
            array( '%s' )
        );

        return true;
    }

    /**
     * Touch the session's updated_at timestamp.
     *
     * @param string $uuid Session UUID.
     */
    public function touch_session( $uuid ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bosscode_sessions',
            array( 'updated_at' => current_time( 'mysql', true ) ),
            array( 'session_uuid' => sanitize_text_field( $uuid ) ),
            array( '%s' ),
            array( '%s' )
        );
    }

    // ─── Message Methods ─────────────────────────────────────────────

    /**
     * Add a message to a session.
     *
     * @param string $session_uuid Session UUID.
     * @param string $role         Message role: system, user, assistant, tool.
     * @param string $content      Message text content.
     * @param array  $meta         Optional metadata: tool_calls, tool_call_id, tool_name, iteration.
     * @return int|false Inserted message ID or false on failure.
     */
    public function add_message( $session_uuid, $role, $content, $meta = array() ) {
        global $wpdb;

        $data = array(
            'session_uuid' => sanitize_text_field( $session_uuid ),
            'role'         => sanitize_text_field( $role ),
            'content'      => $content, // Not sanitized — may contain code/HTML.
            'created_at'   => current_time( 'mysql', true ),
        );
        $format = array( '%s', '%s', '%s', '%s' );

        // Optional metadata fields.
        if ( ! empty( $meta['tool_calls'] ) ) {
            $data['tool_calls'] = is_string( $meta['tool_calls'] )
                ? $meta['tool_calls']
                : wp_json_encode( $meta['tool_calls'] );
            $format[] = '%s';
        }
        if ( ! empty( $meta['tool_call_id'] ) ) {
            $data['tool_call_id'] = sanitize_text_field( $meta['tool_call_id'] );
            $format[] = '%s';
        }
        if ( ! empty( $meta['tool_name'] ) ) {
            $data['tool_name'] = sanitize_text_field( $meta['tool_name'] );
            $format[] = '%s';
        }
        if ( isset( $meta['iteration'] ) ) {
            $data['iteration'] = absint( $meta['iteration'] );
            $format[] = '%d';
        }

        $result = $wpdb->insert( $wpdb->prefix . 'bosscode_messages', $data, $format );

        // Touch session's updated_at.
        $this->touch_session( $session_uuid );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get messages in LLM-compatible format (for continuing conversations).
     *
     * Returns messages with: role, content, and optionally tool_calls / tool_call_id
     * in the format expected by OpenAI / Anthropic APIs.
     *
     * @param string $session_uuid Session UUID.
     * @param int    $limit        Max messages to return.
     * @return array Array of message arrays in LLM format.
     */
    public function get_messages( $session_uuid, $limit = 100 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bosscode_messages';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE session_uuid = %s ORDER BY id ASC LIMIT %d",
                sanitize_text_field( $session_uuid ),
                absint( $limit )
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return array();
        }

        $messages = array();
        foreach ( $rows as $row ) {
            $msg = array(
                'role'    => $row['role'],
                'content' => $row['content'],
            );

            // Reconstruct tool_calls for assistant messages.
            if ( $row['role'] === 'assistant' && ! empty( $row['tool_calls'] ) ) {
                $decoded = json_decode( $row['tool_calls'], true );
                if ( $decoded ) {
                    $msg['tool_calls'] = $decoded;
                }
            }

            // Reconstruct tool_call_id for tool result messages.
            if ( $row['role'] === 'tool' && ! empty( $row['tool_call_id'] ) ) {
                $msg['tool_call_id'] = $row['tool_call_id'];
            }

            $messages[] = $msg;
        }

        return $messages;
    }

    /**
     * Get messages in a UI-friendly format (for display in the chat panel).
     *
     * Includes additional metadata like tool_name, iteration, and created_at.
     *
     * @param string $session_uuid Session UUID.
     * @return array Array of message arrays with display metadata.
     */
    public function get_messages_for_display( $session_uuid ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bosscode_messages';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE session_uuid = %s ORDER BY id ASC",
                sanitize_text_field( $session_uuid )
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return array();
        }

        $messages = array();
        foreach ( $rows as $row ) {
            $msg = array(
                'id'           => (int) $row['id'],
                'role'         => $row['role'],
                'content'      => $row['content'],
                'tool_name'    => $row['tool_name'] ?: null,
                'tool_call_id' => $row['tool_call_id'] ?: null,
                'iteration'    => $row['iteration'] ? (int) $row['iteration'] : null,
                'created_at'   => $row['created_at'],
            );

            if ( $row['role'] === 'assistant' && ! empty( $row['tool_calls'] ) ) {
                $msg['tool_calls'] = json_decode( $row['tool_calls'], true );
            }

            $messages[] = $msg;
        }

        return $messages;
    }

    /**
     * Get the message count for a session.
     *
     * @param string $session_uuid Session UUID.
     * @return int Message count.
     */
    public function get_message_count( $session_uuid ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bosscode_messages';
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_uuid = %s", $session_uuid )
        );
    }

    /**
     * Auto-generate a session title from the first user message.
     *
     * @param string $session_uuid Session UUID.
     * @return string Generated title.
     */
    public function auto_title( $session_uuid ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bosscode_messages';

        $first_user_msg = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT content FROM {$table} WHERE session_uuid = %s AND role = 'user' ORDER BY id ASC LIMIT 1",
                $session_uuid
            )
        );

        if ( ! $first_user_msg ) {
            return 'New Session';
        }

        // Truncate to 60 chars and clean up.
        $title = wp_trim_words( $first_user_msg, 8, '...' );
        $title = mb_substr( $title, 0, 60 );

        $this->rename_session( $session_uuid, $title );
        return $title;
    }
}
