<?php
defined( 'ABSPATH' ) || exit;

class AAM_Site_Reader {

    public function get_site_content() {
        $include_all    = get_option( 'aam_include_all_pages', '1' );
        $selected_pages = (array) get_option( 'aam_selected_pages', [] );

        if ( $include_all === '1' ) {
            $pages = get_pages( [ 'post_status' => 'publish' ] );
        } else {
            $page_ids = array_filter( array_map( 'absint', $selected_pages ) );
            if ( empty( $page_ids ) ) {
                $pages = [];
            } else {
                $pages = get_pages( [
                    'post_status' => 'publish',
                    'include'     => $page_ids,
                ] );
            }
        }

        $content = "";
        if ( ! empty( $pages ) ) {
            foreach ( $pages as $page ) {
                $title = wp_strip_all_tags( $page->post_title );
                $raw_body = $page->post_content;

                // Extract Elementor metadata text if page built with Elementor
                $elementor_data = get_post_meta( $page->ID, '_elementor_data', true );
                $extracted_text = "";

                if ( ! empty( $elementor_data ) ) {
                    if ( is_string( $elementor_data ) ) {
                        // Extract all "text", "title", "editor", "content" strings from Elementor JSON
                        preg_match_all( '/"(?:editor|title|text|content|sub_title|heading_title)":"([^"]+)"/i', $elementor_data, $matches );
                        if ( ! empty( $matches[1] ) ) {
                            $extracted_text = implode( " ", array_map( 'stripcslashes', $matches[1] ) );
                        }
                    }
                }

                $combined = $raw_body . " " . $extracted_text;
                $clean_body = wp_strip_all_tags( strip_shortcodes( $combined ) );
                // Normalize spaces
                $clean_body = preg_replace( '/\s+/', ' ', $clean_body );

                if ( ! empty( trim( $clean_body ) ) ) {
                    $content .= "Page Title: " . $title . "\n";
                    $content .= "Content: " . trim( $clean_body ) . "\n\n";
                }
            }
        }

        return $content;
    }

    public function get_contact_info() {
        $info  = "STORE & CONTACT INFORMATION:\n";
        $info .= "- Site Name: " . get_bloginfo( 'name' ) . "\n";
        $info .= "- Site Tagline: " . get_bloginfo( 'description' ) . "\n";

        // Check for explicitly set support email option
        $contact_email = get_option( 'aam_support_email', '' );

        // Check WooCommerce store sender email if available
        if ( empty( $contact_email ) && class_exists( 'WooCommerce' ) ) {
            $wc_email = get_option( 'woocommerce_email_from_address', '' );
            if ( ! empty( $wc_email ) && is_email( $wc_email ) ) {
                $contact_email = $wc_email;
            }
        }

        // Scan contact pages for email address
        if ( empty( $contact_email ) ) {
            $pages = get_posts( [
                'post_type'   => 'page',
                'post_status' => 'publish',
                's'           => 'contact',
                'numberposts' => 3,
            ] );
            if ( ! empty( $pages ) && is_array( $pages ) ) {
                foreach ( $pages as $p ) {
                    if ( preg_match( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $p->post_content, $m ) ) {
                        $contact_email = $m[0];
                        break;
                    }
                }
            }
        }

        // Fallback to general admin email
        if ( empty( $contact_email ) ) {
            $contact_email = get_option( 'admin_email' );
        }

        $info .= "- Support / Contact Email: " . $contact_email . "\n";
        $info .= "- Site URL: " . site_url() . "\n";

        // WooCommerce Store Address if available
        if ( class_exists( 'WooCommerce' ) ) {
            $address_1 = get_option( 'woocommerce_store_address' );
            $address_2 = get_option( 'woocommerce_store_address_2' );
            $city      = get_option( 'woocommerce_store_city' );
            $postcode  = get_option( 'woocommerce_store_postcode' );
            $country   = get_option( 'woocommerce_default_country' );
            if ( ! empty( $address_1 ) ) {
                $full_addr = implode( ', ', array_filter( [ $address_1, $address_2, $city, $postcode, $country ] ) );
                $info .= "- Store Address: " . $full_addr . "\n";
            }
        }

        return $info;
    }

    public function get_woocommerce_catalog() {
        $wc_enable = get_option( 'aam_wc_enable', '1' );
        if ( $wc_enable !== '1' || ! class_exists( 'WooCommerce' ) ) {
            return "";
        }

        $max_products = (int) get_option( 'aam_wc_max_products', 30 );
        if ( $max_products < 1 ) {
            $max_products = 30;
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $max_products,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query( $args );
        if ( ! $query->have_posts() ) {
            return "";
        }

        $catalog = "STORE PRODUCTS CATALOG:\n";
        $raw_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
        $currency_symbol = html_entity_decode( $raw_symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        while ( $query->have_posts() ) {
            $query->the_post();
            $product = wc_get_product( get_the_ID() );
            if ( ! $product ) continue;

            $title       = wp_strip_all_tags( $product->get_name() );
            $price       = $product->get_price();
            $stock       = $product->is_in_stock() ? 'In Stock' : 'Out of Stock';
            $permalink   = get_permalink( $product->get_id() );
            $short_desc  = wp_strip_all_tags( $product->get_short_description() );
            if ( empty( trim( $short_desc ) ) ) {
                $short_desc = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 18 );
            }

            // Image URL
            $image_id  = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
            if ( empty( $image_url ) && function_exists( 'wc_placeholder_img_src' ) ) {
                $image_url = wc_placeholder_img_src();
            }

            // Categories
            $categories = wp_strip_all_tags( wc_get_product_category_list( $product->get_id(), ', ' ) );

            // Tags
            $tags = wp_strip_all_tags( wc_get_product_tag_list( $product->get_id(), ', ' ) );

            // Attributes
            $attributes_str = '';
            $attributes = $product->get_attributes();
            if ( ! empty( $attributes ) ) {
                $attr_pairs = [];
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
                $attributes_str = implode( '; ', $attr_pairs );
            }

            $catalog .= "- Product: {$title}\n";
            $catalog .= "  Price: {$currency_symbol}{$price}\n";
            $catalog .= "  Stock: {$stock}\n";
            if ( ! empty( $image_url ) )       $catalog .= "  Image URL: {$image_url}\n";
            $catalog .= "  Link: {$permalink}\n";
            if ( ! empty( $categories ) )      $catalog .= "  Categories: {$categories}\n";
            if ( ! empty( $tags ) )            $catalog .= "  Tags: {$tags}\n";
            if ( ! empty( $attributes_str ) )  $catalog .= "  Attributes: {$attributes_str}\n";
            if ( ! empty( $short_desc ) )       $catalog .= "  Summary: {$short_desc}\n";
            $catalog .= "\n";
        }
        wp_reset_postdata();

        return $catalog;
    }

    public function get_system_prompt( $user_message = '' ) {
        $site_name       = wp_strip_all_tags( get_bloginfo( 'name' ) );
        $custom_prompt   = get_option( 'aam_custom_system_instruction', '' );

        $prompt  = "You are a smart, friendly AI Assistant for '{$site_name}'.\n\n";
        $prompt .= "GENERAL RULES:\n";
        $prompt .= "- Answer queries clearly and accurately based on the provided store information and search results.\n";
        $prompt .= "- Keep answers polite, helpful, concise, and well structured.\n";
        $prompt .= "- Always use clean currency formatting (e.g. Rs. 3250 or $3250), never unescaped HTML entities.\n\n";

        $prompt .= "PRODUCT RECOMMENDATIONS & FORMATTING GUIDELINES:\n";
        $prompt .= "- IMPORTANT: For normal queries, greetings, general questions, store hours, policies, or contact details, respond in natural, friendly conversational language with Markdown formatting. Do NOT wrap general replies in JSON.\n";
        $prompt .= "- When a user specifically asks to find, recommend, list, or suggest products, provide a structured JSON product recommendation block (suggest 2-3 products max to keep response fast and concise):\n";
        $prompt .= "  ```json\n";
        $prompt .= "  {\n";
        $prompt .= "    \"type\": \"product_recommendation\",\n";
        $prompt .= "    \"reply\": \"Here are some top products recommended for you:\",\n";
        $prompt .= "    \"products\": [\n";
        $prompt .= "      {\n";
        $prompt .= "        \"title\": \"Product Name\",\n";
        $prompt .= "        \"price\": \"Rs. 3250\",\n";
        $prompt .= "        \"stock\": \"In Stock\",\n";
        $prompt .= "        \"image_url\": \"https://.../image.jpg\",\n";
        $prompt .= "        \"permalink\": \"https://.../product/item/\",\n";
        $prompt .= "        \"short_description\": \"Short 1-sentence summary (under 10 words)\",\n";
        $prompt .= "        \"attributes\": \"Color: Black\"\n";
        $prompt .= "      }\n";
        $prompt .= "    ]\n";
        $prompt .= "  }\n";
        $prompt .= "  ```\n";
        $prompt .= "  CRITICAL RULES FOR PRODUCT JSON:\n";
        $prompt .= "  1. Keep `short_description` under 10 words so you never exceed token limits.\n";
        $prompt .= "  2. Always close all quotes, brackets `]`, and braces `}` completely.\n";
        $prompt .= "  3. Ensure valid JSON without unescaped quotes inside string values.\n";
        $prompt .= "- For general text and links, always wrap links in Markdown `[Link Title](URL)` and images as `![Image Alt](URL)` so links are clickable.\n\n";

        if ( ! empty( trim( $custom_prompt ) ) ) {
            $prompt .= "CUSTOM OWNER INSTRUCTIONS:\n";
            $prompt .= trim( $custom_prompt ) . "\n\n";
        }

        $contact_info = $this->get_contact_info();
        if ( ! empty( trim( $contact_info ) ) ) {
            $prompt .= $contact_info . "\n\n";
        }

        // Dynamically fetch search context for user_message
        if ( ! empty( $user_message ) ) {
            $search_context = $this->get_search_context( $user_message );
            if ( ! empty( trim( $search_context ) ) ) {
                $prompt .= $search_context . "\n";
            }
        } else {
            // Fallback for initial load
            $stats = class_exists( 'AAM_Indexer' ) ? AAM_Indexer::get_index_stats() : [ 'total_indexed' => 0 ];
            if ( empty( $stats['total_indexed'] ) ) {
                $site_content = $this->get_site_content();
                $wc_catalog   = $this->get_woocommerce_catalog();
                if ( ! empty( trim( $site_content ) ) ) $prompt .= "WEBSITE PAGES DATA:\n" . $site_content . "\n";
                if ( ! empty( trim( $wc_catalog ) ) ) $prompt .= $wc_catalog . "\n";
            }
        }

        return $prompt;
    }

    /**
     * Get relevant search results formatted for prompt context
     */
    public function get_search_context( $user_message ) {
        if ( class_exists( 'AAM_Search' ) ) {
            $limit   = (int) get_option( 'aam_search_limit', 8 );
            $results = AAM_Search::find_relevant( $user_message, $limit );
            if ( ! empty( $results ) ) {
                $context = "RELEVANT STORE & WEBSITE SEARCH RESULTS FOR THIS QUERY:\n";
                foreach ( $results as $item ) {
                    if ( $item['content_type'] === 'product' ) {
                        $context .= "- PRODUCT: {$item['title']}\n";
                        if ( ! empty( $item['price'] ) ) $context .= "  Price: {$item['price']}\n";
                        if ( ! empty( $item['stock_status'] ) ) $context .= "  Stock: {$item['stock_status']}\n";
                        if ( ! empty( $item['image_url'] ) ) $context .= "  Image URL: {$item['image_url']}\n";
                        if ( ! empty( $item['permalink'] ) ) $context .= "  Link: {$item['permalink']}\n";
                        $context .= "  Details: " . wp_trim_words( $item['searchable_text'], 25 ) . "\n\n";
                    } else {
                        $context .= "- PAGE: {$item['title']}\n";
                        if ( ! empty( $item['permalink'] ) ) $context .= "  Link: {$item['permalink']}\n";
                        $context .= "  Details: " . wp_trim_words( $item['searchable_text'], 30 ) . "\n\n";
                    }
                }
                return $context;
            }
        }

        // Fallback if index table has 0 items indexed
        $stats = class_exists( 'AAM_Indexer' ) ? AAM_Indexer::get_index_stats() : [ 'total_indexed' => 0 ];
        if ( empty( $stats['total_indexed'] ) ) {
            return "WEBSITE PAGES DATA:\n" . $this->get_site_content() . "\n" . $this->get_woocommerce_catalog();
        }

        return "";
    }

}
