<?php
/**
 * BossCode AI Agent — Keyword Search Index
 *
 * Replaces expensive AI-based embeddings with a lightweight,
 * high-performance keyword + symbol extraction engine using
 * TF-IDF scoring. No AI API calls required for indexing.
 *
 * @package BossCode_AI_Agent
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Search_Index {

    /**
     * Common stop words to exclude from indexing.
     *
     * @var array
     */
    private static $stop_words = array(
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'shall', 'can', 'this', 'that', 'these',
        'those', 'and', 'or', 'but', 'not', 'for', 'with', 'from', 'to',
        'of', 'in', 'on', 'at', 'by', 'as', 'if', 'it', 'its', 'all',
        'each', 'any', 'both', 'few', 'more', 'most', 'other', 'some',
        'such', 'than', 'too', 'very', 'just', 'about', 'above', 'after',
        'again', 'also', 'between', 'here', 'there', 'when', 'where', 'who',
        'what', 'which', 'how', 'then', 'so', 'no', 'nor', 'own', 'same',
        'true', 'false', 'null', 'return', 'var', 'let', 'const', 'function',
        'class', 'public', 'private', 'protected', 'static', 'new', 'use',
        'echo', 'print', 'array', 'string', 'int', 'float', 'bool', 'void',
    );

    /**
     * Extract keywords and symbols from code/text content.
     *
     * @param string $content File content.
     * @param string $extension File extension.
     * @return array Associative array of keyword => frequency.
     */
    public function extract_keywords( $content, $extension = '' ) {
        $keywords = array();

        // Extract identifiers (function names, class names, variables)
        $symbols = $this->extract_symbols( $content, $extension );
        foreach ( $symbols as $symbol ) {
            $lower = strtolower( $symbol );
            if ( strlen( $lower ) >= 3 && ! in_array( $lower, self::$stop_words, true ) ) {
                $keywords[ $lower ] = ( $keywords[ $lower ] ?? 0 ) + 3; // Higher weight for symbols
            }
        }

        // Extract regular words
        $words = preg_split( '/[^a-zA-Z0-9_]+/', $content );
        foreach ( $words as $word ) {
            $lower = strtolower( $word );
            if ( strlen( $lower ) >= 3 && ! in_array( $lower, self::$stop_words, true ) ) {
                $keywords[ $lower ] = ( $keywords[ $lower ] ?? 0 ) + 1;
            }
        }

        // Split camelCase and snake_case into sub-words
        $compound_words = $this->split_compound_words( array_keys( $keywords ) );
        foreach ( $compound_words as $word ) {
            $lower = strtolower( $word );
            if ( strlen( $lower ) >= 3 && ! in_array( $lower, self::$stop_words, true ) ) {
                $keywords[ $lower ] = ( $keywords[ $lower ] ?? 0 ) + 1;
            }
        }

        return $keywords;
    }

    /**
     * Extract named symbols from source code.
     *
     * @param string $content File content.
     * @param string $extension File extension.
     * @return array Array of symbol names.
     */
    private function extract_symbols( $content, $extension ) {
        $symbols = array();

        // PHP: functions, classes, methods, hooks
        if ( in_array( $extension, array( 'php' ), true ) ) {
            // Function declarations
            preg_match_all( '/function\s+(\w+)\s*\(/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );

            // Class declarations
            preg_match_all( '/class\s+(\w+)/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );

            // WordPress hooks
            preg_match_all( '/(?:add_action|add_filter|do_action|apply_filters)\s*\(\s*[\'"](\w+)[\'"]/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );

            // WordPress options
            preg_match_all( '/(?:get_option|update_option|add_option)\s*\(\s*[\'"](\w+)[\'"]/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );
        }

        // JavaScript: functions, classes, components
        if ( in_array( $extension, array( 'js', 'jsx', 'ts', 'tsx' ), true ) ) {
            // Function declarations
            preg_match_all( '/function\s+(\w+)\s*\(/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );

            // Class declarations
            preg_match_all( '/class\s+(\w+)/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );

            // Const/let/var assignments (likely component/function names)
            preg_match_all( '/(?:const|let|var)\s+(\w+)\s*=/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );

            // DOM selectors
            preg_match_all( '/(?:getElementById|querySelector|getElementsByClassName)\s*\(\s*[\'"]([^"\']+)[\'"]/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );
        }

        // CSS: selectors, class names, IDs
        if ( in_array( $extension, array( 'css', 'scss' ), true ) ) {
            preg_match_all( '/\.([a-zA-Z_][\w-]*)/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );

            preg_match_all( '/#([a-zA-Z_][\w-]*)/', $content, $m );
            $symbols = array_merge( $symbols, $m[1] );
        }

        return array_unique( $symbols );
    }

    /**
     * Split camelCase and snake_case into sub-words.
     *
     * @param array $words Array of words to split.
     * @return array Additional sub-words.
     */
    private function split_compound_words( $words ) {
        $subwords = array();
        foreach ( $words as $word ) {
            // camelCase → camel, Case
            $parts = preg_split( '/(?<=[a-z])(?=[A-Z])/', $word );
            if ( count( $parts ) > 1 ) {
                foreach ( $parts as $p ) {
                    if ( strlen( $p ) >= 3 ) {
                        $subwords[] = $p;
                    }
                }
            }

            // snake_case → snake, case
            $parts = explode( '_', $word );
            if ( count( $parts ) > 1 ) {
                foreach ( $parts as $p ) {
                    if ( strlen( $p ) >= 3 ) {
                        $subwords[] = $p;
                    }
                }
            }
        }
        return $subwords;
    }

    /**
     * Search chunks by keyword relevance (TF-IDF inspired).
     *
     * @param string $query  The search query.
     * @param array  $chunks Array of stored chunks with 'keywords' field.
     * @param int    $top_k  Number of results to return.
     * @return array Scored and sorted chunks.
     */
    public function search( $query, $chunks, $top_k = 5 ) {
        // Extract query keywords
        $query_keywords = $this->extract_keywords( $query );
        if ( empty( $query_keywords ) ) {
            return array();
        }

        $total_docs = count( $chunks );
        if ( $total_docs === 0 ) {
            return array();
        }

        // Calculate IDF for each query term
        $idf = array();
        foreach ( array_keys( $query_keywords ) as $term ) {
            $doc_count = 0;
            foreach ( $chunks as $chunk ) {
                $chunk_kw = is_string( $chunk['keywords'] ) ? json_decode( $chunk['keywords'], true ) : $chunk['keywords'];
                if ( isset( $chunk_kw[ $term ] ) ) {
                    $doc_count++;
                }
            }
            $idf[ $term ] = log( ( $total_docs + 1 ) / ( $doc_count + 1 ) ) + 1;
        }

        // Score each chunk
        $scored = array();
        foreach ( $chunks as $chunk ) {
            $chunk_kw = is_string( $chunk['keywords'] ) ? json_decode( $chunk['keywords'], true ) : $chunk['keywords'];
            if ( ! is_array( $chunk_kw ) ) {
                continue;
            }

            $score = 0.0;
            $matched_terms = 0;

            foreach ( $query_keywords as $term => $query_freq ) {
                if ( isset( $chunk_kw[ $term ] ) ) {
                    // TF-IDF: term frequency * inverse document frequency
                    $tf = 1 + log( $chunk_kw[ $term ] );
                    $score += $tf * ( $idf[ $term ] ?? 1 ) * $query_freq;
                    $matched_terms++;
                }
            }

            // Boost score by coverage (what % of query terms matched)
            if ( $matched_terms > 0 ) {
                $coverage = $matched_terms / count( $query_keywords );
                $score *= ( 1 + $coverage );

                // Boost exact file path matches
                $query_lower = strtolower( $query );
                $file_lower = strtolower( $chunk['file_path'] ?? '' );
                if ( strpos( $query_lower, basename( $file_lower, '.' . pathinfo( $file_lower, PATHINFO_EXTENSION ) ) ) !== false ) {
                    $score *= 2;
                }

                $scored[] = array(
                    'file_path'     => $chunk['file_path'],
                    'chunk_index'   => $chunk['chunk_index'],
                    'chunk_content' => $chunk['chunk_content'],
                    'score'         => $score,
                    'matched_terms' => $matched_terms,
                    'token_count'   => $chunk['token_count'] ?? 0,
                );
            }
        }

        // Sort by score descending
        usort( $scored, function( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );

        return array_slice( $scored, 0, $top_k );
    }
}
