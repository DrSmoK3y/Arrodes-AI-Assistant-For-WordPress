<?php
defined( 'ABSPATH' ) || exit;

/**
 * AAM_Embedding_Adapter
 *
 * Universal Multi-Provider Vector Embedding Client & Local Hash Fallback.
 * Generates dense vector embeddings via Google Gemini, OpenAI, Custom API,
 * or a zero-cost local normalized vectorizer for Groq, DeepSeek, and Kimi.
 */
class AAM_Embedding_Adapter {

    /**
     * Generate an embedding vector for a given text
     *
     * @param string $text Input content
     * @return array [ 'vector' => float[], 'dim' => int, 'model' => string, 'provider' => string ]
     */
    public static function generate_embedding( $text ) {
        $text = trim( (string) $text );
        if ( empty( $text ) ) {
            return null;
        }

        // Truncate to maximum 2,000 words to respect API token boundaries
        $clean_sample = implode( ' ', array_slice( explode( ' ', $text ), 0, 1500 ) );

        // Check local transient cache
        $cache_key = 'aam_emb_' . md5( $clean_sample );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached['vector'] ) ) {
            return $cached;
        }

        $provider   = get_option( 'aam_embedding_provider', 'auto' );
        $api_key    = get_option( 'aam_embedding_api_key', '' );
        if ( empty( $api_key ) ) {
            // Fall back to main API key if same provider is used
            $api_key = get_option( 'aam_api_key', '' );
        }

        $main_ai_provider = get_option( 'aam_provider', 'openrouter' );

        // Resolve Auto Mode: If main provider is Gemini, use Gemini embedding; if custom/openai, use OpenAI; otherwise local fallback
        if ( 'auto' === $provider ) {
            if ( 'gemini' === $main_ai_provider && ! empty( $api_key ) ) {
                $provider = 'gemini';
            } elseif ( 'custom' === $main_ai_provider && ! empty( $api_key ) ) {
                $provider = 'openai_compat';
            } else {
                $provider = 'local_hash';
            }
        }

        $result = null;

        // 1. Google Gemini Embeddings (text-embedding-004)
        if ( 'gemini' === $provider && ! empty( $api_key ) ) {
            $result = self::call_gemini_embedding( $clean_sample, $api_key );
        }

        // 2. OpenAI / OpenAI Compatible Embeddings
        if ( ( 'openai' === $provider || 'openai_compat' === $provider ) && ! empty( $api_key ) ) {
            $endpoint = ( 'openai_compat' === $provider ) ? get_option( 'aam_custom_endpoint', '' ) : 'https://api.openai.com/v1';
            $result = self::call_openai_embedding( $clean_sample, $api_key, $endpoint );
        }

        // 3. High-Quality Deterministic Local Hash Vectorizer (Always works, 0 cost, instant)
        if ( empty( $result ) || empty( $result['vector'] ) ) {
            $result = self::generate_local_vector( $clean_sample );
        }

        // Cache for 30 days
        if ( ! empty( $result ) && ! empty( $result['vector'] ) ) {
            set_transient( $cache_key, $result, 30 * DAY_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Binary Vector Packing (pack('f*')) for ultra-fast storage and lower RAM/DB footprint
     */
    public static function pack_vector( array $vector ) {
        return pack( 'f*', ...$vector );
    }

    /**
     * Binary Vector Unpacking
     */
    public static function unpack_vector( $binary ) {
        if ( empty( $binary ) ) return [];
        $unpacked = unpack( 'f*', $binary );
        return is_array( $unpacked ) ? array_values( $unpacked ) : [];
    }

    /**
     * Compute cosine similarity between two float vectors: (A . B) / (||A|| * ||B||)
     *
     * @param array $vecA Float array
     * @param array $vecB Float array
     * @return float Value between -1.0 and 1.0 (or 0.0 to 1.0 for normalized non-negative vectors)
     */
    public static function cosine_similarity( array $vecA, array $vecB ) {
        $countA = count( $vecA );
        $countB = count( $vecB );

        if ( $countA === 0 || $countB === 0 ) {
            return 0.0;
        }

        $min_count = min( $countA, $countB );
        $dot_product = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ( $i = 0; $i < $min_count; $i++ ) {
            $valA = (float) $vecA[ $i ];
            $valB = (float) $vecB[ $i ];
            $dot_product += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }

        if ( $normA <= 0.0 || $normB <= 0.0 ) {
            return 0.0;
        }

        $similarity = $dot_product / ( sqrt( $normA ) * sqrt( $normB ) );
        return (float) max( 0.0, min( 1.0, $similarity ) );
    }

    /**
     * Google Gemini Embedding API Call
     */
    private static function call_gemini_embedding( $text, $api_key ) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key=' . urlencode( $api_key );

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'model'   => 'models/text-embedding-004',
                'content' => [
                    'parts' => [ [ 'text' => $text ] ]
                ]
            ] )
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code !== 200 || empty( $body ) ) {
            return null;
        }

        $data = json_decode( $body, true );
        $values = $data['embedding']['values'] ?? null;

        if ( is_array( $values ) && ! empty( $values ) ) {
            return [
                'vector'   => $values,
                'dim'      => count( $values ),
                'model'    => 'text-embedding-004',
                'provider' => 'gemini',
            ];
        }

        return null;
    }

    /**
     * OpenAI Compatible Embedding API Call
     */
    private static function call_openai_embedding( $text, $api_key, $base_url = 'https://api.openai.com/v1' ) {
        $endpoint = rtrim( $base_url, '/' );
        if ( ! str_ends_with( $endpoint, '/embeddings' ) ) {
            $endpoint .= '/embeddings';
        }

        $auth_header = str_starts_with( $api_key, 'Bearer ' ) ? $api_key : 'Bearer ' . $api_key;

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => $auth_header,
            ],
            'body'    => wp_json_encode( [
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ] )
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code !== 200 || empty( $body ) ) {
            return null;
        }

        $data = json_decode( $body, true );
        $values = $data['data'][0]['embedding'] ?? null;

        if ( is_array( $values ) && ! empty( $values ) ) {
            return [
                'vector'   => $values,
                'dim'      => count( $values ),
                'model'    => 'text-embedding-3-small',
                'provider' => 'openai',
            ];
        }

        return null;
    }

    /**
     * High-dimensional deterministic Local Vectorizer (128 dimensions)
     * Maps token n-grams and frequency features into a normalized continuous vector space.
     * Works instantly with zero API dependencies, zero cost, and zero external network calls.
     */
    public static function generate_local_vector( $text ) {
        $dim = 128;
        $vector = array_fill( 0, $dim, 0.0 );

        // Extract tokens (unigrams + bigrams)
        $clean = strtolower( wp_strip_all_tags( $text ) );
        $words = preg_split( '/[^a-z0-9_\-]+/', $clean, -1, PREG_SPLIT_NO_EMPTY );

        if ( empty( $words ) ) {
            return [
                'vector'   => $vector,
                'dim'      => $dim,
                'model'    => 'aam-local-hash-128',
                'provider' => 'local_hash',
            ];
        }

        $word_count = count( $words );
        for ( $i = 0; $i < $word_count; $i++ ) {
            $w = $words[ $i ];
            // Hash unigram to bin
            $h1 = crc32( $w ) % $dim;
            if ( $h1 < 0 ) $h1 += $dim;
            $weight = 1.0 + ( 1.0 / ( 1.0 + log( strlen( $w ) + 1 ) ) );
            $vector[ $h1 ] += $weight;

            // Hash bigram if available
            if ( $i < $word_count - 1 ) {
                $bigram = $w . '_' . $words[ $i + 1 ];
                $h2 = crc32( $bigram ) % $dim;
                if ( $h2 < 0 ) $h2 += $dim;
                $vector[ $h2 ] += 1.8;
            }
        }

        // L2 Normalization so dot product equals cosine similarity
        $norm = 0.0;
        foreach ( $vector as $val ) {
            $norm += $val * $val;
        }
        $sqrt_norm = sqrt( $norm );
        if ( $sqrt_norm > 0 ) {
            for ( $j = 0; $j < $dim; $j++ ) {
                $vector[ $j ] = round( $vector[ $j ] / $sqrt_norm, 6 );
            }
        }

        return [
            'vector'   => $vector,
            'dim'      => $dim,
            'model'    => 'aam-local-hash-128',
            'provider' => 'local_hash',
        ];
    }
}
