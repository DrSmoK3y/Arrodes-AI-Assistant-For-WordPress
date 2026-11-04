<?php
defined( 'ABSPATH' ) || exit;

/**
 * AAM_Hybrid_Search
 *
 * Industrial Hybrid Search Engine (Vector Cosine Similarity + BM25 Sparse Search + RRF).
 * Combines semantic chunk retrieval, direct catalog index search, live WooCommerce product sync,
 * Roman Urdu intent expansion, in-title boosting, and strict threshold filtering.
 */
class AAM_Hybrid_Search {

    public static function get_chunks_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aam_content_chunks';
    }

    public static function get_index_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aam_content_index';
    }

    /**
     * Search relevant content chunks for AI context
     *
     * @param string $user_message User question or message
     * @param int $limit Max chunks to return (default: 5)
     * @return array Array of scored chunk objects
     */
    public static function search( $user_message, $limit = 5 ) {
        global $wpdb;

        $raw_message = trim( (string) $user_message );
        if ( empty( $raw_message ) ) {
            return [];
        }

        // Cache key
        $cache_key = 'aam_hyb_' . md5( strtolower( $raw_message ) . '_' . $limit );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        $chunks_table = self::get_chunks_table_name();
        $index_table  = self::get_index_table_name();
        $has_chunks_table = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $chunks_table ) ) === $chunks_table );
        $has_index_table  = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $index_table ) ) === $index_table );

        // 1. Process query with intent detection and Roman Urdu expansion
        $processed = AAM_Query_Preprocessor::process( $raw_message );
        $cleaned_keywords   = $processed['cleaned'];
        $expanded_keywords  = $processed['expanded_keywords'];
        $intent             = $processed['intent'];
        $normalized_query   = $processed['normalized_query'];

        // If neither table exists, try direct WooCommerce search
        if ( ! $has_chunks_table && ! $has_index_table ) {
            return self::direct_wc_search( $cleaned_keywords, $limit );
        }

        // 2. Dual-Source Candidate Generation (Chunks + Product Catalog Index + Live WooCommerce)
        $candidates = self::get_sparse_candidates( $chunks_table, $index_table, $cleaned_keywords, $expanded_keywords, $intent, 60 );

        // If no candidates found, fallback to direct WooCommerce product search or general content
        if ( empty( $candidates ) ) {
            $wc_direct = self::direct_wc_search( $cleaned_keywords, $limit );
            if ( ! empty( $wc_direct ) ) {
                $candidates = $wc_direct;
            } elseif ( $has_chunks_table ) {
                $candidates = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$chunks_table} ORDER BY id DESC LIMIT %d", 20 ),
                    ARRAY_A
                );
            }
        }

        if ( empty( $candidates ) ) {
            return [];
        }

        // 3. Dense Vector Similarity Scoring on pre-filtered candidates
        $query_emb_data = AAM_Embedding_Adapter::generate_embedding( $normalized_query );
        $query_vector   = $query_emb_data['vector'] ?? null;

        $min_threshold = (float) get_option( 'aam_similarity_threshold', '0.45' );
        $wa_number     = get_option( 'aam_whatsapp_number', '' );
        $evaluated     = [];

        foreach ( $candidates as $chunk ) {
            $chunk_id      = isset( $chunk['id'] ) ? $chunk['id'] : 0;
            $post_id       = isset( $chunk['post_id'] ) ? (int) $chunk['post_id'] : 0;
            $section_title = $chunk['section_title'] ?? $chunk['title'] ?? '';
            $content       = $chunk['clean_content'] ?? $chunk['searchable_text'] ?? '';
            $chunk_type    = $chunk['chunk_type'] ?? $chunk['content_type'] ?? 'general';
            $url           = $chunk['url'] ?? $chunk['permalink'] ?? '';
            $price         = $chunk['price'] ?? '';
            $stock_status  = $chunk['stock_status'] ?? '';
            $image_url     = $chunk['image_url'] ?? '';
            $add_to_cart   = $chunk['add_to_cart_url'] ?? '';

            // 4. Live WooCommerce Data Sync: Ensure 100% current live price, stock & real image
            if ( $chunk_type === 'product' && $post_id > 0 && function_exists( 'wc_get_product' ) ) {
                $wc_p = wc_get_product( $post_id );
                if ( $wc_p && 'publish' === get_post_status( $post_id ) ) {
                    $raw_price = $wc_p->get_price();
                    $symbol    = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : 'Rs.';
                    if ( ! empty( $raw_price ) && is_numeric( $raw_price ) ) {
                        $price = $symbol . ' ' . number_format( (float) $raw_price, 2 );
                    } elseif ( $wc_p->get_price_html() ) {
                        $price = wp_strip_all_tags( $wc_p->get_price_html() );
                    }

                    $stock_status = $wc_p->is_in_stock() ? 'In Stock' : 'Out of Stock';

                    $img_id = $wc_p->get_image_id();
                    if ( $img_id ) {
                        $live_img = wp_get_attachment_image_url( $img_id, 'large' );
                        if ( ! empty( $live_img ) ) {
                            $image_url = $live_img;
                        }
                    }

                    if ( empty( $add_to_cart ) ) {
                        $add_to_cart = function_exists( 'wc_get_checkout_url' )
                            ? add_query_arg( 'add-to-cart', $post_id, wc_get_checkout_url() )
                            : home_url( "/cart/?add-to-cart={$post_id}" );
                    }
                }
            }

            // Calculate Sparse BM25 / Keyword Score
            $keyword_score = self::calculate_keyword_match_score( $section_title, $content, $expanded_keywords );

            // Calculate Dense Vector Cosine Similarity
            $vector_score = 0.0;
            if ( ! empty( $query_vector ) ) {
                if ( ! empty( $chunk['embedding'] ) ) {
                    $chunk_vector = is_array( $chunk['embedding'] ) ? $chunk['embedding'] : json_decode( $chunk['embedding'], true );
                    if ( is_array( $chunk_vector ) ) {
                        $vector_score = AAM_Embedding_Adapter::cosine_similarity( $query_vector, $chunk_vector );
                    }
                } else {
                    // Generate vector on the fly if chunk was retrieved directly from product table
                    $cand_emb = AAM_Embedding_Adapter::generate_embedding( $section_title . ' ' . $content );
                    if ( ! empty( $cand_emb['vector'] ) ) {
                        $vector_score = AAM_Embedding_Adapter::cosine_similarity( $query_vector, $cand_emb['vector'] );
                    }
                }
            }

            // Title Match Boost (strong boost when search terms match product title)
            $title_boost = 0.0;
            $lower_title = strtolower( $section_title );
            $clean_query_lower = strtolower( $cleaned_keywords );

            // Exact phrase or substring in title
            if ( ! empty( $clean_query_lower ) && strpos( $lower_title, $clean_query_lower ) !== false ) {
                $title_boost += 0.35;
            } else {
                foreach ( $expanded_keywords as $kw ) {
                    if ( strlen( $kw ) >= 3 && strpos( $lower_title, strtolower( $kw ) ) !== false ) {
                        $title_boost += 0.20;
                        break;
                    }
                }
            }

            // Intent Alignment Boost
            $intent_boost = 0.0;
            if ( $intent === 'pricing' && $chunk_type === 'pricing' ) {
                $intent_boost += 0.20;
            } elseif ( $intent === 'policy' && $chunk_type === 'policy' ) {
                $intent_boost += 0.20;
            } elseif ( $intent === 'faq' && $chunk_type === 'faq' ) {
                $intent_boost += 0.15;
            } elseif ( ( $intent === 'product' || $intent === 'pricing' ) && $chunk_type === 'product' ) {
                $intent_boost += 0.25;
            }

            // WhatsApp chat URL with pre-filled message
            $whatsapp_url = '';
            if ( ! empty( $wa_number ) ) {
                $clean_wa = preg_replace( '/[^0-9]/', '', $wa_number );
                $wa_msg = ( $chunk_type === 'product' )
                    ? "Salam! I am interested in purchasing {$section_title} ({$price}) on " . get_bloginfo( 'name' ) . ". Please confirm stock and delivery."
                    : "Salam! I was chatting with your AI Assistant regarding '{$raw_message}'. Please connect me with your team.";
                $whatsapp_url = "https://wa.me/{$clean_wa}?text=" . rawurlencode( $wa_msg );
            }

            $evaluated[] = [
                'id'              => $chunk_id,
                'post_id'         => $post_id,
                'section_title'   => $section_title,
                'chunk_type'      => $chunk_type,
                'clean_content'   => $content,
                'url'             => $url,
                'price'           => $price,
                'stock_status'    => $stock_status,
                'image_url'       => $image_url,
                'add_to_cart_url' => $add_to_cart,
                'whatsapp_url'    => $whatsapp_url,
                'keyword_score'   => $keyword_score,
                'vector_score'    => $vector_score,
                'title_boost'     => $title_boost,
                'intent_boost'    => $intent_boost,
            ];
        }

        // 5. Compute Reciprocal Rank Fusion (RRF) Ranks (TREC Standard k=60)
        $by_kw = $evaluated;
        usort( $by_kw, function( $a, $b ) {
            return ( $b['keyword_score'] <=> $a['keyword_score'] );
        });

        $by_vec = $evaluated;
        usort( $by_vec, function( $a, $b ) {
            return ( $b['vector_score'] <=> $a['vector_score'] );
        });

        $kw_ranks  = [];
        foreach ( $by_kw as $idx => $item ) {
            $kw_ranks[ $item['id'] ] = $idx + 1;
        }

        $vec_ranks = [];
        foreach ( $by_vec as $idx => $item ) {
            $vec_ranks[ $item['id'] ] = $idx + 1;
        }

        $RRF_K = 60;
        $scored_chunks = [];

        foreach ( $evaluated as $item ) {
            $cid = $item['id'];
            $rank_bm25 = $kw_ranks[ $cid ] ?? 100;
            $rank_vec  = $vec_ranks[ $cid ] ?? 100;

            // RRF Formula: 1/(k + rank_bm25) + 1/(k + rank_vector)
            $rrf = ( 1.0 / ( $RRF_K + $rank_bm25 ) ) + ( 1.0 / ( $RRF_K + $rank_vec ) );
            $composite_score = min( 0.99, ( $rrf / 0.033 ) * 0.80 + $item['title_boost'] + $item['intent_boost'] );

            // Retain candidates meeting threshold or having title match / good keyword match
            if ( $composite_score >= $min_threshold || $item['title_boost'] > 0 || $item['keyword_score'] >= 0.25 ) {
                $scored_chunks[] = [
                    'id'              => $cid,
                    'post_id'         => $item['post_id'],
                    'section_title'   => $item['section_title'],
                    'title'           => $item['section_title'],
                    'chunk_type'      => $item['chunk_type'],
                    'clean_content'   => $item['clean_content'],
                    'url'             => $item['url'],
                    'permalink'       => $item['url'],
                    'price'           => $item['price'],
                    'stock_status'    => $item['stock_status'],
                    'image_url'       => $item['image_url'],
                    'add_to_cart_url' => $item['add_to_cart_url'],
                    'whatsapp_url'    => $item['whatsapp_url'],
                    'score'           => round( $composite_score, 4 ),
                    'vector_score'    => round( $item['vector_score'], 4 ),
                    'keyword_score'   => round( $item['keyword_score'], 4 ),
                    'intent'          => $intent,
                ];
            }
        }

        // Sort descending by composite score
        usort( $scored_chunks, function( $a, $b ) {
            if ( $a['score'] === $b['score'] ) return 0;
            return ( $a['score'] < $b['score'] ) ? 1 : -1;
        } );

        $final_results = array_slice( $scored_chunks, 0, $limit );

        // Cache results for 10 minutes
        set_transient( $cache_key, $final_results, 600 );

        return $final_results;
    }

    /**
     * Fetch candidate chunks using Multi-Table Sparse Search (Chunks + Catalog Index + Direct WC)
     */
    private static function get_sparse_candidates( $chunks_table, $index_table, $cleaned_keywords, array $expanded_keywords, $intent = 'general', $max_candidates = 50 ) {
        global $wpdb;

        $results  = [];
        $seen_ids = [];

        // Helper to add candidate uniquely
        $add_candidate = function( $row ) use ( &$results, &$seen_ids, $max_candidates ) {
            $key = ( ! empty( $row['url'] ) ) ? $row['url'] : ( $row['section_title'] ?? $row['title'] ?? '' );
            if ( ! empty( $key ) && ! isset( $seen_ids[ $key ] ) ) {
                $seen_ids[ $key ] = true;
                $results[] = $row;
            }
        };

        // ── 1. Search `wp_aam_content_chunks` table ──
        if ( ! empty( $chunks_table ) && $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $chunks_table ) ) === $chunks_table ) {
            // A. Natural Language Fulltext (tolerant to missing words, never breaks on short tokens)
            if ( ! empty( $cleaned_keywords ) ) {
                $ft_sql = "SELECT *, MATCH(section_title, clean_content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS ft_score
                           FROM {$chunks_table}
                           WHERE MATCH(section_title, clean_content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                           ORDER BY ft_score DESC LIMIT %d";
                $rows = $wpdb->get_results( $wpdb->prepare( $ft_sql, $cleaned_keywords, $cleaned_keywords, 25 ), ARRAY_A );
                if ( ! empty( $rows ) ) {
                    foreach ( $rows as $r ) $add_candidate( $r );
                }
            }

            // B. Direct Title & Keyword LIKE matches in Chunks Table
            if ( ! empty( $cleaned_keywords ) ) {
                $like_pat = '%' . $wpdb->esc_like( $cleaned_keywords ) . '%';
                $title_rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$chunks_table} WHERE section_title LIKE %s ORDER BY id DESC LIMIT 15", $like_pat ),
                    ARRAY_A
                );
                if ( ! empty( $title_rows ) ) {
                    foreach ( $title_rows as $r ) $add_candidate( $r );
                }
            }

            // C. Keyword tokens LIKE matches
            foreach ( array_slice( $expanded_keywords, 0, 4 ) as $kw ) {
                if ( strlen( $kw ) < 3 ) continue;
                $kw_pat = '%' . $wpdb->esc_like( $kw ) . '%';
                $extra = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$chunks_table} WHERE section_title LIKE %s OR clean_content LIKE %s ORDER BY id DESC LIMIT 10", $kw_pat, $kw_pat ),
                    ARRAY_A
                );
                if ( ! empty( $extra ) ) {
                    foreach ( $extra as $r ) $add_candidate( $r );
                }
            }
        }

        // ── 2. Search `wp_aam_content_index` table (Primary Catalog & Pages Index) ──
        if ( ! empty( $index_table ) && $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $index_table ) ) === $index_table ) {
            // A. Title LIKE matches in Index Table
            if ( ! empty( $cleaned_keywords ) ) {
                $idx_pat = '%' . $wpdb->esc_like( $cleaned_keywords ) . '%';
                $idx_rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$index_table} WHERE title LIKE %s OR searchable_text LIKE %s ORDER BY id DESC LIMIT 20", $idx_pat, $idx_pat ),
                    ARRAY_A
                );
                if ( ! empty( $idx_rows ) ) {
                    foreach ( $idx_rows as $ir ) {
                        $add_candidate( self::normalize_index_row_to_chunk( $ir ) );
                    }
                }
            }

            // B. Product-specific search in Index Table
            foreach ( array_slice( $expanded_keywords, 0, 4 ) as $kw ) {
                if ( strlen( $kw ) < 3 ) continue;
                $kw_pat = '%' . $wpdb->esc_like( $kw ) . '%';
                $prod_rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$index_table} WHERE content_type = 'product' AND (title LIKE %s OR searchable_text LIKE %s) ORDER BY id DESC LIMIT 15", $kw_pat, $kw_pat ),
                    ARRAY_A
                );
                if ( ! empty( $prod_rows ) ) {
                    foreach ( $prod_rows as $pr ) {
                        $add_candidate( self::normalize_index_row_to_chunk( $pr ) );
                    }
                }
            }
        }

        // ── 3. Direct WooCommerce Products Query (Live Store Fallback) ──
        if ( function_exists( 'wc_get_products' ) && ( count( $results ) < 4 || $intent === 'product' ) ) {
            $wc_prods = wc_get_products( [
                's'      => $cleaned_keywords,
                'status' => 'publish',
                'limit'  => 10,
            ] );

            if ( empty( $wc_prods ) && ! empty( $expanded_keywords ) ) {
                foreach ( array_slice( $expanded_keywords, 0, 3 ) as $kw ) {
                    if ( strlen( $kw ) < 3 ) continue;
                    $more = wc_get_products( [ 's' => $kw, 'status' => 'publish', 'limit' => 5 ] );
                    if ( ! empty( $more ) ) {
                        $wc_prods = array_merge( $wc_prods, $more );
                    }
                }
            }

            if ( ! empty( $wc_prods ) ) {
                foreach ( $wc_prods as $wc_obj ) {
                    $pid = $wc_obj->get_id();
                    if ( class_exists( 'AAM_Chunker' ) ) {
                        $chunks = AAM_Chunker::chunk_product( $pid );
                        if ( ! empty( $chunks[0] ) ) {
                            $chunks[0]['id'] = 'wc_' . $pid;
                            $add_candidate( $chunks[0] );
                        }
                    }
                }
            }
        }

        return array_slice( $results, 0, $max_candidates );
    }

    /**
     * Convert a row from wp_aam_content_index into standard chunk array
     */
    private static function normalize_index_row_to_chunk( array $row ) {
        $title = $row['title'] ?? 'Untitled';
        $type  = $row['content_type'] ?? 'general';
        $price = $row['price'] ?? '';
        $stock = $row['stock_status'] ?? '';
        $link  = $row['permalink'] ?? '';
        $image = $row['image_url'] ?? '';
        $text  = $row['searchable_text'] ?? '';
        $ref_id = (int) ( $row['ref_id'] ?? 0 );

        $content_lines = [
            "TITLE: {$title}",
            "TYPE: {$type}",
        ];
        if ( ! empty( $ref_id ) ) $content_lines[] = "PRODUCT_ID: {$ref_id}";
        if ( ! empty( $price ) )  $content_lines[] = "PRICE: {$price}";
        if ( ! empty( $stock ) )  $content_lines[] = "STATUS: {$stock}";
        if ( ! empty( $link ) )   $content_lines[] = "LINK: {$link}";
        if ( ! empty( $image ) )  $content_lines[] = "IMAGE: {$image}";
        if ( ! empty( $text ) )   $content_lines[] = "DETAILS: {$text}";

        $cart_url = '';
        if ( $type === 'product' && $ref_id > 0 ) {
            $cart_url = function_exists( 'wc_get_checkout_url' )
                ? add_query_arg( 'add-to-cart', $ref_id, wc_get_checkout_url() )
                : home_url( "/cart/?add-to-cart={$ref_id}" );
        }

        return [
            'id'              => 'idx_' . ( $row['id'] ?? $ref_id ),
            'post_id'         => $ref_id,
            'section_title'   => $title,
            'chunk_type'      => $type,
            'clean_content'   => implode( "\n", $content_lines ),
            'url'             => $link,
            'price'           => $price,
            'stock_status'    => $stock,
            'image_url'       => $image,
            'add_to_cart_url' => $cart_url,
            'embedding'       => null,
            'token_count'     => str_word_count( $text ),
        ];
    }

    /**
     * Direct WooCommerce search fallback
     */
    private static function direct_wc_search( $cleaned_keywords, $limit = 5 ) {
        if ( ! function_exists( 'wc_get_products' ) || ! class_exists( 'AAM_Chunker' ) ) {
            return [];
        }

        $wc_prods = wc_get_products( [
            's'      => $cleaned_keywords,
            'status' => 'publish',
            'limit'  => $limit,
        ] );

        $results = [];
        foreach ( $wc_prods as $p ) {
            $chunks = AAM_Chunker::chunk_product( $p->get_id() );
            if ( ! empty( $chunks[0] ) ) {
                $c = $chunks[0];
                $c['id'] = 'wc_' . $p->get_id();
                $c['score'] = 0.85;
                $results[] = $c;
            }
        }

        return $results;
    }

    /**
     * Compute BM25-like keyword match score based on term frequencies and title prominence
     */
    private static function calculate_keyword_match_score( $title, $content, array $keywords ) {
        if ( empty( $keywords ) ) return 0.0;

        $combined = strtolower( $title . ' ' . $content );
        $title_lower = strtolower( $title );
        $matches = 0;
        $total_words = count( $keywords );

        foreach ( $keywords as $kw ) {
            $kw = strtolower( trim( $kw ) );
            if ( strlen( $kw ) < 2 ) continue;

            if ( strpos( $title_lower, $kw ) !== false ) {
                $matches += 1.5; // Weight title matches higher
            } elseif ( strpos( $combined, $kw ) !== false ) {
                $matches += 1.0;
            }
        }

        if ( $total_words === 0 ) return 0.0;
        return min( 1.0, (float) ( $matches / $total_words ) );
    }

    /**
     * Fallback to legacy index table if chunk table is migrating
     */
    private static function fallback_legacy_search( $cleaned_keywords, $limit ) {
        if ( class_exists( 'AAM_Search' ) ) {
            $raw_items = AAM_Search::find_relevant( $cleaned_keywords, $limit );
            $formatted = [];
            foreach ( $raw_items as $item ) {
                $formatted[] = [
                    'id'              => $item['id'] ?? 0,
                    'post_id'         => $item['ref_id'] ?? 0,
                    'section_title'   => $item['title'] ?? 'Store Item',
                    'title'           => $item['title'] ?? 'Store Item',
                    'chunk_type'      => $item['content_type'] ?? 'product',
                    'clean_content'   => $item['searchable_text'] ?? '',
                    'url'             => $item['permalink'] ?? '',
                    'permalink'       => $item['permalink'] ?? '',
                    'price'           => $item['price'] ?? '',
                    'stock_status'    => $item['stock_status'] ?? '',
                    'image_url'       => $item['image_url'] ?? '',
                    'add_to_cart_url' => '',
                    'whatsapp_url'    => '',
                    'score'           => 0.75,
                    'vector_score'    => 0.0,
                    'keyword_score'   => 0.75,
                    'intent'          => 'product',
                ];
            }
            return $formatted;
        }
        return [];
    }
}
