<?php
defined( 'ABSPATH' ) || exit;

class AAM_Indexer {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aam_content_index';
    }

    public static function get_chunks_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aam_content_chunks';
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

        self::create_chunks_table();
    }

    public static function create_chunks_table() {
        global $wpdb;

        $table   = self::get_chunks_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            chunk_index INT(11) NOT NULL DEFAULT 0,
            section_title VARCHAR(255) NOT NULL DEFAULT '',
            chunk_type VARCHAR(50) NOT NULL DEFAULT 'text',
            url VARCHAR(500) NOT NULL DEFAULT '',
            clean_content LONGTEXT NOT NULL,
            embedding LONGTEXT NULL,
            embedding_dim INT(11) DEFAULT 0,
            embedding_model VARCHAR(100) DEFAULT '',
            token_count INT(11) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id_idx (post_id),
            KEY chunk_type_idx (chunk_type),
            FULLTEXT KEY chunk_fulltext (section_title, clean_content)
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

        // Remove from index on delete or trash
        add_action( 'before_delete_post', [ $this, 'on_delete_post' ], 10, 1 );
        add_action( 'wp_trash_post', [ $this, 'on_delete_post' ], 10, 1 );
        add_action( 'untrash_post', [ $this, 'on_untrash_post' ], 10, 1 );
    }

    public function on_untrash_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;
        if ( in_array( $post->post_type, [ 'page', 'post' ], true ) && 'publish' === $post->post_status ) {
            $this->index_page( $post_id );
        } elseif ( 'product' === $post->post_type && 'publish' === $post->post_status ) {
            $this->index_product( $post_id );
        }
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

        // Clean up semantic chunks
        global $wpdb;
        $chunks_table = self::get_chunks_table_name();
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $chunks_table ) ) === $chunks_table ) {
            $wpdb->delete( $chunks_table, [ 'post_id' => $post_id ], [ '%d' ] );
        }
    }

    /**
     * Index a WordPress Page or Post using rendered HTML parsing (DOMDocument + DOMXPath)
     */
    public function index_page( $page_id ) {
        global $wpdb;

        $post = get_post( $page_id );
        if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
            $this->remove_index( 'page', $page_id );
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

        // 2. Render content: Check for Elementor Frontend Builder content first
        $rendered_html = '';
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->frontend ) ) {
            $elem_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $page_id );
            if ( ! empty( trim( $elem_content ) ) ) {
                $rendered_html = $elem_content;
            }
        }

        if ( empty( trim( $rendered_html ) ) ) {
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
        }

        if ( empty( trim( $rendered_html ) ) ) {
            $rendered_html = $post->post_content;
        }

        $extracted_items = [];
        $processed_nodes = [];

        // 3. Deep Extraction from Elementor Postmeta JSON (_elementor_data)
        $elem_meta_items = $this->extract_elementor_widgets( $page_id, $page_title, $permalink, $page_thumb );
        if ( ! empty( $elem_meta_items ) ) {
            foreach ( $elem_meta_items as $em_item ) {
                $extracted_items[] = $em_item;
            }
        }

        // 4. Clean & Load HTML via DOMDocument
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        @$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $rendered_html );
        $xpath = new DOMXPath( $dom );
        libxml_clear_errors();

        // 5. Remove Noise (scripts, styles, navs, footers, display:none, hidden elements)
        $noise_nodes = $xpath->query( '//script | //style | //nav | //footer | //header | //*[contains(@style,"display:none")] | //*[contains(@class,"hidden")]' );
        if ( $noise_nodes ) {
            foreach ( $noise_nodes as $node ) {
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        // 6A. Specialized Extraction: Pricing Tables & Subscription Cards (Highest Priority)
        // Detects Elementor Price Tables, custom pricing cards, and containers with prices & plans
        $pricing_cards = $xpath->query(
            '//*[contains(@class,"price-table") or contains(@class,"pricing-table") or contains(@class,"pricing-card") or contains(@class,"plan-card") or contains(@class,"subscription-card") or contains(@class,"package-card") or contains(@class,"elementor-widget-price-table")]' .
            ' | //div[(.//h1 or .//h2 or .//h3 or .//h4 or .//h5 or .//strong) and (contains(.,"$") or contains(., "€") or contains(., "£") or contains(., "Rs") or contains(., "per year") or contains(., "per month") or contains(., "/yr") or contains(., "/mo") or contains(., "Subscription"))]'
        );

        if ( $pricing_cards && $pricing_cards->length > 0 ) {
            $pricing_list = [];
            foreach ( $pricing_cards as $pc ) {
                $pricing_list[] = $pc;
            }

            // Sort deeper first
            usort( $pricing_list, function( $a, $b ) {
                $depth_a = 0; $p = $a; while ( $p->parentNode ) { $depth_a++; $p = $p->parentNode; }
                $depth_b = 0; $p = $b; while ( $p->parentNode ) { $depth_b++; $p = $p->parentNode; }
                return $depth_b - $depth_a;
            } );

            foreach ( $pricing_list as $card ) {
                $already_processed = false;
                $parent = $card;
                while ( $parent ) {
                    if ( in_array( $parent, $processed_nodes, true ) ) {
                        $already_processed = true;
                        break;
                    }
                    $parent = $parent->parentNode;
                }
                if ( $already_processed ) continue;

                $card_text = trim( $card->textContent );
                if ( strlen( $card_text ) < 15 ) continue;

                // Find plan heading
                $heading_node = $xpath->query( './/*[contains(@class,"heading") or contains(@class,"title") or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::strong]', $card )->item( 0 );
                if ( ! $heading_node ) continue;
                $plan_title = trim( wp_strip_all_tags( $heading_node->textContent ) );
                if ( empty( $plan_title ) || strlen( $plan_title ) < 2 ) continue;

                // Extract exact price string (e.g. $39, $97, $39 per year, $97 for 3 full years)
                $price_str = '';
                $price_node = $xpath->query( './/*[contains(@class,"price") or contains(@class,"amount") or contains(@class,"cost")]', $card )->item( 0 );
                if ( $price_node ) {
                    $raw_p = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $price_node->textContent ) ) );
                    if ( preg_match( '/(?:[\$€£]|Rs\.?)\s*\d+(?:\.\d{2})?(?:\s*(?:per|\/|for)?\s*[\w\.\(\)\s]+)?/i', $raw_p, $pm ) ) {
                        $price_str = trim( $pm[0] );
                    }
                }
                if ( empty( $price_str ) && preg_match( '/(?:[\$€£]|Rs\.?)\s*\d+(?:\.\d{2})?(?:\s*(?:per|\/|for)?\s*(?:year|month|yr|mo|full years|life|site))?/i', $card_text, $pm ) ) {
                    $price_str = trim( $pm[0] );
                }

                // Extract feature list items
                $features = [];
                $li_nodes = $xpath->query( './/ul//li | .//*[contains(@class,"features-list")]//li | .//*[contains(@class,"icon-list-item")]', $card );
                if ( $li_nodes && $li_nodes->length > 0 ) {
                    foreach ( $li_nodes as $li ) {
                        $ftxt = trim( wp_strip_all_tags( $li->textContent ) );
                        if ( ! empty( $ftxt ) && strlen( $ftxt ) >= 3 ) {
                            $features[] = $ftxt;
                        }
                    }
                }

                // Extract discount notes or subtitles
                $extra_info = [];
                $sub_node = $xpath->query( './/*[contains(@class,"subheading") or contains(@class,"sub-title") or contains(@class,"badge") or contains(@class,"discount") or contains(@class,"ribbon")]', $card )->item( 0 );
                if ( $sub_node ) {
                    $stxt = trim( wp_strip_all_tags( $sub_node->textContent ) );
                    if ( ! empty( $stxt ) && $stxt !== $plan_title ) {
                        $extra_info[] = $stxt;
                    }
                }

                $full_details = "Plan: {$plan_title}.";
                if ( $price_str ) $full_details .= " Official Price: {$price_str}.";
                if ( ! empty( $extra_info ) ) $full_details .= " Note: " . implode( ', ', $extra_info ) . ".";
                if ( ! empty( $features ) ) {
                    $full_details .= " Included Features: " . implode( '; ', $features ) . ".";
                } else {
                    $clean_body = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $card_text ) );
                    $full_details .= " Details: " . wp_trim_words( $clean_body, 50 ) . ".";
                }
                $full_details .= " (Page: {$page_title})";

                $extracted_items[] = [
                    'title'     => $plan_title,
                    'price'     => $price_str,
                    'text'      => $full_details,
                    'image_url' => $page_thumb,
                    'permalink' => $permalink,
                ];

                $processed_nodes[] = $card;
            }
        }

        // 6B. Card-like clusters (gallery items, product grids, service cards with images)
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
                            'price'     => '',
                            'text'      => $item_desc ? ( $item_title . ' - ' . $item_desc . ' (Page: ' . $page_title . ')' ) : ( $item_title . ' (Page: ' . $page_title . ')' ),
                            'image_url' => $item_img ?: $page_thumb,
                            'permalink' => $item_link,
                        ];

                        $processed_nodes[] = $card;
                    }
                }
            }
        }

        // 6C. Accordions & FAQs (e.g. Elementor Accordion, Toggle)
        $accordion_items = $xpath->query( '//*[contains(@class,"accordion-item") or contains(@class,"toggle-item") or contains(@class,"faq-item")]' );
        if ( $accordion_items && $accordion_items->length > 0 ) {
            foreach ( $accordion_items as $acc ) {
                $title_node = $xpath->query( './/*[contains(@class,"title") or contains(@class,"tab-title") or self::h3 or self::h4 or self::h5 or self::strong]', $acc )->item( 0 );
                $content_node = $xpath->query( './/*[contains(@class,"content") or contains(@class,"tab-content") or self::p]', $acc )->item( 0 );
                if ( $title_node && $content_node ) {
                    $q_title = trim( wp_strip_all_tags( $title_node->textContent ) );
                    $a_text  = trim( wp_strip_all_tags( $content_node->textContent ) );
                    if ( strlen( $q_title ) >= 4 && strlen( $a_text ) >= 5 ) {
                        $extracted_items[] = [
                            'title'     => $q_title,
                            'price'     => '',
                            'text'      => "FAQ: {$q_title} Answer: {$a_text} (Page: {$page_title})",
                            'image_url' => $page_thumb,
                            'permalink' => $permalink,
                        ];
                        $processed_nodes[] = $acc;
                    }
                }
            }
        }

        // 6D. Flowing content chunking (heading-wise sections)
        $headings = $xpath->query( '//h2 | //h3' );
        if ( $headings && $headings->length > 0 ) {
            foreach ( $headings as $h ) {
                $section_title = trim( wp_strip_all_tags( $h->textContent ) );
                if ( empty( $section_title ) || strlen( $section_title ) < 3 ) {
                    continue;
                }

                // Check if this heading was already processed in pricing or card
                $parent = $h;
                $already_done = false;
                while ( $parent ) {
                    if ( in_array( $parent, $processed_nodes, true ) ) {
                        $already_done = true;
                        break;
                    }
                    $parent = $parent->parentNode;
                }
                if ( $already_done ) continue;

                $section_text_parts = [];
                $curr = $h->nextSibling;
                $sibling_depth = 0;
                while ( $curr && $sibling_depth < 15 ) {
                    $node_name = strtolower( $curr->nodeName );
                    if ( in_array( $node_name, [ 'h1', 'h2', 'h3', 'h4' ], true ) ) {
                        break;
                    }
                    $txt = trim( wp_strip_all_tags( $curr->textContent ) );
                    if ( ! empty( $txt ) ) {
                        $section_text_parts[] = $txt;
                    }
                    $curr = $curr->nextSibling;
                    $sibling_depth++;
                }

                // If nextSibling was empty due to container nesting, check parent container text
                if ( empty( $section_text_parts ) && $h->parentNode ) {
                    $parent_txt = trim( wp_strip_all_tags( $h->parentNode->textContent ) );
                    if ( strlen( $parent_txt ) > strlen( $section_title ) + 10 ) {
                        $section_text_parts[] = substr( $parent_txt, strlen( $section_title ) );
                    }
                }

                $section_text = trim( preg_replace( '/\s+/', ' ', implode( ' ', $section_text_parts ) ) );
                if ( ! empty( $section_text ) && strlen( $section_text ) >= 20 ) {
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
                            'price'     => '',
                            'text'      => $section_title . ' - ' . wp_trim_words( $section_text, 80 ) . ' (Page: ' . $page_title . ')',
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
                    'price'           => ! empty( $item['price'] ) ? sanitize_text_field( $item['price'] ) : '',
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

        // Index semantic chunks into wp_aam_content_chunks
        $this->index_page_chunks( $post );

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
     * Deep extraction of Elementor widgets directly from postmeta _elementor_data JSON
     *
     * Directly parses pricing tables, packages, accordions, toggles, icon boxes,
     * call to actions, and feature grids built in Elementor or Elementor Addons.
     */
    private function extract_elementor_widgets( $page_id, $page_title, $permalink, $page_thumb ) {
        $raw_json = get_post_meta( $page_id, '_elementor_data', true );
        if ( empty( $raw_json ) ) {
            return [];
        }

        $elements = is_array( $raw_json ) ? $raw_json : json_decode( $raw_json, true );
        if ( ! is_array( $elements ) ) {
            return [];
        }

        $items = [];
        $this->parse_elementor_elements_recursive( $elements, $page_id, $page_title, $permalink, $page_thumb, $items );
        return $items;
    }

    private function parse_elementor_elements_recursive( $elements, $page_id, $page_title, $permalink, $page_thumb, &$items ) {
        if ( ! is_array( $elements ) ) {
            return;
        }

        foreach ( $elements as $elem ) {
            if ( ! is_array( $elem ) ) {
                continue;
            }

            $el_type     = $elem['elType'] ?? '';
            $widget_type = $elem['widgetType'] ?? '';
            $settings    = $elem['settings'] ?? [];

            if ( $el_type === 'widget' && ! empty( $settings ) ) {
                // 1. Pricing Tables / Packages (Elementor Pro Price Table, EAEL, JetElements, etc.)
                if ( in_array( $widget_type, [ 'price-table', 'pricing-table', 'eael-pricing-table', 'pricing', 'jet-pricing-table' ], true )
                     || ( ! empty( $settings['heading'] ) && ( isset( $settings['price'] ) || isset( $settings['pricing_price'] ) ) ) ) {

                    $heading = trim( wp_strip_all_tags( $settings['heading'] ?? ( $settings['title'] ?? '' ) ) );
                    $sub     = trim( wp_strip_all_tags( $settings['sub_heading'] ?? ( $settings['subtitle'] ?? '' ) ) );
                    $symbol  = trim( $settings['currency_symbol'] ?? ( $settings['currency'] ?? '$' ) );
                    $price   = trim( (string) ( $settings['price'] ?? ( $settings['pricing_price'] ?? '' ) ) );
                    $period  = trim( wp_strip_all_tags( $settings['period'] ?? ( $settings['duration'] ?? '' ) ) );

                    if ( ! empty( $heading ) && ! empty( $price ) ) {
                        $period_clean = '';
                        if ( ! empty( $period ) ) {
                            $period_clean = ( strpos( $period, '/' ) === 0 || strpos( $period, 'per' ) === 0 ) ? ( ' ' . $period ) : ( ' / ' . $period );
                        }
                        $price_str = $symbol . $price . $period_clean;

                        $features = [];
                        if ( ! empty( $settings['features_list'] ) && is_array( $settings['features_list'] ) ) {
                            foreach ( $settings['features_list'] as $feat ) {
                                $ft = trim( wp_strip_all_tags( $feat['item_text'] ?? ( $feat['text'] ?? '' ) ) );
                                if ( ! empty( $ft ) ) {
                                    $features[] = $ft;
                                }
                            }
                        }

                        $btn_text = trim( wp_strip_all_tags( $settings['button_text'] ?? '' ) );
                        $btn_url  = trim( $settings['link']['url'] ?? '' );
                        if ( empty( $btn_url ) || $btn_url === '#' ) {
                            $btn_url = $permalink;
                        }

                        $details = "Plan: {$heading}. Official Price: {$price_str}.";
                        if ( ! empty( $sub ) ) {
                            $details .= " Subtitle: {$sub}.";
                        }
                        if ( ! empty( $features ) ) {
                            $details .= " Included Features: " . implode( '; ', $features ) . ".";
                        }
                        if ( ! empty( $btn_text ) ) {
                            $details .= " Action: {$btn_text} ({$btn_url}).";
                        }
                        $details .= " (Page: {$page_title})";

                        $items[] = [
                            'title'     => $heading,
                            'price'     => $price_str,
                            'text'      => $details,
                            'image_url' => $page_thumb,
                            'permalink' => $btn_url,
                        ];
                    }
                }

                // 2. Accordions & Toggles / FAQs
                if ( in_array( $widget_type, [ 'accordion', 'toggle', 'eael-adv-accordion', 'jet-accordion' ], true ) ) {
                    $tabs = $settings['tabs'] ?? ( $settings['accordion_items'] ?? [] );
                    if ( is_array( $tabs ) ) {
                        foreach ( $tabs as $tab ) {
                            $tab_title   = trim( wp_strip_all_tags( $tab['tab_title'] ?? ( $tab['title'] ?? '' ) ) );
                            $tab_content = trim( wp_strip_all_tags( $tab['tab_content'] ?? ( $tab['content'] ?? '' ) ) );
                            if ( strlen( $tab_title ) >= 3 && strlen( $tab_content ) >= 4 ) {
                                $items[] = [
                                    'title'     => $tab_title,
                                    'price'     => '',
                                    'text'      => "FAQ: {$tab_title} Answer: {$tab_content} (Page: {$page_title})",
                                    'image_url' => $page_thumb,
                                    'permalink' => $permalink,
                                ];
                            }
                        }
                    }
                }

                // 3. Icon Box / Image Box / CTA
                if ( in_array( $widget_type, [ 'icon-box', 'image-box', 'call-to-action' ], true ) ) {
                    $title = trim( wp_strip_all_tags( $settings['title_text'] ?? ( $settings['title'] ?? '' ) ) );
                    $desc  = trim( wp_strip_all_tags( $settings['description_text'] ?? ( $settings['description'] ?? '' ) ) );
                    $url   = trim( $settings['link']['url'] ?? '' );
                    if ( strlen( $title ) >= 3 && strlen( $desc ) >= 10 ) {
                        $items[] = [
                            'title'     => $title,
                            'price'     => '',
                            'text'      => "{$title} - {$desc} (Page: {$page_title})",
                            'image_url' => $page_thumb,
                            'permalink' => ( ! empty( $url ) && $url !== '#' ) ? $url : $permalink,
                        ];
                    }
                }
            }

            // Recurse child elements (sections, columns, nested containers)
            if ( ! empty( $elem['elements'] ) && is_array( $elem['elements'] ) ) {
                $this->parse_elementor_elements_recursive( $elem['elements'], $page_id, $page_title, $permalink, $page_thumb, $items );
            }
        }
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

        // Skip hidden catalog items
        if ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === $product->get_catalog_visibility() ) {
            $this->remove_index( 'product', $product_id );
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
            $var_prices = [];
            foreach ( array_slice( $children, 0, 25 ) as $child_id ) {
                $variation = wc_get_product( $child_id );
                if ( $variation ) {
                    if ( $variation->get_sku() ) $var_skus[] = $variation->get_sku();
                    $vp = $variation->get_price();
                    if ( ! empty( $vp ) ) $var_prices[] = $vp;
                }
            }
            if ( ! empty( $var_skus ) ) {
                $variation_info = 'Variation SKUs: ' . implode( ', ', $var_skus );
            }
            if ( ! empty( $var_prices ) ) {
                $variation_info .= ' | Variation Prices: ' . implode( ', ', array_unique( $var_prices ) );
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

        $result = $wpdb->replace(
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

        // Index product chunks into wp_aam_content_chunks so Hybrid Search finds it
        $this->index_product_chunks( $product_id );

        return $result;
    }

    /**
     * Index a post or page into semantic atomic blocks with vector embeddings
     */
    public function index_page_chunks( $post ) {
        if ( ! class_exists( 'AAM_Chunker' ) || ! class_exists( 'AAM_Embedding_Adapter' ) ) {
            return;
        }

        global $wpdb;
        $chunks_table = self::get_chunks_table_name();
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $chunks_table ) ) !== $chunks_table ) {
            self::create_chunks_table();
        }

        // Delete existing chunks for this post
        $wpdb->delete( $chunks_table, [ 'post_id' => $post->ID ], [ '%d' ] );

        $chunks = AAM_Chunker::chunk_post( $post );
        if ( empty( $chunks ) ) {
            return;
        }

        foreach ( $chunks as $idx => $chunk ) {
            $content = $chunk['clean_content'];
            $emb_data = AAM_Embedding_Adapter::generate_embedding( $content );
            $embedding_json = ( $emb_data && ! empty( $emb_data['vector'] ) ) ? wp_json_encode( $emb_data['vector'] ) : null;
            $embedding_dim  = $emb_data['dim'] ?? 0;
            $embedding_model = $emb_data['model'] ?? '';

            $wpdb->insert(
                $chunks_table,
                [
                    'post_id'         => $post->ID,
                    'chunk_index'     => $idx,
                    'section_title'   => $chunk['section_title'],
                    'chunk_type'      => $chunk['chunk_type'],
                    'url'             => $chunk['url'],
                    'clean_content'   => $content,
                    'embedding'       => $embedding_json,
                    'embedding_dim'   => $embedding_dim,
                    'embedding_model' => $embedding_model,
                    'token_count'     => $chunk['token_count'] ?? str_word_count( $content ),
                    'updated_at'      => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
            );
        }
    }

    /**
     * Index a WooCommerce product into an atomic product chunk with vector embeddings
     */
    public function index_product_chunks( $product_id ) {
        if ( ! class_exists( 'AAM_Chunker' ) || ! class_exists( 'AAM_Embedding_Adapter' ) ) {
            return;
        }

        global $wpdb;
        $chunks_table = self::get_chunks_table_name();
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $chunks_table ) ) !== $chunks_table ) {
            self::create_chunks_table();
        }

        // Delete existing chunks for this product
        $wpdb->delete( $chunks_table, [ 'post_id' => $product_id ], [ '%d' ] );

        $chunks = AAM_Chunker::chunk_product( $product_id );
        if ( empty( $chunks ) ) {
            return;
        }

        foreach ( $chunks as $idx => $chunk ) {
            $content = $chunk['clean_content'];
            $emb_data = AAM_Embedding_Adapter::generate_embedding( $content );
            $embedding_json = ( $emb_data && ! empty( $emb_data['vector'] ) ) ? wp_json_encode( $emb_data['vector'] ) : null;
            $embedding_dim  = $emb_data['dim'] ?? 0;
            $embedding_model = $emb_data['model'] ?? '';

            $wpdb->insert(
                $chunks_table,
                [
                    'post_id'         => $product_id,
                    'chunk_index'     => $idx,
                    'section_title'   => $chunk['section_title'],
                    'chunk_type'      => 'product',
                    'url'             => $chunk['url'],
                    'clean_content'   => $content,
                    'embedding'       => $embedding_json,
                    'embedding_dim'   => $embedding_dim,
                    'embedding_model' => $embedding_model,
                    'token_count'     => $chunk['token_count'] ?? str_word_count( $content ),
                    'updated_at'      => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
            );
        }
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

        // Ensure tables exist before indexing
        self::create_table();
        self::create_chunks_table();

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
        if ( class_exists( 'WooCommerce' ) || function_exists( 'wc_get_products' ) ) {
            if ( function_exists( 'wc_get_products' ) ) {
                $product_ids = wc_get_products( [ 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ] );
            } else {
                $product_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
            }
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
     * Get statistics on indexed items and semantic chunks
     */
    public static function get_index_stats() {
        global $wpdb;
        $table = self::get_table_name();
        $chunks_table = self::get_chunks_table_name();

        // Check if table exists
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return [
                'table_exists'      => false,
                'total_indexed'     => 0,
                'products_indexed'  => 0,
                'pages_indexed'     => 0,
                'total_chunks'      => 0,
                'pricing_chunks'    => 0,
                'faq_chunks'        => 0,
                'vectorized_chunks' => 0,
                'last_updated'      => 'Never',
            ];
        }

        $total    = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $products = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE content_type = 'product'" );
        $pages    = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE content_type IN ('page', 'page_item')" );
        $last_upd = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$table}" );

        $total_chunks      = 0;
        $pricing_chunks    = 0;
        $faq_chunks        = 0;
        $vectorized_chunks = 0;

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $chunks_table ) ) === $chunks_table ) {
            $total_chunks      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table}" );
            $pricing_chunks    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table} WHERE chunk_type = 'pricing'" );
            $faq_chunks        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table} WHERE chunk_type = 'faq'" );
            $vectorized_chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table} WHERE embedding IS NOT NULL AND embedding != ''" );
        }

        return [
            'table_exists'      => true,
            'total_indexed'     => (int) $total,
            'products_indexed'  => (int) $products,
            'pages_indexed'     => (int) $pages,
            'total_chunks'      => $total_chunks,
            'pricing_chunks'    => $pricing_chunks,
            'faq_chunks'        => $faq_chunks,
            'vectorized_chunks' => $vectorized_chunks,
            'last_updated'      => $last_upd ?: 'Never',
        ];
    }

}
