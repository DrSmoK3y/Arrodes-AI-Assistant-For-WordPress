<?php
defined( 'ABSPATH' ) || exit;

/**
 * AAM_Chunker
 * 
 * Semantic Block Chunking Engine for WordPress AI Manager.
 * Breaks pages, posts, Elementor layouts, and WooCommerce products into coherent,
 * complete semantic chunks (Headings, FAQ pairs, Pricing tables, Products, Policies)
 * preserving complete sentences and preventing arbitrary 50-word truncation.
 */
class AAM_Chunker {

    /**
     * Chunk a WordPress post/page into semantic blocks
     *
     * @param int|WP_Post $post Post ID or WP_Post object
     * @return array Array of chunk objects with title, section_title, chunk_type, clean_content, url, token_count
     */
    public static function chunk_post( $post ) {
        if ( is_numeric( $post ) ) {
            $post = get_post( $post );
        }

        if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
            return [];
        }

        $chunks    = [];
        $permalink = get_permalink( $post->ID );
        $post_title = wp_strip_all_tags( $post->post_title );

        // 1. Render complete HTML (handling Elementor dynamic widgets, ACF, shortcodes, and tabs)
        $rendered_html = '';
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->frontend ) ) {
            $elem_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post->ID );
            if ( ! empty( trim( $elem_content ) ) ) {
                $rendered_html = $elem_content;
            }
        }

        if ( empty( trim( $rendered_html ) ) ) {
            $saved_post = $GLOBALS['post'] ?? null;
            $GLOBALS['post'] = $post;
            setup_postdata( $post );
            $rendered_html = apply_filters( 'the_content', $post->post_content );
            if ( $saved_post ) {
                $GLOBALS['post'] = $saved_post;
                setup_postdata( $saved_post );
            } else {
                wp_reset_postdata();
            }
        }

        if ( empty( trim( $rendered_html ) ) ) {
            $rendered_html = $post->post_content;
        }

        // 2. Primary: Parse rendered HTML sections (DOMDocument, Headings, Tables, FAQs, Accordions)
        if ( ! empty( $rendered_html ) ) {
            $dom_chunks = self::parse_html_sections( $rendered_html, $post_title, $permalink );
            if ( ! empty( $dom_chunks ) ) {
                $chunks = array_merge( $chunks, $dom_chunks );
            }
        }

        // 3. Fallback: If rendered HTML produced few chunks, parse raw Elementor JSON meta (_elementor_data)
        $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
        if ( count( $chunks ) < 2 && ! empty( $elementor_data ) ) {
            $elementor_chunks = self::parse_elementor_data( $elementor_data, $post_title, $permalink );
            if ( ! empty( $elementor_chunks ) ) {
                $chunks = array_merge( $chunks, $elementor_chunks );
            }
        }

        // 4. Final Fallback: Intact full-document chunk
        if ( empty( $chunks ) ) {
            $clean_text = self::clean_text( $post->post_content );
            if ( ! empty( $clean_text ) ) {
                $chunks[] = [
                    'section_title' => $post_title,
                    'chunk_type'    => 'general',
                    'clean_content' => $post_title . "\n" . $clean_text,
                    'url'           => $permalink,
                    'token_count'   => str_word_count( $clean_text ),
                ];
            }
        }

        // De-duplicate any nearly identical chunks
        return self::deduplicate_chunks( $chunks );
    }

    /**
     * Chunk a WooCommerce product into an atomic, comprehensive product chunk
     */
    public static function chunk_product( $product_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return [];
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return [];
        }

        $title      = wp_strip_all_tags( $product->get_name() );
        $price      = $product->get_price();
        $stock      = $product->is_in_stock() ? 'In Stock' : 'Out of Stock';
        $permalink  = get_permalink( $product->get_id() );
        $short_desc = wp_strip_all_tags( $product->get_short_description() );
        $full_desc  = wp_strip_all_tags( $product->get_description() );

        $raw_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
        $currency_symbol = html_entity_decode( $raw_symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $desc = ! empty( $short_desc ) ? $short_desc : $full_desc;

        // Categories & Tags
        $categories = wp_strip_all_tags( wc_get_product_category_list( $product->get_id(), ', ' ) );
        $tags       = wp_strip_all_tags( wc_get_product_tag_list( $product->get_id(), ', ' ) );

        // Attributes
        $attr_pairs = [];
        $attributes = $product->get_attributes();
        if ( ! empty( $attributes ) ) {
            foreach ( $attributes as $attr ) {
                if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
                    $name = wc_attribute_label( $attr->get_name() );
                    $opts = $attr->get_options();
                    $val  = is_array( $opts ) ? implode( ', ', $opts ) : $opts;
                    if ( ! empty( $val ) ) {
                        $attr_pairs[] = "{$name}: {$val}";
                    }
                }
            }
        }
        $attrs_str = implode( '; ', $attr_pairs );

        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
        if ( empty( $image_url ) && $image_id ) {
            $image_url = wp_get_attachment_url( $image_id );
        }

        $add_to_cart_url = function_exists( 'wc_get_checkout_url' ) 
            ? add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() ) 
            : home_url( "/cart/?add-to-cart={$product_id}" );

        $content_lines = [
            "PRODUCT: {$title}",
            "PRODUCT_ID: {$product_id}",
            "PRICE: {$currency_symbol}{$price}",
            "STATUS: {$stock}",
            "LINK: {$permalink}",
        ];
        if ( ! empty( $image_url ) )       $content_lines[] = "IMAGE: {$image_url}";
        if ( ! empty( $add_to_cart_url ) ) $content_lines[] = "ADD_TO_CART: {$add_to_cart_url}";
        if ( ! empty( $categories ) )      $content_lines[] = "CATEGORY: {$categories}";
        if ( ! empty( $tags ) )            $content_lines[] = "TAGS: {$tags}";
        if ( ! empty( $attrs_str ) )       $content_lines[] = "SPECIFICATIONS: {$attrs_str}";
        if ( ! empty( $desc ) )            $content_lines[] = "DETAILS: {$desc}";

        $clean_content = implode( "\n", $content_lines );

        return [
            [
                'post_id'         => (int) $product_id,
                'section_title'   => $title,
                'chunk_type'      => 'product',
                'clean_content'   => $clean_content,
                'url'             => $permalink,
                'token_count'     => str_word_count( $clean_content ),
                'price'           => $price,
                'stock_status'    => $stock,
                'image_url'       => $image_url,
                'add_to_cart_url' => $add_to_cart_url,
            ]
        ];
    }

    /**
     * Parse HTML by headings and container tags into semantic blocks
     */
    private static function parse_html_sections( $html, $post_title, $permalink ) {
        if ( empty( trim( $html ) ) ) {
            return [];
        }

        // Clean WordPress shortcodes
        $html = strip_shortcodes( $html );

        // Use DOMDocument with libxml error suppression
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $encoded = mb_convert_encoding( '<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8' );
        $dom->loadHTML( $encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );
        $chunks = [];

        // 1. Detect Tables (Pricing, Comparison, Feature matrices)
        $tables = $xpath->query( '//table' );
        if ( $tables && $tables->length > 0 ) {
            foreach ( $tables as $table ) {
                $table_text = self::convert_table_to_text( $table );
                if ( ! empty( $table_text ) && strlen( $table_text ) > 30 ) {
                    $is_pricing = (bool) preg_match( '/(\$|€|£|Rs|price|plan|tier|month|year|license)/i', $table_text );
                    $chunks[] = [
                        'section_title' => $is_pricing ? "Pricing & Comparison - {$post_title}" : "Table: {$post_title}",
                        'chunk_type'    => $is_pricing ? 'pricing' : 'table',
                        'clean_content' => $table_text,
                        'url'           => $permalink,
                        'token_count'   => str_word_count( $table_text ),
                    ];
                }
            }
        }

        // 2. Detect FAQ Accordions / Details
        $accordions = $xpath->query( '//details | //div[contains(@class, "faq") or contains(@class, "accordion")]' );
        if ( $accordions && $accordions->length > 0 ) {
            foreach ( $accordions as $accordion ) {
                $acc_text = trim( preg_replace( '/\s+/', ' ', $accordion->textContent ) );
                if ( ! empty( $acc_text ) && strlen( $acc_text ) > 25 ) {
                    $chunks[] = [
                        'section_title' => "FAQ - {$post_title}",
                        'chunk_type'    => 'faq',
                        'clean_content' => $acc_text,
                        'url'           => $permalink,
                        'token_count'   => str_word_count( $acc_text ),
                    ];
                }
            }
        }

        // 3. Segment by Headings (H1, H2, H3, H4)
        $heading_nodes = $xpath->query( '//h1 | //h2 | //h3 | //h4' );
        if ( $heading_nodes && $heading_nodes->length > 0 ) {
            foreach ( $heading_nodes as $heading ) {
                $heading_title = trim( $heading->textContent );
                if ( empty( $heading_title ) || strlen( $heading_title ) < 2 ) {
                    continue;
                }

                // Gather sibling text until next heading
                $section_content = [];
                $node = $heading->nextSibling;

                while ( $node ) {
                    if ( in_array( strtolower( $node->nodeName ), [ 'h1', 'h2', 'h3', 'h4' ], true ) ) {
                        break;
                    }

                    if ( $node->nodeType === XML_ELEMENT_NODE || $node->nodeType === XML_TEXT_NODE ) {
                        $txt = trim( $node->textContent );
                        if ( ! empty( $txt ) ) {
                            $section_content[] = $txt;
                        }
                    }
                    $node = $node->nextSibling;
                }

                $combined_text = implode( "\n", $section_content );
                $combined_text = trim( preg_replace( '/\s+/', ' ', $combined_text ) );

                if ( ! empty( $combined_text ) && strlen( $combined_text ) > 20 ) {
                    // Classify chunk type
                    $chunk_type = 'heading';
                    if ( preg_match( '/(price|plan|cost|tier|pricing|package|fee)/i', $heading_title ) ) {
                        $chunk_type = 'pricing';
                    } elseif ( preg_match( '/(faq|question|help|how to|answer)/i', $heading_title ) ) {
                        $chunk_type = 'faq';
                    } elseif ( preg_match( '/(policy|refund|privacy|terms|return|guarantee)/i', $heading_title ) ) {
                        $chunk_type = 'policy';
                    }

                    $full_chunk = "{$heading_title}\n{$combined_text}";

                    // If section is very long (> 500 words), split by paragraph boundaries
                    if ( str_word_count( $full_chunk ) > 500 ) {
                        $sub_chunks = self::split_long_section( $heading_title, $combined_text, $permalink, $chunk_type );
                        $chunks = array_merge( $chunks, $sub_chunks );
                    } else {
                        $chunks[] = [
                            'section_title' => "{$post_title} > {$heading_title}",
                            'chunk_type'    => $chunk_type,
                            'clean_content' => $full_chunk,
                            'url'           => $permalink,
                            'token_count'   => str_word_count( $full_chunk ),
                        ];
                    }
                }
            }
        }

        return $chunks;
    }

    /**
     * Parse Elementor JSON metadata for widgets (Price tables, accordions, headings, text-editor)
     */
    private static function parse_elementor_data( $elementor_json, $post_title, $permalink ) {
        if ( is_string( $elementor_json ) ) {
            $data = json_decode( $elementor_json, true );
        } else {
            $data = $elementor_json;
        }

        if ( ! is_array( $data ) ) {
            return [];
        }

        $chunks = [];
        self::traverse_elementor_elements( $data, $post_title, $permalink, $chunks );
        return $chunks;
    }

    private static function traverse_elementor_elements( $elements, $post_title, $permalink, &$chunks ) {
        if ( ! is_array( $elements ) ) return;

        foreach ( $elements as $el ) {
            $el_type = $el['elType'] ?? '';
            $widget_type = $el['widgetType'] ?? '';
            $settings = $el['settings'] ?? [];

            if ( $el_type === 'widget' ) {
                // 1. Price Table Widget
                if ( strpos( $widget_type, 'price' ) !== false || ! empty( $settings['heading'] ) && ! empty( $settings['price'] ) ) {
                    $heading  = $settings['heading'] ?? ( $settings['title'] ?? 'Plan' );
                    $price    = ( $settings['currency_symbol'] ?? '$' ) . ( $settings['price'] ?? '' ) . ( ! empty( $settings['period'] ) ? '/' . $settings['period'] : '' );
                    $features = [];
                    if ( ! empty( $settings['features_list'] ) && is_array( $settings['features_list'] ) ) {
                        foreach ( $settings['features_list'] as $f ) {
                            if ( ! empty( $f['item_text'] ) ) {
                                $features[] = trim( wp_strip_all_tags( $f['item_text'] ) );
                            }
                        }
                    }
                    $chunk_text = "PRICING PLAN: {$heading}\nPRICE: {$price}";
                    if ( ! empty( $features ) ) {
                        $chunk_text .= "\nFEATURES:\n• " . implode( "\n• ", $features );
                    }
                    $chunks[] = [
                        'section_title' => "Pricing: {$heading} ({$post_title})",
                        'chunk_type'    => 'pricing',
                        'clean_content' => $chunk_text,
                        'url'           => $permalink,
                        'token_count'   => str_word_count( $chunk_text ),
                    ];
                }
                // 2. Accordion / Toggle Widget
                elseif ( strpos( $widget_type, 'accordion' ) !== false || strpos( $widget_type, 'toggle' ) !== false ) {
                    $tabs = $settings['tabs'] ?? [];
                    if ( is_array( $tabs ) ) {
                        foreach ( $tabs as $tab ) {
                            $q = trim( wp_strip_all_tags( $tab['tab_title'] ?? '' ) );
                            $a = trim( wp_strip_all_tags( $tab['tab_content'] ?? '' ) );
                            if ( ! empty( $q ) && ! empty( $a ) ) {
                                $faq_text = "QUESTION: {$q}\nANSWER: {$a}";
                                $chunks[] = [
                                    'section_title' => "FAQ: {$q}",
                                    'chunk_type'    => 'faq',
                                    'clean_content' => $faq_text,
                                    'url'           => $permalink,
                                    'token_count'   => str_word_count( $faq_text ),
                                ];
                            }
                        }
                    }
                }
                // 3. Text Editor / Heading Widget
                elseif ( in_array( $widget_type, [ 'text-editor', 'heading' ], true ) ) {
                    $raw = $settings['editor'] ?? ( $settings['title'] ?? '' );
                    $clean = trim( wp_strip_all_tags( $raw ) );
                    if ( strlen( $clean ) > 100 ) {
                        $chunks[] = [
                            'section_title' => "{$post_title} Section",
                            'chunk_type'    => 'heading',
                            'clean_content' => $clean,
                            'url'           => $permalink,
                            'token_count'   => str_word_count( $clean ),
                        ];
                    }
                }
            }

            // Recurse into nested elements (columns, sections, containers)
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                self::traverse_elementor_elements( $el['elements'], $post_title, $permalink, $chunks );
            }
        }
    }

    /**
     * Convert HTML Table Node into Row-Wise Semantic Sentences with Column Headers
     * Ensures every chunk row is completely self-contained and never loses header context.
     */
    private static function convert_table_to_text( $table_node ) {
        $xpath = new DOMXPath( $table_node->ownerDocument );
        $tr_list = $xpath->query( './/tr', $table_node );

        if ( ! $tr_list || $tr_list->length === 0 ) return '';

        $headers = [];
        $sentences = [];

        // 1. Extract Headers from first row or th elements
        $first_row = $tr_list->item( 0 );
        if ( $first_row ) {
            $header_cells = $xpath->query( './/th | .//td', $first_row );
            foreach ( $header_cells as $th ) {
                $headers[] = trim( preg_replace( '/\s+/', ' ', $th->textContent ) );
            }
        }

        // 2. Process data rows into descriptive sentences
        for ( $i = 1; $i < $tr_list->length; $i++ ) {
            $tr = $tr_list->item( $i );
            $cells = $xpath->query( './/th | .//td', $tr );
            $row_pairs = [];

            for ( $c = 0; $c < $cells->length; $c++ ) {
                $cell_val = trim( preg_replace( '/\s+/', ' ', $cells->item( $c )->textContent ) );
                if ( empty( $cell_val ) ) continue;

                $hdr = isset( $headers[ $c ] ) && ! empty( $headers[ $c ] ) ? $headers[ $c ] : "Column " . ( $c + 1 );
                $row_pairs[] = "{$hdr}: {$cell_val}";
            }

            if ( ! empty( $row_pairs ) ) {
                $sentences[] = "• " . implode( " | ", $row_pairs );
            }
        }

        // If no structured headers were detected, fallback to pipe markdown
        if ( empty( $sentences ) ) {
            foreach ( $tr_list as $tr ) {
                $cols = [];
                $cells = $xpath->query( './/th | .//td', $tr );
                foreach ( $cells as $cell ) {
                    $cols[] = trim( preg_replace( '/\s+/', ' ', $cell->textContent ) );
                }
                if ( ! empty( $cols ) ) {
                    $sentences[] = implode( ' | ', $cols );
                }
            }
        }

        return implode( "\n", $sentences );
    }

    /**
     * Split very long sections into 250-400 word chunks with overlap
     */
    private static function split_long_section( $title, $text, $permalink, $chunk_type ) {
        $paragraphs = explode( "\n", $text );
        $sub_chunks = [];
        $current = [];
        $current_words = 0;
        $part = 1;

        foreach ( $paragraphs as $p ) {
            $p = trim( $p );
            if ( empty( $p ) ) continue;

            $words = str_word_count( $p );
            if ( ( $current_words + $words ) > 350 && ! empty( $current ) ) {
                $chunk_body = implode( "\n", $current );
                $sub_chunks[] = [
                    'section_title' => "{$title} (Part {$part})",
                    'chunk_type'    => $chunk_type,
                    'clean_content' => "{$title}\n{$chunk_body}",
                    'url'           => $permalink,
                    'token_count'   => str_word_count( $chunk_body ),
                ];
                $part++;
                $current = [ $p ];
                $current_words = $words;
            } else {
                $current[] = $p;
                $current_words += $words;
            }
        }

        if ( ! empty( $current ) ) {
            $chunk_body = implode( "\n", $current );
            $sub_chunks[] = [
                'section_title' => $part > 1 ? "{$title} (Part {$part})" : $title,
                'chunk_type'    => $chunk_type,
                'clean_content' => "{$title}\n{$chunk_body}",
                'url'           => $permalink,
                'token_count'   => str_word_count( $chunk_body ),
            ];
        }

        return $sub_chunks;
    }

    /**
     * Clean raw HTML text
     */
    private static function clean_text( $text ) {
        $text = strip_shortcodes( $text );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text );
    }

    /**
     * De-duplicate chunks that have duplicate content
     */
    private static function deduplicate_chunks( $chunks ) {
        $unique = [];
        $seen   = [];

        foreach ( $chunks as $c ) {
            $hash = md5( strtolower( substr( $c['clean_content'], 0, 100 ) ) );
            if ( ! isset( $seen[ $hash ] ) ) {
                $seen[ $hash ] = true;
                $unique[] = $c;
            }
        }

        return $unique;
    }
}
