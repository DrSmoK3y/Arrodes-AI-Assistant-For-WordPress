<?php
defined( 'ABSPATH' ) || exit;

/**
 * AAM_Query_Preprocessor
 *
 * Fast Query Normalization, Intent Detection, and Roman Urdu Query Expansion.
 * Eliminates keyword blindness by expanding colloquial, vernacular, and Roman Urdu
 * queries into canonical search keywords for both Sparse BM25 and Dense Vector retrieval.
 */
class AAM_Query_Preprocessor {

    /**
     * Common conversational prefixes / filler stop words in English and Roman Urdu
     */
    private static $filler_words = [
        'bhai', 'yaar', 'janab', 'sir', 'plz', 'please', 'batao', 'batayein', 'mujhe', 'chahiye',
        'karna', 'hai', 'hain', 'kya', 'kaise', 'kab', 'kahan', 'kitna', 'kitne', 'meri', 'mera',
        'hum', 'app', 'aap', 'can you', 'could you', 'tell me', 'i want', 'what is', 'how to',
        'show me', 'where is', 'give me', 'looking for', 'need to know', 'is there'
    ];

    /**
     * Roman Urdu to Canonical Keyword Dictionary
     */
    private static $roman_urdu_dictionary = [
        // Pricing & Plans
        'price'        => [ 'price', 'pricing', 'cost', 'fee', 'rate', 'plans', 'tier', 'subscription' ],
        'rate'         => [ 'price', 'cost', 'pricing', 'charges', 'rate' ],
        'kitne ka'     => [ 'price', 'cost', 'pricing', 'package' ],
        'paisa'        => [ 'price', 'cost', 'pricing', 'payment' ],
        'paise'        => [ 'price', 'cost', 'pricing', 'payment' ],
        'khareedna'    => [ 'buy', 'purchase', 'order', 'pricing', 'plans' ],
        'khareed'      => [ 'buy', 'purchase', 'pricing' ],
        'discount'     => [ 'discount', 'coupon', 'promo', 'offer', 'sale' ],
        'off'          => [ 'discount', 'coupon', 'promo', 'offer' ],
        'free'         => [ 'free trial', 'starter', 'community', 'zero cost' ],

        // Policies & Guarantee
        'refund'       => [ 'refund', 'money back', 'guarantee', 'return policy', 'cancellation' ],
        'wapis'        => [ 'refund', 'return', 'money back guarantee' ],
        'wapas'        => [ 'refund', 'return', 'money back guarantee' ],
        'policy'       => [ 'policy', 'terms', 'guarantee', 'conditions' ],
        'guarantee'    => [ 'guarantee', 'warranty', 'money back', 'satisfaction' ],

        // Installation & Setup
        'install'      => [ 'install', 'installation', 'setup', 'activation', 'guide', 'download' ],
        'setup'        => [ 'setup', 'configuration', 'getting started', 'install', 'guide' ],
        'chalu'        => [ 'start', 'activate', 'setup', 'install' ],
        'kaam'         => [ 'function', 'how it works', 'features', 'usage' ],
        'use'          => [ 'usage', 'how to use', 'guide', 'tutorial', 'documentation' ],

        // Support & Contact
        'contact'      => [ 'contact', 'support', 'email', 'phone', 'help desk', 'touch' ],
        'rabta'        => [ 'contact', 'support', 'reach out', 'email' ],
        'madad'        => [ 'help', 'support', 'assistance' ],
        'email'        => [ 'email', 'support email', 'contact address' ],
        'phone'        => [ 'phone number', 'call', 'contact' ],

        // Delivery & Shipping (eCommerce)
        'delivery'     => [ 'delivery', 'shipping', 'transit', 'dispatch', 'courier' ],
        'bhejna'       => [ 'shipping', 'delivery', 'dispatch' ],
        'kab pohnche'  => [ 'delivery time', 'shipping days', 'estimated arrival' ],
        'order'        => [ 'order', 'tracking', 'status', 'checkout' ],

        // Verification & Authenticity
        'verify'       => [ 'verify', 'verification', 'credential', 'validation', 'check', 'certificate' ],
        'asli'         => [ 'genuine', 'original', 'verified', 'authentic' ],
        'fake'         => [ 'authentic', 'official', 'verified' ],
    ];

    /**
     * Process query: clean, detect intent, and expand keywords
     *
     * @param string $query Raw user input
     * @return array [ 'cleaned' => string, 'expanded_keywords' => array, 'intent' => string, 'normalized_query' => string ]
     */
    public static function process( $query, $history = [] ) {
        $raw = trim( (string) $query );
        if ( empty( $raw ) ) {
            return [
                'cleaned'            => '',
                'expanded_keywords'  => [],
                'intent'             => 'general',
                'normalized_query'   => '',
            ];
        }

        // 1. Normalize Roman Urdu spelling/phonetic variants
        $normalized = strtolower( $raw );
        $normalized = preg_replace( '/\b(kia|kiya|kye|kyaa)\b/i', 'kya', $normalized );
        $normalized = preg_replace( '/\b(pese|pesay|rupay|rupya|rupee|rupees)\b/i', 'paisa', $normalized );
        $normalized = preg_replace( '/\b(wapas|wapsi|waapsi|waapis)\b/i', 'wapis', $normalized );
        $normalized = preg_replace( '/\b(kese|kasey|tarika|tareeqa|tareeqah)\b/i', 'kaise', $normalized );
        $normalized = preg_replace( '/\b(daam|qeemat|qemat|rate|bhao)\b/i', 'price', $normalized );
        $normalized = preg_replace( '/\b(khareedna|kharidna|lena|leina)\b/i', 'buy', $normalized );
        $normalized = preg_replace( '/\b(chahiye|chahye|chahie)\b/i', 'need', $normalized );

        // 2. Context-aware follow-up resolution from recent conversation history
        $is_followup = preg_match( '/^(aur\s+|what\s+about\s+|and\s+|is\s+ka\s+|iski\s+|iska\s+|ye\s+bhi\s+|price\?|rate\?|details\?)/i', $normalized ) || strlen( $normalized ) < 15;
        $context_subject = "";
        if ( $is_followup && ! empty( $history ) && is_array( $history ) ) {
            for ( $h_idx = count( $history ) - 1; $h_idx >= 0; $h_idx-- ) {
                $prev_content = $history[ $h_idx ]['content'] ?? '';
                // Check for product titles, clothing items, audio, or plans
                if ( preg_match( '/\b([A-Z][a-zA-Z0-9\s\-]{3,40}(?:suit|kurti|kurta|lawn|chiffon|dress|shirt|speaker|earbuds|plan|package))\b/i', $prev_content, $m ) ) {
                    $context_subject = trim( $m[0] );
                    break;
                } elseif ( preg_match( '/\b(kisonli[\w\s\.\-]+|stereo\s*m04[\w\s\.\-]+|chiffon|lawn|kurti|kurta|speaker|earbuds|pro\s*plan|agency\s*plan|starter\s*plan)\b/i', $prev_content, $m ) ) {
                    $context_subject = trim( $m[0] );
                    break;
                }
            }
        }

        $effective_query = ! empty( $context_subject ) ? "{$context_subject} {$normalized}" : $normalized;

        // 3. Detect Intent
        $intent = self::detect_intent( $effective_query );

        // 4. Expand Roman Urdu and colloquial terms
        $expanded = [];
        foreach ( self::$roman_urdu_dictionary as $pattern => $synonyms ) {
            if ( preg_match( '/\b' . preg_quote( $pattern, '/' ) . '\b/i', $effective_query ) ) {
                $expanded = array_merge( $expanded, $synonyms );
            }
        }

        // 5. Clean query by stripping filler words
        $tokens = preg_split( '/[\s,\.\?\!\-\_]+/', $effective_query );
        $meaningful_tokens = [];
        foreach ( $tokens as $t ) {
            $t = trim( $t );
            if ( strlen( $t ) >= 2 && ! in_array( $t, self::$filler_words, true ) ) {
                $meaningful_tokens[] = $t;
            }
        }

        // Combine meaningful tokens with expanded synonyms
        $all_keywords = array_unique( array_merge( $meaningful_tokens, $expanded ) );

        // Cleaned string for MySQL FULLTEXT
        $cleaned_string = implode( ' ', array_slice( $meaningful_tokens, 0, 8 ) );
        if ( empty( $cleaned_string ) ) {
            $cleaned_string = $raw;
        }

        // Normalized expanded query for embedding vector creation
        $normalized_query = $raw;
        if ( ! empty( $expanded ) ) {
            $normalized_query .= ' (' . implode( ' ', array_slice( $expanded, 0, 6 ) ) . ')';
        }

        return [
            'cleaned'            => $cleaned_string,
            'expanded_keywords'  => array_values( $all_keywords ),
            'intent'             => $intent,
            'normalized_query'   => $normalized_query,
        ];
    }

    /**
     * Classify high-level user intent
     */
    private static function detect_intent( $query ) {
        if ( preg_match( '/(price|pricing|cost|rate|kitne|fee|plan|subscription|tier|license|buy|khareed)/i', $query ) ) {
            return 'pricing';
        }
        if ( preg_match( '/(refund|return|wapis|wapas|money back|guarantee|policy|cancel)/i', $query ) ) {
            return 'policy';
        }
        if ( preg_match( '/(install|setup|kaise|how to|guide|doc|documentation|tutorial|chalu)/i', $query ) ) {
            return 'setup';
        }
        if ( preg_match( '/(contact|support|email|phone|rabta|call|help|reach)/i', $query ) ) {
            return 'support';
        }
        if ( preg_match( '/(delivery|shipping|courier|bhejna|pohnch)/i', $query ) ) {
            return 'shipping';
        }
        if ( preg_match( '/(product|item|speaker|shirt|watch|buy|shop|store|stock)/i', $query ) ) {
            return 'product';
        }
        if ( preg_match( '/(verify|certificate|credential|check|authentic)/i', $query ) ) {
            return 'verification';
        }

        return 'general';
    }
}
