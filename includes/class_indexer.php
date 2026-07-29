<?php
defined( 'ABSPATH' ) || exit;

class AAM_Indexer {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aam_content_index';
    }

    public static function create_table() {
        global $wpdb;

        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            content_type VARCHAR(20) NOT NULL,
            ref_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            searchable_text LONGTEXT NOT NULL,
            price VARCHAR(50) DEFAULT '',
            stock_status VARCHAR(50) DEFAULT '',
            image_url TEXT,
            permalink TEXT,
            meta_json LONGTEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_ref (content_type, ref_id),
            KEY content_type_idx (content_type),
            FULLTEXT KEY ft_search (title, searchable_text)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function __construct() {
        $this->register_hooks();
    }

    private function register_hooks() {
        // Auto-index on post / page save
        add_action( 'save_post', [ $this, 'on_save_post' ], 10, 2 );

        // Auto-index WooCommerce products
        add_action( 'woocommerce_update_product', [ $this, 'index_product' ], 10, 1 );
        add_action( 'woocommerce_new_product', [ $this, 'index_product' ], 10, 1 );

        // Remove from index on delete
        add_action( 'before_delete_post', [ $this, 'on_delete_post' ], 10, 1 );
    }

    public function on_save_post( $post_id, $post ) {
        if ( get_option( 'aam_auto_index', '1' ) === '0' ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( 'page' === $post->post_type || 'post' === $post->post_type ) {
            if ( 'publish' === $post->post_status ) {
                $this->index_page( $post_id );
            } else {
                $this->remove_index( 'page', $post_id );
            }
        } elseif ( 'product' === $post->post_type ) {
            if ( 'publish' === $post->post_status ) {
                $this->index_product( $post_id );
            } else {
                $this->remove_index( 'product', $post_id );
            }
        }
    }

    public function on_delete_post( $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( 'product' === $post_type ) {
            $this->remove_index( 'product', $post_id );
        } elseif ( 'page' === $post_type || 'post' === $post_type ) {
            $this->remove_index( 'page', $post_id );
            global $wpdb;
            $table = self::get_table_name();
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE content_type = 'page_item' AND ( (ref_id >= %d AND ref_id < %d) OR meta_json LIKE %s )",
                $post_id * 1000,
                ( $post_id + 1 ) * 1000,
                '%"parent_page_id":' . intval( $post_id ) . '%'
            ) );
        }
    }

    /**
     * Index a WordPress Page or Post using rendered HTML parsing (DOMDocument + DOMXPath)
     */
    public function index_page( $page_id ) {
        global $wpdb;

        $post = get_post( $page_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return false;
        }

        $table = self::get_table_name();

        // 1. Remove any previous main page entry and sub-item entries for this page
        $this->remove_index( 'page', $page_id );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE content_type = 'page_item' AND ( (ref_id >= %d AND ref_id < %d) OR meta_json LIKE %s )",
            $page_id * 1000,
            ( $page_id + 1 ) * 1000,
            '%"parent_page_id":' . intval( $page_id ) . '%'
        ) );

        $page_title = wp_strip_all_tags( $post->post_title );
        $permalink  = get_permalink( $page_id );
        $page_thumb = get_the_post_thumbnail_url( $page_id, 'medium' ) ?: '';

        // 2. Render content using 'the_content' filter so Elementor, Gutenberg, Divi & Shortcodes execute
        $saved_global_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        $GLOBALS['post']    = $post;
        setup_postdata( $post );

        $rendered_html = apply_filters( 'the_content', $post->post_content );

        if ( $saved_global_post ) {
            $GLOBALS['post'] = $saved_global_post;
            setup_postdata( $saved_global_post );
        } else {
            wp_reset_postdata();
        }

        if ( empty( trim( $rendered_html ) ) ) {
            $rendered_html = $post->post_content;
        }

        // 3. Clean & Load HTML via DOMDocument
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        @$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $rendered_html );
        $xpath = new DOMXPath( $dom );
        libxml_clear_errors();

        // 4. Remove Noise (scripts, styles, navs, footers, display:none, hidden elements)
        $noise_nodes = $xpath->query( '//script | //style | //nav | //footer | //header | //*[contains(@style,"display:none")] | //*[contains(@class,"hidden")]' );
        if ( $noise_nodes ) {
            foreach ( $noise_nodes as $node ) {
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        $extracted_items = [];
        $processed_nodes = [];

        // 5A. Card-like clusters (gallery items, product grids, service cards)
        $candidate_cards = $xpath->query( '//div[.//img and (.//h1 or .//h2 or .//h3 or .//h4 or .//h5 or .//h6)] | //li[.//img and (.//h1 or .//h2 or .//h3 or .//h4 or .//h5 or .//h6)] | //article[.//img and (.//h1 or .//h2 or .//h3 or .//h4 or .//h5 or .//h6)]' );

        if ( $candidate_cards && $candidate_cards->length > 0 ) {
            $cards_list = [];
            foreach ( $candidate_cards as $card ) {
                $cards_list[] = $card;
            }

            // Sort candidate nodes by depth (deeper nodes first to avoid parent container duplication)
            usort( $cards_list, function( $a, $b ) {
                $depth_a = 0; $p = $a; while ( $p->parentNode ) { $depth_a++; $p = $p->parentNode; }
                $depth_b = 0; $p = $b; while ( $p->parentNode ) { $depth_b++; $p = $p->parentNode; }
                return $depth_b - $depth_a;
            } );

            foreach ( $cards_list as $card ) {
                $already_processed = false;
                $parent = $card;
                while ( $parent ) {
                    if ( in_array( $parent, $processed_nodes, true ) ) {
                        $already_processed = true;
                        break;
                    }
                    $parent = $parent->parentNode;
                }
                if ( $already_processed ) {
                    continue;
                }

                $img_node     = $xpath->query( './/img', $card )->item( 0 );
                $heading_node = $xpath->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $card )->item( 0 );
                $desc_node    = $xpath->query( './/p | .//span[contains(@class,"desc") or contains(@class,"text")] | .//div[contains(@class,"desc") or contains(@class,"text")]', $card )->item( 0 );
                $link_node    = $xpath->query( './/a', $card )->item( 0 );

                if ( $heading_node ) {
                    $item_title = trim( wp_strip_all_tags( $heading_node->textContent ) );
                    if ( ! empty( $item_title ) && strlen( $item_title ) >= 3 ) {
                        $item_img = '';
                        if ( $img_node ) {
                            $item_img = $img_node->getAttribute( 'src' )
                                ?: ( $img_node->getAttribute( 'data-src' )
                                ?: ( $img_node->getAttribute( 'data-lazy-src' ) ?: '' ) );
                        }

                        $item_link = $permalink;
                        if ( $link_node ) {
                            $href = $link_node->getAttribute( 'href' );
                            if ( ! empty( $href ) && '#' !== $href && 0 !== strpos( $href, 'javascript:' ) ) {
                                $item_link = $href;
                            }
                        }

                        $item_desc = $desc_node ? trim( wp_strip_all_tags( $desc_node->textContent ) ) : '';

                        $extracted_items[] = [
                            'title'     => $item_title,
                            'text'      => $item_desc ? ( $item_title . ' - ' . $item_desc . ' (Page: ' . $page_title . ')' ) : ( $item_title . ' (Page: ' . $page_title . ')' ),
                            'image_url' => $item_img ?: $page_thumb,
                            'permalink' => $item_link,
                        ];

                        $processed_nodes[] = $card;
                    }
                }
            }
        }

        // 5B. Flowing content chunking (heading-wise sections)
        $headings = $xpath->query( '//h2 | //h3' );
        if ( $headings && $headings->length > 0 ) {
            foreach ( $headings as $h ) {
                $section_title = trim( wp_strip_all_tags( $h->textContent ) );
                if ( empty( $section_title ) || strlen( $section_title ) < 3 ) {
                    continue;
                }

                $section_text_parts = [];
                $curr = $h->nextSibling;
                while ( $curr ) {
                    $node_name = strtolower( $curr->nodeName );
                    if ( in_array( $node_name, [ 'h1', 'h2', 'h3', 'h4' ], true ) ) {
                        break;
                    }
                    $txt = trim( wp_strip_all_tags( $curr->textContent ) );
                    if ( ! empty( $txt ) ) {
                        $section_text_parts[] = $txt;
                    }
                    $curr = $curr->nextSibling;
                }

                $section_text = implode( ' ', $section_text_parts );
                if ( ! empty( $section_text ) ) {
                    $is_duplicate = false;
                    foreach ( $extracted_items as $ex ) {
                        if ( strtolower( $ex['title'] ) === strtolower( $section_title ) ) {
                            $is_duplicate = true;
                            break;
                        }
                    }

                    if ( ! $is_duplicate ) {
                        $extracted_items[] = [
                            'title'     => $section_title,
                            'text'      => $section_title . ' - ' . $section_text . ' (Page: ' . $page_title . ')',
                            'image_url' => $page_thumb,
                            'permalink' => $permalink,
                        ];
                    }
                }
            }
        }

        // 5C. Deduplicate extracted items by title + text hash
        $unique_items = [];
        $seen_hashes  = [];
        foreach ( $extracted_items as $item ) {
            $hash = md5( strtolower( $item['title'] . '|' . $item['text'] ) );
            if ( ! isset( $seen_hashes[ $hash ] ) ) {
                $seen_hashes[ $hash ] = true;
                $unique_items[]       = $item;
            }
        }

        // 6. Insert sub-items (page_item) into index table
        $inserted_subitems = 0;
        foreach ( $unique_items as $index => $item ) {
            $sub_ref_id = ( $page_id * 1000 ) + ( $index + 1 );
            $wpdb->replace(
                $table,
                [
                    'content_type'    => 'page_item',
                    'ref_id'          => $sub_ref_id,
                    'title'           => $item['title'],
                    'searchable_text' => trim( $item['text'] ),
                    'price'           => '',
                    'stock_status'    => '',
                    'image_url'       => $item['image_url'],
                    'permalink'       => $item['permalink'],
                    'meta_json'       => json_encode( [
                        'post_type'      => $post->post_type,
                        'parent_page_id' => $page_id,
                        'parent_title'   => $page_title,
                    ] ),
                    'updated_at'      => current_time( 'mysql' ),
                ],
                [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
            $inserted_subitems++;
        }

        // 7. Store overall Page summary row
        $full_body_text = trim( wp_strip_all_tags( $rendered_html ) );
        $full_body_text = preg_replace( '/\s+/', ' ', $full_body_text );
        if ( empty( $full_body_text ) ) {
            $full_body_text = $page_title;
        }

        return $wpdb->replace(
            $table,
            [
                'content_type'    => 'page',
                'ref_id'          => $page_id,
                'title'           => $page_title,
                'searchable_text' => trim( $full_body_text ),
                'price'           => '',
                'stock_status'    => '',
                'image_url'       => $page_thumb,
                'permalink'       => $permalink,
                'meta_json'       => json_encode( [
                    'post_type'     => $post->post_type,
                    'sub_items_cnt' => $inserted_subitems,
                ] ),
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Index a WooCommerce Product
     */
    public function index_product( $product_id ) {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
            return false;
        }

        $title       = wp_strip_all_tags( $product->get_name() );
        $desc        = wp_strip_all_tags( strip_shortcodes( $product->get_description() ) );
        $short_desc  = wp_strip_all_tags( strip_shortcodes( $product->get_short_description() ) );
        $sku         = $product->get_sku();

        // Get Categories & Tags
        $categories = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $tags       = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] );
        $cat_str    = is_array( $categories ) ? implode( ', ', $categories ) : '';
        $tag_str    = is_array( $tags ) ? implode( ', ', $tags ) : '';

        // Attributes & Variations
        $attributes_str = '';
        if ( $product->has_attributes() ) {
            $attrs = [];
            foreach ( $product->get_attributes() as $attr ) {
                if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
                    $options = $attr->get_options();
                    $attrs[] = ( method_exists( $attr, 'get_name' ) ? $attr->get_name() . ': ' : '' ) . implode( ' ', $options );
                }
            }
            $attributes_str = implode( ' ', $attrs );
        }

        // Variable Product variations attributes & prices
        $variation_info = '';
        if ( $product->is_type( 'variable' ) ) {
            $children = $product->get_children();
            $var_skus = [];
            foreach ( array_slice( $children, 0, 20 ) as $child_id ) {
                $variation = wc_get_product( $child_id );
                if ( $variation ) {
                    if ( $variation->get_sku() ) $var_skus[] = $variation->get_sku();
                }
            }
            if ( ! empty( $var_skus ) ) {
                $variation_info = 'Variation SKUs: ' . implode( ', ', $var_skus );
            }
        }

        $searchable_parts = [
            $title,
            'SKU: ' . $sku,
            'Categories: ' . $cat_str,
            'Tags: ' . $tag_str,
            'Attributes: ' . $attributes_str,
            $variation_info,
            $short_desc,
            $desc
        ];

        $searchable = implode( ' | ', array_filter( $searchable_parts ) );
        $searchable = preg_replace( '/\s+/', ' ', $searchable );

        $price = $product->get_price();
        if ( ! empty( $price ) && is_numeric( $price ) ) {
            $formatted_price = get_woocommerce_currency_symbol() . ' ' . number_format( (float) $price, 2 );
        } else {
            $formatted_price = $product->get_price_html() ? wp_strip_all_tags( $product->get_price_html() ) : 'N/A';
        }

        $stock_status = $product->is_in_stock() ? 'In Stock' : 'Out of Stock';
        $image_id     = $product->get_image_id();
        $image_url    = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
        $permalink    = get_permalink( $product_id );

        $meta = [
            'sku'        => $sku,
            'categories' => $categories,
            'tags'       => $tags,
            'regular_price' => $product->get_regular_price(),
            'sale_price'    => $product->get_sale_price(),
            'is_on_sale'    => $product->is_on_sale(),
        ];

        $table = self::get_table_name();

        return $wpdb->replace(
            $table,
            [
                'content_type'    => 'product',
                'ref_id'          => $product_id,
                'title'           => $title,
                'searchable_text' => trim( $searchable ),
                'price'           => $formatted_price,
                'stock_status'    => $stock_status,
                'image_url'       => $image_url,
                'permalink'       => $permalink,
                'meta_json'       => json_encode( $meta ),
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Remove item from index
     */
    public function remove_index( $type, $ref_id ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->delete( $table, [ 'content_type' => $type, 'ref_id' => $ref_id ], [ '%s', '%d' ] );
    }

    /**
     * Reindex all published pages and products
     */
    public function reindex_all() {
        global $wpdb;

        // Ensure table exists before indexing
        self::create_table();

        // Reindex Pages & Posts
        $page_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page', 'post') AND post_status = 'publish'" );
        $indexed_pages = 0;
        if ( ! empty( $page_ids ) && is_array( $page_ids ) ) {
            foreach ( $page_ids as $page_id ) {
                if ( $this->index_page( $page_id ) !== false ) {
                    $indexed_pages++;
                }
            }
        }

        // Reindex WooCommerce Products
        $indexed_products = 0;
        if ( class_exists( 'WooCommerce' ) ) {
            $product_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
            if ( ! empty( $product_ids ) && is_array( $product_ids ) ) {
                foreach ( $product_ids as $product_id ) {
                    if ( $this->index_product( $product_id ) !== false ) {
                        $indexed_products++;
                    }
                }
            }
        }

        return [
            'pages'    => $indexed_pages,
            'products' => $indexed_products,
        ];
    }

    /**
     * Get statistics on indexed items
     */
    public static function get_index_stats() {
        global $wpdb;
        $table = self::get_table_name();

        // Check if table exists
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return [
                'table_exists'     => false,
                'total_indexed'    => 0,
                'products_indexed' => 0,
                'pages_indexed'    => 0,
                'last_updated'     => 'Never',
            ];
        }

        $total    = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $products = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE content_type = 'product'" );
        $pages    = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE content_type IN ('page', 'page_item')" );
        $last_upd = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$table}" );

        return [
            'table_exists'     => true,
            'total_indexed'    => (int) $total,
            'products_indexed' => (int) $products,
            'pages_indexed'    => (int) $pages,
            'last_updated'     => $last_upd ?: 'Never',
        ];
    }

}
