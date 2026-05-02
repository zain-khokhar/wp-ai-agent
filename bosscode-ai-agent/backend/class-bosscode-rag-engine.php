<?php
/**
 * BossCode AI Agent — RAG Engine (v2 — Keyword-Based)
 *
 * Context retrieval engine using lightweight keyword/TF-IDF search
 * instead of expensive AI-powered embeddings. Handles file chunking,
 * keyword extraction, and context injection into the system prompt.
 *
 * Architecture change: No AI API calls during indexing. All indexing
 * is done locally using PHP text processing.
 *
 * @package BossCode_AI_Agent
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_RAG_Engine {

    /** @var BossCode_Search_Index */
    private $search_index;

    /** @var BossCode_Vector_Store */
    private $store;

    /** @var BossCode_Settings */
    private $settings;

    /**
     * Maximum tokens per chunk.
     */
    const MAX_CHUNK_TOKENS = 800;

    /**
     * Overlap tokens between sliding window chunks.
     */
    const OVERLAP_TOKENS = 100;

    /**
     * File extensions to index.
     */
    const INDEXABLE_EXTENSIONS = array( 'php', 'js', 'css', 'html', 'json', 'txt', 'jsx', 'ts', 'tsx', 'scss' );

    /**
     * Directories to skip during scanning.
     */
    const SKIP_DIRS = array( 'node_modules', '.git', 'vendor', 'bosscode-backups', 'cache', '.svn', 'dist', 'build' );

    /**
     * Constructor.
     *
     * Note: No longer requires BossCode_AI_Client — indexing is fully local.
     *
     * @param BossCode_Search_Index $search_index Keyword search engine.
     * @param BossCode_Vector_Store $store        Chunk storage.
     * @param BossCode_Settings     $settings     Plugin settings.
     */
    public function __construct(
        BossCode_Search_Index $search_index,
        BossCode_Vector_Store $store,
        BossCode_Settings $settings
    ) {
        $this->search_index = $search_index;
        $this->store        = $store;
        $this->settings     = $settings;
    }

    // ─── Indexing ───────────────────────────────────────────

    /**
     * Index all files in allowed directories.
     *
     * Scans files, chunks them, extracts keywords, and stores them.
     * NO AI API calls — all processing is local and instant.
     *
     * @param callable|null $progress_callback Called with (current, total, file_path).
     * @return array Summary of indexing results.
     */
    public function index_all( $progress_callback = null ) {
        $allowed_paths = $this->settings->get( 'allowed_paths' );
        if ( ! is_array( $allowed_paths ) || empty( $allowed_paths ) ) {
            $allowed_paths = $this->get_default_paths();
        }

        // Scan for files
        $files = array();
        foreach ( $allowed_paths as $dir ) {
            if ( is_dir( $dir ) ) {
                $this->scan_directory( $dir, $files );
            }
        }

        $total        = count( $files );
        $indexed      = 0;
        $skipped      = 0;
        $errors       = 0;
        $chunks_added = 0;

        foreach ( $files as $i => $file_path ) {
            $current_hash = hash_file( 'sha256', $file_path );
            $relative     = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file_path ) );

            // Skip unchanged files
            $stored_hash = $this->store->get_file_hash( $relative );
            if ( $stored_hash === $current_hash ) {
                $skipped++;
                if ( $progress_callback ) {
                    call_user_func( $progress_callback, $i + 1, $total, $relative . ' (skipped)' );
                }
                continue;
            }

            // Re-index changed file
            $this->store->delete_by_file( $relative );

            $content = file_get_contents( $file_path );
            if ( false === $content ) {
                $errors++;
                continue;
            }

            $extension = pathinfo( $file_path, PATHINFO_EXTENSION );
            $chunks    = $this->chunk_file( $content, $extension, $relative );

            if ( empty( $chunks ) ) {
                $skipped++;
                continue;
            }

            // Store each chunk with keywords (NO AI embedding call!)
            foreach ( $chunks as $ci => $chunk ) {
                $keywords = $this->search_index->extract_keywords( $chunk['content'], $extension );

                $this->store->upsert( array(
                    'file_path'     => $relative,
                    'chunk_index'   => $ci,
                    'chunk_content' => $chunk['content'],
                    'embedding'     => null,  // No embedding needed
                    'keywords'      => $keywords,
                    'token_count'   => $chunk['token_count'],
                    'file_hash'     => $current_hash,
                ) );

                $chunks_added++;
            }

            $indexed++;

            if ( $progress_callback ) {
                call_user_func( $progress_callback, $i + 1, $total, $relative );
            }
        }

        return array(
            'total_files'  => $total,
            'indexed'      => $indexed,
            'skipped'      => $skipped,
            'errors'       => $errors,
            'chunks_added' => $chunks_added,
        );
    }

    // ─── Context Search ────────────────────────────────────

    /**
     * Search for the most relevant chunks to a query.
     *
     * Uses keyword/TF-IDF matching instead of cosine similarity.
     *
     * @param string $query  The user's query.
     * @param int    $top_k  Number of results.
     * @return array Matching chunks with relevance scores.
     */
    public function search( $query, $top_k = 5 ) {
        $all_chunks = $this->store->get_all_chunks();
        if ( empty( $all_chunks ) ) {
            return array();
        }

        return $this->search_index->search( $query, $all_chunks, $top_k );
    }

    /**
     * Build a context block from search results.
     *
     * @param string $query The user's query.
     * @param int    $top_k Number of chunks.
     * @return string Formatted context block.
     */
    public function build_context( $query, $top_k = 5 ) {
        $results = $this->search( $query, $top_k );

        if ( empty( $results ) ) {
            return '';
        }

        $context = "\n\n<Relevant_Code_Context>\n";
        foreach ( $results as $r ) {
            $context .= sprintf(
                "\n<!-- File: %s | Chunk: %d | Relevance: %.2f -->\n<file_content path=\"%s\">\n%s\n</file_content>\n",
                $r['file_path'],
                $r['chunk_index'],
                $r['score'],
                $r['file_path'],
                $r['chunk_content']
            );
        }
        $context .= "\n</Relevant_Code_Context>\n";

        return $context;
    }

    /**
     * Build context from specific file paths (for file attachments).
     *
     * @param array $file_paths Array of relative file paths.
     * @param int   $max_tokens Maximum total tokens to include.
     * @return string Formatted context block.
     */
    public function build_file_context( $file_paths, $max_tokens = 8000 ) {
        if ( empty( $file_paths ) ) {
            return '';
        }

        $context     = "\n\n<Attached_Files>\n";
        $total_tokens = 0;

        foreach ( $file_paths as $rel_path ) {
            $abs_path = ABSPATH . ltrim( $rel_path, '/' );
            if ( ! file_exists( $abs_path ) || ! is_file( $abs_path ) ) {
                continue;
            }

            $size = filesize( $abs_path );
            if ( $size > 512000 ) {
                $context .= sprintf( "\n<!-- File: %s (skipped — too large: %s) -->\n", $rel_path, size_format( $size ) );
                continue;
            }

            $content = file_get_contents( $abs_path );
            if ( false === $content ) {
                continue;
            }

            $tokens = $this->estimate_tokens( $content );
            if ( $total_tokens + $tokens > $max_tokens ) {
                // Truncate file content to fit within budget
                $remaining_tokens = $max_tokens - $total_tokens;
                $remaining_chars  = $remaining_tokens * 4;
                $content = substr( $content, 0, $remaining_chars ) . "\n... (truncated)";
                $tokens  = $remaining_tokens;
            }

            $context .= sprintf(
                "\n<file_content path=\"%s\">\n%s\n</file_content>\n",
                $rel_path,
                $content
            );

            $total_tokens += $tokens;

            if ( $total_tokens >= $max_tokens ) {
                break;
            }
        }

        $context .= "\n</Attached_Files>\n";
        return $context;
    }

    /**
     * Build context from a directory (recursive file collection).
     *
     * @param string $dir_path Relative directory path.
     * @param int    $max_tokens Maximum total tokens.
     * @return string Formatted context block.
     */
    public function build_directory_context( $dir_path, $max_tokens = 8000 ) {
        $abs_path = ABSPATH . ltrim( $dir_path, '/' );
        if ( ! is_dir( $abs_path ) ) {
            return '';
        }

        $files = array();
        $this->scan_directory( $abs_path, $files );

        // Convert to relative paths
        $rel_paths = array_map( function( $f ) {
            return str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $f ) );
        }, $files );

        return $this->build_file_context( $rel_paths, $max_tokens );
    }

    // ─── Chunking ───────────────────────────────────────────

    /**
     * Split file content into chunks.
     *
     * @param string $content   File content.
     * @param string $extension File extension.
     * @param string $file_path Relative file path.
     * @return array Array of chunks.
     */
    public function chunk_file( $content, $extension, $file_path = '' ) {
        $token_count = $this->estimate_tokens( $content );
        if ( $token_count < 10 ) {
            return array();
        }

        // Small file — single chunk
        if ( $token_count <= self::MAX_CHUNK_TOKENS ) {
            return array( array(
                'content'     => "// File: {$file_path}\n" . $content,
                'token_count' => $token_count,
            ) );
        }

        // Language-aware chunking
        $chunks = array();
        if ( in_array( $extension, array( 'php' ), true ) ) {
            $chunks = $this->chunk_php( $content, $file_path );
        } elseif ( in_array( $extension, array( 'js', 'jsx', 'ts', 'tsx' ), true ) ) {
            $chunks = $this->chunk_js( $content, $file_path );
        } elseif ( in_array( $extension, array( 'css', 'scss' ), true ) ) {
            $chunks = $this->chunk_css( $content, $file_path );
        }

        // Fallback to sliding window
        if ( empty( $chunks ) ) {
            $chunks = $this->chunk_sliding_window( $content, $file_path );
        }

        return $chunks;
    }

    /**
     * Chunk PHP files by function/class boundaries.
     */
    private function chunk_php( $content, $file_path ) {
        $chunks = array();
        $pattern = '/^(?=\s*(?:function\s|class\s|abstract\s+class\s|interface\s|trait\s|\/\*\*\s))/m';
        $parts   = preg_split( $pattern, $content, -1, PREG_SPLIT_NO_EMPTY );

        if ( ! $parts || count( $parts ) <= 1 ) {
            return array();
        }

        $line_counter = 1;
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( empty( $part ) ) {
                continue;
            }

            $tokens = $this->estimate_tokens( $part );

            if ( $tokens > self::MAX_CHUNK_TOKENS ) {
                $sub_chunks = $this->chunk_sliding_window( $part, $file_path, $line_counter );
                $chunks     = array_merge( $chunks, $sub_chunks );
            } else {
                $lines_in_part = substr_count( $part, "\n" ) + 1;
                $header  = "// File: {$file_path} | Lines: {$line_counter}-" . ( $line_counter + $lines_in_part - 1 );
                $chunks[] = array(
                    'content'     => $header . "\n" . $part,
                    'token_count' => $tokens,
                );
            }

            $line_counter += substr_count( $part, "\n" ) + 1;
        }

        return $chunks;
    }

    /**
     * Chunk JS files by function/class/component boundaries.
     */
    private function chunk_js( $content, $file_path ) {
        $chunks = array();
        $pattern = '/^(?=\s*(?:function\s|const\s+\w+\s*=|let\s+\w+\s*=|class\s|export\s|\/\*\*\s))/m';
        $parts   = preg_split( $pattern, $content, -1, PREG_SPLIT_NO_EMPTY );

        if ( ! $parts || count( $parts ) <= 1 ) {
            return array();
        }

        $line_counter = 1;
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( empty( $part ) ) {
                continue;
            }

            $tokens = $this->estimate_tokens( $part );

            if ( $tokens > self::MAX_CHUNK_TOKENS ) {
                $sub_chunks = $this->chunk_sliding_window( $part, $file_path, $line_counter );
                $chunks     = array_merge( $chunks, $sub_chunks );
            } else {
                $lines_in_part = substr_count( $part, "\n" ) + 1;
                $header  = "// File: {$file_path} | Lines: {$line_counter}-" . ( $line_counter + $lines_in_part - 1 );
                $chunks[] = array(
                    'content'     => $header . "\n" . $part,
                    'token_count' => $tokens,
                );
            }

            $line_counter += substr_count( $part, "\n" ) + 1;
        }

        return $chunks;
    }

    /**
     * Chunk CSS files by rule block boundaries.
     */
    private function chunk_css( $content, $file_path ) {
        $pattern = '/(?<=\})\s*\n/';
        $parts   = preg_split( $pattern, $content, -1, PREG_SPLIT_NO_EMPTY );

        if ( ! $parts || count( $parts ) <= 1 ) {
            return array();
        }

        $chunks       = array();
        $buffer       = '';
        $buffer_tokens = 0;
        $line_counter = 1;

        foreach ( $parts as $part ) {
            $part_tokens = $this->estimate_tokens( $part );

            if ( $buffer_tokens + $part_tokens > self::MAX_CHUNK_TOKENS && ! empty( $buffer ) ) {
                $header  = "/* File: {$file_path} | Lines: ~{$line_counter} */";
                $chunks[] = array(
                    'content'     => $header . "\n" . trim( $buffer ),
                    'token_count' => $buffer_tokens,
                );
                $line_counter += substr_count( $buffer, "\n" ) + 1;
                $buffer        = '';
                $buffer_tokens = 0;
            }

            $buffer       .= $part . "\n";
            $buffer_tokens += $part_tokens;
        }

        if ( ! empty( trim( $buffer ) ) ) {
            $header  = "/* File: {$file_path} | Lines: ~{$line_counter} */";
            $chunks[] = array(
                'content'     => $header . "\n" . trim( $buffer ),
                'token_count' => $buffer_tokens,
            );
        }

        return $chunks;
    }

    /**
     * Fallback chunking: sliding window with overlap.
     */
    private function chunk_sliding_window( $content, $file_path, $start_line = 1 ) {
        $lines  = explode( "\n", $content );
        $chunks = array();

        $chunk_lines  = array();
        $chunk_tokens = 0;
        $chunk_start  = $start_line;

        for ( $i = 0; $i < count( $lines ); $i++ ) {
            $line_tokens   = $this->estimate_tokens( $lines[ $i ] );
            $chunk_lines[] = $lines[ $i ];
            $chunk_tokens += $line_tokens;

            if ( $chunk_tokens >= self::MAX_CHUNK_TOKENS ) {
                $chunk_end = $chunk_start + count( $chunk_lines ) - 1;
                $header    = "// File: {$file_path} | Lines: {$chunk_start}-{$chunk_end}";
                $chunks[]  = array(
                    'content'     => $header . "\n" . implode( "\n", $chunk_lines ),
                    'token_count' => $chunk_tokens,
                );

                $overlap_lines = (int) ( self::OVERLAP_TOKENS / max( 1, $line_tokens ) );
                $overlap_lines = max( 3, min( $overlap_lines, 10 ) );
                $chunk_lines   = array_slice( $chunk_lines, -$overlap_lines );
                $chunk_tokens  = $this->estimate_tokens( implode( "\n", $chunk_lines ) );
                $chunk_start   = $chunk_end - $overlap_lines + 1;
            }
        }

        if ( ! empty( $chunk_lines ) ) {
            $chunk_end = $chunk_start + count( $chunk_lines ) - 1;
            $header    = "// File: {$file_path} | Lines: {$chunk_start}-{$chunk_end}";
            $chunks[]  = array(
                'content'     => $header . "\n" . implode( "\n", $chunk_lines ),
                'token_count' => $chunk_tokens,
            );
        }

        return $chunks;
    }

    // ─── Helpers ────────────────────────────────────────────

    /**
     * Estimate token count for a string.
     *
     * @param string $text The text to estimate.
     * @return int Estimated token count.
     */
    private function estimate_tokens( $text ) {
        return (int) ceil( strlen( $text ) / 4 );
    }

    /**
     * Get default indexable paths (themes + plugins directories).
     *
     * @return array
     */
    private function get_default_paths() {
        return array(
            get_stylesheet_directory(),
            WP_CONTENT_DIR . '/plugins/',
        );
    }

    /**
     * Recursively scan a directory for indexable files.
     *
     * @param string $dir   Absolute directory path.
     * @param array  $files Array to append file paths to.
     */
    private function scan_directory( $dir, &$files ) {
        $items = @scandir( $dir );
        if ( ! $items ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $full_path = $dir . DIRECTORY_SEPARATOR . $item;

            if ( is_dir( $full_path ) ) {
                if ( in_array( $item, self::SKIP_DIRS, true ) ) {
                    continue;
                }
                $this->scan_directory( $full_path, $files );
            } elseif ( is_file( $full_path ) ) {
                $ext = pathinfo( $full_path, PATHINFO_EXTENSION );
                if ( in_array( $ext, self::INDEXABLE_EXTENSIONS, true ) ) {
                    if ( filesize( $full_path ) <= 512000 ) {
                        $files[] = $full_path;
                    }
                }
            }
        }
    }

    /**
     * Get indexing stats.
     *
     * @return array
     */
    public function get_stats() {
        return array(
            'total_chunks'  => $this->store->get_chunk_count(),
            'indexed_files' => $this->store->get_indexed_files(),
            'table_exists'  => $this->store->table_exists(),
        );
    }
}
