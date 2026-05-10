<?php
/**
 * BossCode_Confirmation_Manager — Human-in-the-Loop Confirmation
 *
 * Manages pending confirmations for destructive tool calls (write_file,
 * delete_file, replace_in_file). When a destructive tool is called, instead
 * of executing immediately, a "confirm_required" SSE event is sent to the
 * frontend. The frontend displays a diff modal and the user approves or
 * rejects. The decision is communicated back via POST /confirm.
 *
 * Pending actions are stored in WordPress transients (keyed by tool_call_id)
 * with a 5-minute TTL.
 *
 * @package BossCode_AI_Agent
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Confirmation_Manager {

    const TTL = 300; // 5 minutes

    /**
     * Store a pending action for later confirmation.
     *
     * @param string $tool_call_id The LLM-generated tool call ID.
     * @param string $tool_name    Tool name (write_file, delete_file, etc.).
     * @param array  $args         Tool arguments.
     * @param string $session_uuid Associated session UUID.
     * @return bool True if stored successfully.
     */
    public function store_pending( $tool_call_id, $tool_name, $args, $session_uuid = '' ) {
        $key = $this->transient_key( $tool_call_id );

        $data = array(
            'tool_call_id' => $tool_call_id,
            'tool_name'    => $tool_name,
            'args'         => $args,
            'session_uuid' => $session_uuid,
            'user_id'      => get_current_user_id(),
            'stored_at'    => time(),
        );

        return set_transient( $key, $data, self::TTL );
    }

    /**
     * Retrieve a pending action.
     *
     * @param string $tool_call_id The tool call ID.
     * @return array|false Pending action data or false if not found/expired.
     */
    public function get_pending( $tool_call_id ) {
        $key  = $this->transient_key( $tool_call_id );
        $data = get_transient( $key );

        if ( ! $data || ! is_array( $data ) ) {
            return false;
        }

        // Ensure the current user owns this pending action.
        if ( isset( $data['user_id'] ) && $data['user_id'] !== get_current_user_id() ) {
            return false;
        }

        return $data;
    }

    /**
     * Consume (delete) a pending action after it's been approved or rejected.
     *
     * @param string $tool_call_id The tool call ID.
     * @return bool True if deleted.
     */
    public function consume( $tool_call_id ) {
        return delete_transient( $this->transient_key( $tool_call_id ) );
    }

    /**
     * Check if a tool requires human confirmation.
     *
     * @param string $tool_name Tool name.
     * @param BossCode_Tools $tools Tools registry instance.
     * @return bool Whether confirmation is required.
     */
    public function requires_confirmation( $tool_name, $tools = null ) {
        if ( $tools && method_exists( $tools, 'is_destructive' ) ) {
            return $tools->is_destructive( $tool_name );
        }

        // Fallback hardcoded list.
        $destructive = array( 'write_file', 'delete_file', 'replace_in_file' );
        return in_array( $tool_name, $destructive, true );
    }

    /**
     * Build the transient key for a tool_call_id.
     *
     * @param string $tool_call_id The tool call ID.
     * @return string Transient key.
     */
    private function transient_key( $tool_call_id ) {
        // Sanitize to safe key characters.
        return 'bosscode_confirm_' . md5( $tool_call_id );
    }
}
