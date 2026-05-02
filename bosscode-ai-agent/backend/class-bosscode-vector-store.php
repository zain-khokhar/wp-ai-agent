<?php
/**
 * BossCode AI Agent — Chunk Store (v2)
 *
 * Database layer for storing file chunks with keyword indices.
 * Repurposed from vector/embedding storage to lightweight
 * keyword-based storage. Embeddings column kept for backward
 * compatibility but is no longer populated.
 *
 * @package BossCode_AI_Agent
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Vector_Store {

    /**
     * Get the full table name with WP prefix.
     *
     * @return string
     */
    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'bosscode_embeddings';
    }

    /**
     * Insert or update a chunk with its keywords.
     *
     * @param array $data {
     *     @type string $file_path     Relative path from ABSPATH.
     *     @type int    $chunk_index   Index of chunk within the file.
     *     @type string $chunk_content The text content of the chunk.
     *     @type array  $embedding     Deprecated. Kept for compatibility.
     *     @type array  $keywords      Keyword => frequency map.
     *     @type int    $token_count   Approximate token count.
     *     @type string $file_hash     SHA-256 hash of the source file.
     * }
     * @return int|false The row ID on success, false on failure.
     */
    public function upsert( $data ) {
        global $wpdb;
        $table = $this->table_name();

        $file_path   = isset( $data['file_path'] ) ? $data['file_path'] : '';
        $chunk_index = isset( $data['chunk_index'] ) ? (int) $data['chunk_index'] : 0;

        // Check if this chunk already exists
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE file_path = %s AND chunk_index = %d",
            $file_path,
            $chunk_index
        ) );

        $row = array(
            'file_path'     => $file_path,
            'chunk_index'   => $chunk_index,
            'chunk_content' => isset( $data['chunk_content'] ) ? $data['chunk_content'] : '',
            'embedding'     => isset( $data['keywords'] )
                                ? wp_json_encode( $data['keywords'] )
                                : ( isset( $data['embedding'] ) ? wp_json_encode( $data['embedding'] ) : null ),
            'token_count'   => isset( $data['token_count'] ) ? (int) $data['token_count'] : 0,
            'file_hash'     => isset( $data['file_hash'] ) ? $data['file_hash'] : '',
            'updated_at'    => current_time( 'mysql', true ),
        );

        if ( $existing_id ) {
            $wpdb->update( $table, $row, array( 'id' => $existing_id ) );
            return (int) $existing_id;
        }

        $row['created_at'] = current_time( 'mysql', true );
        $result = $wpdb->insert( $table, $row );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get all chunks with their keyword data for search.
     *
     * @return array Array of chunk records with decoded keywords.
     */
    public function get_all_chunks() {
        global $wpdb;
        $table = $this->table_name();

        // Use cache for performance
        $cached = get_transient( 'bosscode_chunks_cache' );
        if ( false !== $cached ) {
            return $cached;
        }

        $results = $wpdb->get_results(
            "SELECT id, file_path, chunk_index, chunk_content, embedding as keywords, token_count
             FROM {$table}
             WHERE embedding IS NOT NULL
             ORDER BY file_path ASC, chunk_index ASC",
            ARRAY_A
        );

        if ( ! $results ) {
            return array();
        }

        // Decode keyword JSON
        foreach ( $results as &$row ) {
            $row['keywords'] = json_decode( $row['keywords'], true );
        }

        // Cache for 10 minutes
        set_transient( 'bosscode_chunks_cache', $results, 600 );

        return $results;
    }

    /**
     * Get all embeddings for backward compatibility.
     * Maps to get_all_chunks() internally.
     *
     * @param bool $use_cache Whether to use transient caching.
     * @return array Array of row objects.
     */
    public function get_all_embeddings( $use_cache = true ) {
        return $this->get_all_chunks();
    }

    /**
     * Delete all chunks for a specific file.
     *
     * @param string $file_path Relative path.
     * @return int Number of rows deleted.
     */
    public function delete_by_file( $file_path ) {
        global $wpdb;
        $table = $this->table_name();

        $deleted = $wpdb->delete( $table, array( 'file_path' => $file_path ) );

        // Invalidate cache
        delete_transient( 'bosscode_chunks_cache' );
        delete_transient( 'bosscode_embeddings_cache' );

        return $deleted ? $deleted : 0;
    }

    /**
     * Get the stored hash for a file.
     *
     * @param string $file_path Relative path.
     * @return string|null The stored hash, or null if not indexed.
     */
    public function get_file_hash( $file_path ) {
        global $wpdb;
        $table = $this->table_name();

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT file_hash FROM {$table} WHERE file_path = %s LIMIT 1",
            $file_path
        ) );
    }

    /**
     * Get all indexed file paths.
     *
     * @return array Array of file path strings.
     */
    public function get_indexed_files() {
        global $wpdb;
        $table = $this->table_name();

        return $wpdb->get_col(
            "SELECT DISTINCT file_path FROM {$table} ORDER BY file_path ASC"
        );
    }

    /**
     * Get total count of chunks in the store.
     *
     * @return int
     */
    public function get_chunk_count() {
        global $wpdb;
        $table = $this->table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Clear the entire store.
     *
     * @return bool True on success.
     */
    public function clear_all() {
        global $wpdb;
        $table = $this->table_name();
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        delete_transient( 'bosscode_chunks_cache' );
        delete_transient( 'bosscode_embeddings_cache' );
        return true;
    }

    /**
     * Check if the table exists.
     *
     * @return bool
     */
    public function table_exists() {
        global $wpdb;
        $table = $this->table_name();
        return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
    }
}
