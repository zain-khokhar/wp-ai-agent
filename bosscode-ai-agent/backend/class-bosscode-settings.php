<?php
/**
 * BossCode AI Agent — Settings Manager
 *
 * Centralized CRUD for all plugin settings. Handles encryption
 * for sensitive values (API keys) and provides typed getters
 * with defaults for every setting.
 *
 * @package BossCode_AI_Agent
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Settings {

    /**
     * Security module instance for encryption/decryption.
     *
     * @var BossCode_Security
     */
    private $security;

    /**
     * Default values for all plugin settings.
     *
     * @var array
     */
    private $defaults = array(
        'bosscode_ai_provider'            => 'openai_compatible',
        'bosscode_ai_base_url'            => 'http://localhost:11434/v1',
        'bosscode_ai_api_key'             => '',
        'bosscode_ai_model'               => 'local-model',
        'bosscode_ai_max_loop_iterations' => 15,
        'bosscode_ai_embedding_model'     => 'text-embedding-3-small',
        'bosscode_ai_allowed_paths'       => array(),
        'bosscode_ai_gemini_auto_url'     => 'http://localhost:3200',
        'bosscode_ai_gemini_auto_enabled' => false,
    );

    /**
     * Keys that contain sensitive data and must be encrypted at rest.
     *
     * @var array
     */
    private $encrypted_keys = array(
        'bosscode_ai_api_key',
    );

    /**
     * Constructor.
     *
     * @param BossCode_Security $security Security module instance.
     */
    public function __construct( BossCode_Security $security ) {
        $this->security = $security;
    }

    /**
     * Get a setting value by key.
     *
     * Automatically decrypts encrypted settings. Returns the default
     * value if the setting doesn't exist.
     *
     * @param string $key     The option key (without or with 'bosscode_ai_' prefix).
     * @param mixed  $default Optional. Override the default value.
     * @return mixed The setting value.
     */
    public function get( $key, $default = null ) {
        // Normalize key — allow shorthand without prefix
        $full_key = $this->normalize_key( $key );

        // Determine default
        if ( null === $default && isset( $this->defaults[ $full_key ] ) ) {
            $default = $this->defaults[ $full_key ];
        }

        $value = get_option( $full_key, $default );

        // Decrypt if this is an encrypted key
        if ( in_array( $full_key, $this->encrypted_keys, true ) && ! empty( $value ) ) {
            $decrypted = $this->security->decrypt( $value );
            return ( false !== $decrypted ) ? $decrypted : $default;
        }

        return $value;
    }

    /**
     * Set a setting value.
     *
     * Automatically encrypts sensitive settings before storage.
     *
     * @param string $key   The option key.
     * @param mixed  $value The value to store.
     * @return bool True if the option was updated successfully.
     */
    public function set( $key, $value ) {
        $full_key = $this->normalize_key( $key );

        // Encrypt if this is a sensitive key
        if ( in_array( $full_key, $this->encrypted_keys, true ) && ! empty( $value ) ) {
            $value = $this->security->encrypt( $value );
            if ( false === $value ) {
                return false;
            }
        }

        return update_option( $full_key, $value );
    }

    /**
     * Get all settings as an associative array.
     *
     * Sensitive values are masked for safe frontend display.
     * This is what the GET /settings endpoint returns.
     *
     * @return array All settings with masked sensitive values.
     */
    public function get_all_masked() {
        $settings = array();

        foreach ( $this->defaults as $key => $default ) {
            $short_key = str_replace( 'bosscode_ai_', '', $key );

            if ( in_array( $key, $this->encrypted_keys, true ) ) {
                // Return masked version for display
                $raw = $this->get( $key );
                $settings[ $short_key ] = $this->security->mask_value( $raw );
                $settings[ $short_key . '_is_set' ] = ! empty( $raw );
            } else {
                $settings[ $short_key ] = $this->get( $key );
            }
        }

        return $settings;
    }

    /**
     * Bulk-update settings from a request payload.
     *
     * Sanitizes each value according to its type before saving.
     * If api_key is empty or masked (unchanged), the existing key is preserved.
     *
     * @param array $data Associative array of settings to update.
     * @return array Result with 'success' and 'message' keys.
     */
    public function update_from_request( $data ) {
        $updated = array();

        // Provider
        if ( isset( $data['provider'] ) ) {
            $allowed_providers = array( 'openai_compatible', 'openai', 'anthropic', 'groq', 'gemini_auto', 'custom' );
            $provider = sanitize_text_field( $data['provider'] );
            if ( in_array( $provider, $allowed_providers, true ) ) {
                $this->set( 'provider', $provider );
                $updated[] = 'provider';
            }
        }

        // Base URL
        if ( isset( $data['base_url'] ) ) {
            $base_url = esc_url_raw( $data['base_url'] );
            if ( ! empty( $base_url ) ) {
                $this->set( 'base_url', $base_url );
                $updated[] = 'base_url';
            }
        }

        // API Key — only update if a new non-masked value is provided
        if ( isset( $data['api_key'] ) ) {
            $api_key = sanitize_text_field( $data['api_key'] );
            // Skip if empty or looks like a masked value (all asterisks)
            if ( ! empty( $api_key ) && ! preg_match( '/^\*+/', $api_key ) ) {
                $this->set( 'api_key', $api_key );
                $updated[] = 'api_key';
            }
        }

        // Model name
        if ( isset( $data['model'] ) ) {
            $this->set( 'model', sanitize_text_field( $data['model'] ) );
            $updated[] = 'model';
        }

        // Max loop iterations
        if ( isset( $data['max_loop_iterations'] ) ) {
            $max = absint( $data['max_loop_iterations'] );
            $max = max( 1, min( 50, $max ) ); // Clamp between 1 and 50
            $this->set( 'max_loop_iterations', $max );
            $updated[] = 'max_loop_iterations';
        }

        // Embedding model
        if ( isset( $data['embedding_model'] ) ) {
            $this->set( 'embedding_model', sanitize_text_field( $data['embedding_model'] ) );
            $updated[] = 'embedding_model';
        }

        // Allowed paths
        if ( isset( $data['allowed_paths'] ) && is_array( $data['allowed_paths'] ) ) {
            $paths = array_map( 'sanitize_text_field', $data['allowed_paths'] );
            $paths = array_filter( $paths ); // Remove empties
            $this->set( 'allowed_paths', $paths );
            $updated[] = 'allowed_paths';
        }

        // Gemini Auto URL
        if ( isset( $data['gemini_auto_url'] ) ) {
            $url = esc_url_raw( $data['gemini_auto_url'] );
            if ( ! empty( $url ) ) {
                $this->set( 'gemini_auto_url', $url );
                $updated[] = 'gemini_auto_url';
            }
        }

        // Gemini Auto Enabled toggle
        if ( isset( $data['gemini_auto_enabled'] ) ) {
            $this->set( 'gemini_auto_enabled', (bool) $data['gemini_auto_enabled'] );
            $updated[] = 'gemini_auto_enabled';
        }

        return array(
            'success' => true,
            'message' => 'Settings saved successfully.',
            'updated' => $updated,
        );
    }

    /**
     * Normalize a setting key to its full option name.
     *
     * Allows using shorthand (e.g., 'base_url') or full key
     * (e.g., 'bosscode_ai_base_url').
     *
     * @param string $key The key to normalize.
     * @return string The full option key.
     */
    private function normalize_key( $key ) {
        if ( strpos( $key, 'bosscode_ai_' ) === 0 ) {
            return $key;
        }
        return 'bosscode_ai_' . $key;
    }
}
