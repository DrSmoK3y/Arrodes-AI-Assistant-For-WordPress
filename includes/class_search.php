<?php
defined( 'ABSPATH' ) || exit;

class AAM_Search {

    /**
     * Find relevant products and pages from the wp_aam_content_index table
     * based on user query/message.
     *
     * @param string $user_message User input text.
     * @param int $limit Max number of items to return (default: 8).
     * @return array Array of matching rows with title, searchable_text, price, stock_status, image_url, permalink, meta_json.
     */
    public static function find_relevant( $user_message, $limit = 8 ) {
        global $wpdb;

        if ( empty( trim( $user_message ) ) ) {
            return [];
        }

        // Check search cache (Transient)
        $cache_key = 'aam_srch_' . md5( strtolower( trim( $user_message ) ) . '_' . $limit );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $table = AAM_Indexer::get_table_name();

        // Check if index table exists and has rows
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return [];
        }

        // 1. Clean query & extract keywords
        $cleaned_keywords = self::clean_query( $user_message );
        if ( empty( $cleaned_keywords ) ) {
            $cleaned_keywords = trim( $user_message );
        }

        // 2. Price filter detection (e.g. "under 5000", "below 3000", "less than 2000")
        $max_price_filter = self::detect_max_price( $user_message );

        // 3. Try FULLTEXT search
        $results = self::search_fulltext( $table, $cleaned_keywords, $limit, $max_price_filter );

        // 4. Fallback to LIKE search if FULLTEXT yields 0 results
        if ( empty( $results ) ) {
            $results = self::search_like( $table, $cleaned_keywords, $limit, $max_price_filter );
        }

        // 5. If still empty, try individual keyword fallback
        if ( empty( $results ) ) {
            $words = array_filter( explode( ' ', $cleaned_keywords ) );
            if ( ! empty( $words ) ) {
                $first_word = reset( $words );
                if ( strlen( $first_word ) >= 3 ) {
                    $results = self::search_like( $table, $first_word, $limit, $max_price_filter );
                }
            }
        }

        // 6. Rank results: In-stock items first, then by ID
        usort( $results, function( $a, $b ) {
            $a_in_stock = ( strcasecmp( $a['stock_status'], 'In Stock' ) === 0 || empty( $a['stock_status'] ) ) ? 1 : 0;
            $b_in_stock = ( strcasecmp( $b['stock_status'], 'In Stock' ) === 0 || empty( $b['stock_status'] ) ) ? 1 : 0;

            if ( $a_in_stock !== $b_in_stock ) {
                return $b_in_stock - $a_in_stock; // In stock comes first
            }
            return 0;
        } );

        $final_results = array_slice( $results, 0, $limit );
        set_transient( $cache_key, $final_results, 600 ); // Cache for 10 minutes

        return $final_results;
    }

    /**
     * FULLTEXT Search Query Execution
     */
    private static function search_fulltext( $table, $keywords, $limit, $max_price = null ) {
        global $wpdb;

        // Prepare query for NATURAL LANGUAGE MODE or BOOLEAN MODE
        $sql = "SELECT *, 
                MATCH(title, searchable_text) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score 
                FROM {$table} 
                WHERE MATCH(title, searchable_text) AGAINST (%s IN NATURAL LANGUAGE MODE)";

        $params = [ $keywords, $keywords ];

        if ( $max_price !== null && $max_price > 0 ) {
            $sql .= " AND (CAST(price AS DECIMAL(10,2)) <= %f OR price = '' OR price IS NULL)";
            $params[] = $max_price;
        }

        $sql .= " ORDER BY score DESC, id DESC LIMIT %d";
        $params[] = $limit * 2; // Fetch slightly more to re-rank in-stock items

        $prepared = $wpdb->prepare( $sql, $params );
        $rows     = $wpdb->get_results( $prepared, ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Fallback LIKE Search Query Execution
     */
    private static function search_like( $table, $keywords, $limit, $max_price = null ) {
        global $wpdb;

        $like_term = '%' . $wpdb->esc_like( $keywords ) . '%';
        $sql       = "SELECT * FROM {$table} WHERE (title LIKE %s OR searchable_text LIKE %s)";
        $params    = [ $like_term, $like_term ];

        if ( $max_price !== null && $max_price > 0 ) {
            $sql .= " AND (CAST(price AS DECIMAL(10,2)) <= %f OR price = '' OR price IS NULL)";
            $params[] = $max_price;
        }

        $sql .= " ORDER BY id DESC LIMIT %d";
        $params[] = $limit * 2;

        $prepared = $wpdb->prepare( $sql, $params );
        $rows     = $wpdb->get_results( $prepared, ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Clean user query by removing common stop words
     */
    private static function clean_query( $text ) {
        $text = strtolower( trim( $text ) );
        // Remove punctuation
        $text = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $text );

        $stopwords = [
            'the', 'is', 'at', 'which', 'on', 'a', 'an', 'and', 'or', 'in', 'of', 'for', 'to', 'with', 'by',
            'what', 'how', 'where', 'when', 'who', 'does', 'do', 'can', 'you', 'me', 'my', 'please', 'tell',
            'show', 'find', 'get', 'kya', 'hai', 'hain', 'ho', 'ko', 'se', 'main', 'mai', 'ka', 'ki', 'ke',
            'mga', 'mujhe', 'dikhayein', 'batao', 'dene', 'chahiye', 'kisi', 'kya', 'konsa', 'konsi'
        ];

        $words = explode( ' ', $text );
        $filtered = array_filter( $words, function( $word ) use ( $stopwords ) {
            $w = trim( $word );
            return ! in_array( $w, $stopwords, true ) && mb_strlen( $w, 'UTF-8' ) > 1;
        } );

        return implode( ' ', $filtered );
    }

    /**
     * Detect max price from user query (e.g. "under 5000", "below 3000", "less than 1500")
     */
    private static function detect_max_price( $user_message ) {
        if ( preg_match( '/(?:under|below|less than|sasta|kam|budget)\s*(?:rs|pkr|\$)?\s*(\d+)/i', $user_message, $matches ) ) {
            return (float) $matches[1];
        }
        return null;
    }

}
