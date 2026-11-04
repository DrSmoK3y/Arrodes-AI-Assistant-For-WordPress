<?php
defined( 'ABSPATH' ) || exit;

class AAM_AI {

    private $provider;
    private $api_key;
    private $model_name;
    private $custom_endpoint;

    public function __construct() {
        $this->provider        = get_option( 'aam_provider', 'openrouter' );
        $this->api_key         = get_option( 'aam_api_key', '' );
        $this->model_name      = get_option( 'aam_model_name', '' );
        $this->custom_endpoint = get_option( 'aam_custom_endpoint', '' );
    }

    public function send_message( $user_message, $history = [] ) {
        if ( empty( $this->api_key ) ) {
            return __( 'API key is missing. Please configure your API key in Arrodes AI Manager settings.', 'arrodes-ai-manager' );
        }

        $site_reader   = new AAM_Site_Reader();
        $system_prompt = $site_reader->get_system_prompt( $user_message );

        switch ( $this->provider ) {
            case 'gemini':
                return $this->call_gemini( $system_prompt, $user_message, $history );
            case 'deepseek':
                return $this->call_openai_compatible( 'https://api.deepseek.com/chat/completions', $this->model_name ?: 'deepseek-chat', $system_prompt, $user_message, $history );
            case 'kimi':
                return $this->call_openai_compatible( 'https://api.moonshot.cn/v1/chat/completions', $this->model_name ?: 'moonshot-v1-8k', $system_prompt, $user_message, $history );
            case 'custom':
                $endpoint = rtrim( trim( (string) $this->custom_endpoint ), '/' );
                if ( empty( $endpoint ) ) {
                    return __( 'Custom AI endpoint URL is empty. Please enter your endpoint URL in Arrodes AI Manager settings under AI Models & API.', 'arrodes-ai-manager' );
                }
                if ( ! $this->validate_custom_endpoint( $endpoint ) ) {
                    return __( 'Invalid or restricted custom AI endpoint URL. Please ensure your endpoint uses https:// and a valid hostname.', 'arrodes-ai-manager' );
                }
                if ( substr( $endpoint, -17 ) !== '/chat/completions' ) {
                    $endpoint .= '/chat/completions';
                }
                return $this->call_openai_compatible( $endpoint, $this->model_name ?: 'gpt-3.5-turbo', $system_prompt, $user_message, $history );
            case 'openrouter':
            default:
                return $this->call_openai_compatible( 'https://openrouter.ai/api/v1/chat/completions', $this->model_name ?: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning-20260428:free', $system_prompt, $user_message, $history );
        }
    }

    private function validate_custom_endpoint( $url ) {
        $parsed = wp_parse_url( $url );
        // Require HTTPS only for custom endpoints to prevent cleartext credential leakage and SSRF over plain HTTP
        if ( ! $parsed || ( $parsed['scheme'] ?? '' ) !== 'https' ) {
            return false;
        }
        $host = strtolower( trim( $parsed['host'] ?? '' ) );
        if ( empty( $host ) ) {
            return false;
        }

        // Block obvious loopback, localhost, and cloud metadata hostnames
        if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1', 'metadata.google.internal', 'instance-data' ], true ) || strpos( $host, '169.254.' ) === 0 ) {
            return false;
        }

        $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? [ $host ] : @gethostbynamel( $host );
        if ( empty( $ips ) || ! is_array( $ips ) ) {
            return false;
        }

        foreach ( $ips as $ip ) {
            // Block private, reserved, and loopback IP ranges
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return false;
            }
            if ( strpos( $ip, '127.' ) === 0 || strpos( $ip, '169.254.' ) === 0 || $ip === '0.0.0.0' ) {
                return false;
            }
        }

        return true;
    }

    private function call_openai_compatible( $endpoint, $default_model, $system_prompt, $user_message, $history = [] ) {
        $model = ! empty( $this->model_name ) ? sanitize_text_field( $this->model_name ) : $default_model;

        $messages = [
            [ 'role' => 'system', 'content' => $system_prompt ]
        ];

        // Append past history if provided (last 4 messages for context)
        if ( ! empty( $history ) && is_array( $history ) ) {
            $recent_history = array_slice( $history, -4 );
            foreach ( $recent_history as $msg ) {
                if ( isset( $msg['role'], $msg['content'] ) ) {
                    $messages[] = [
                        'role'    => ( $msg['role'] === 'user' ) ? 'user' : 'assistant',
                        'content' => sanitize_text_field( $msg['content'] )
                    ];
                }
            }
        }

        $messages[] = [ 'role' => 'user', 'content' => $user_message ];

        $max_tokens = max( 1500, (int) get_option( 'aam_max_tokens', 2000 ) );
        $payload    = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.2,
        ];
        if ( $max_tokens > 0 ) {
            $payload['max_tokens'] = $max_tokens;
        }

        $body = wp_json_encode( $payload );

        // wp_safe_remote_post enforces WordPress internal IP and DNS rebinding protections
        $auth_token = trim( (string) $this->api_key );
        $auth_header = ( strpos( $auth_token, 'Bearer ' ) === 0 ) ? $auth_token : 'Bearer ' . $auth_token;

        $response = wp_safe_remote_post( $endpoint, [
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => $auth_header,
                'HTTP-Referer'  => esc_url_raw( get_site_url() ),
            ],
            'body'        => $body,
            'timeout'     => 30,
            'redirection' => 0,
            'sslverify'   => true,
        ]);

        if ( is_wp_error( $response ) ) {
            return __( 'Connection error. Please try again later.', 'arrodes-ai-manager' );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            return $this->clean_ai_response( $data['choices'][0]['message']['content'] );
        }

        if ( isset( $data['error']['message'] ) ) {
            $err_msg = $data['error']['message'];
            if ( stripos( $err_msg, 'authentication' ) !== false || stripos( $err_msg, 'auth' ) !== false || stripos( $err_msg, 'api key' ) !== false ) {
                return '⚠️ Authentication Error: ' . esc_html( $err_msg ) . '. Please verify your API Key and Endpoint in Settings > AI Models & API.';
            }
            return 'API Error: ' . esc_html( $err_msg );
        }

        return __( 'No response received from AI service.', 'arrodes-ai-manager' );
    }

    private function call_gemini( $system_prompt, $user_message, $history = [] ) {
        $raw_model = ! empty( $this->model_name ) ? trim( $this->model_name ) : 'gemini-3.6-flash';
        $model     = preg_replace( '#^models/#', '', $raw_model );

        // Automatically upgrade deprecated models or empty fallback
        if ( empty( $model ) || $model === 'gemini-2.5-flash' || $model === 'gemini-1.5-flash' ) {
            $model = 'gemini-3.6-flash';
        }

        // Strict whitelist character sanitization to prevent URL manipulation / path traversal
        $clean_model = preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $model );
        if ( empty( $clean_model ) ) {
            $clean_model = 'gemini-3.6-flash';
        }

        // Pass API Key in header ('x-goog-api-key') instead of query param to prevent URL logging leaks
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$clean_model}:generateContent";

        $contents = [];

        // Append past history if provided
        if ( ! empty( $history ) && is_array( $history ) ) {
            $recent_history = array_slice( $history, -4 );
            foreach ( $recent_history as $msg ) {
                if ( isset( $msg['role'], $msg['content'] ) ) {
                    $contents[] = [
                        'role'  => ( $msg['role'] === 'user' ) ? 'user' : 'model',
                        'parts' => [ [ 'text' => sanitize_text_field( $msg['content'] ) ] ]
                    ];
                }
            }
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [ [ 'text' => $user_message ] ]
        ];

        $max_tokens = max( 1500, (int) get_option( 'aam_max_tokens', 2000 ) );
        $payload    = [
            'system_instruction' => [
                'parts' => [ [ 'text' => $system_prompt ] ]
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.2,
            ]
        ];
        if ( $max_tokens > 0 ) {
            $payload['generationConfig']['maxOutputTokens'] = $max_tokens;
        }

        $body = wp_json_encode( $payload );

        $response = wp_safe_remote_post( $url, [
            'headers'     => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->api_key,
            ],
            'body'        => $body,
            'timeout'     => 30,
            'redirection' => 0,
            'sslverify'   => true,
        ]);

        if ( is_wp_error( $response ) ) {
            return __( 'Connection error. Please try again later.', 'arrodes-ai-manager' );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return $this->clean_ai_response( $data['candidates'][0]['content']['parts'][0]['text'] );
        }

        if ( isset( $data['error']['message'] ) ) {
            return 'Gemini API Error: ' . $data['error']['message'];
        }

        return __( 'No response received from Gemini API.', 'arrodes-ai-manager' );
    }

    /**
     * Clean and strip internal AI reasoning/think blocks and guarantee a valid response.
     */
    private function clean_ai_response( $raw_text ) {
        if ( empty( $raw_text ) ) {
            return __( 'Hello! Welcome to our store. How can I help you today?', 'arrodes-ai-manager' );
        }

        // Remove <think>...</think> and <reasoning>...</reasoning> blocks
        $cleaned = preg_replace( '/<(think|reasoning)>[\s\S]*?<\/\1>/i', '', $raw_text );
        $cleaned = trim( $cleaned );

        // If the AI accidentally returned a pure JSON object wrapping a simple conversational reply like {"reply": "..."} without products
        $trimmed = $cleaned;
        if ( preg_match( '/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $trimmed, $m ) ) {
            $trimmed = trim( $m[1] );
        }

        if ( substr( $trimmed, 0, 1 ) === '{' && substr( $trimmed, -1 ) === '}' ) {
            $test_decoded = json_decode( $trimmed, true );
            if ( is_array( $test_decoded ) ) {
                // If it doesn't contain product recommendations, extract the message text directly
                if ( empty( $test_decoded['products'] ) && empty( $test_decoded['items'] ) ) {
                    foreach ( [ 'reply', 'message', 'text', 'answer', 'content', 'response' ] as $k ) {
                        if ( ! empty( $test_decoded[ $k ] ) && is_string( $test_decoded[ $k ] ) ) {
                            return trim( $test_decoded[ $k ] );
                        }
                    }
                }
            }
        }

        if ( empty( $cleaned ) ) {
            return __( 'Hello! Welcome to our store. How can I help you today?', 'arrodes-ai-manager' );
        }

        // Convert any markdown or ASCII comparison tables into clean mobile bullet cards
        $cleaned = $this->convert_markdown_tables_to_bullets( $cleaned );

        // Filter out and sanitize any hallucinated third-party domains (e.g. azmidigisol.com)
        $cleaned = $this->sanitize_external_domains( $cleaned );

        // If product JSON was truncated by token limit, auto-repair and close it
        $repaired = $this->repair_truncated_json( $cleaned );

        return $repaired;
    }

    /**
     * Strips or replaces hallucinated third-party domains (e.g. azmidigisol.com) with the real site URL.
     */
    private function sanitize_external_domains( $text ) {
        if ( empty( $text ) || ! is_string( $text ) ) {
            return $text;
        }

        $home_url   = home_url();
        $site_host  = wp_parse_url( $home_url, PHP_URL_HOST );
        $site_title = wp_strip_all_tags( get_bloginfo( 'name' ) );

        // 1. Direct purge of azmidigisol.com or azmidigisol mentions
        $text = preg_replace( '/https?:\/\/(?:www\.)?azmidigisol\.com[^\s<>"\'\)]*/i', esc_url( home_url( '/contact/' ) ), $text );
        $text = preg_replace( '/\bazmidigisol(?:\.com)?\b/i', $site_title, $text );

        // 2. Scan markdown links: [Title](URL)
        if ( ! empty( $site_host ) ) {
            $text = preg_replace_callback( '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/i', function( $matches ) use ( $site_host, $home_url ) {
                $link_title = $matches[1];
                $link_url   = $matches[2];
                $link_host  = wp_parse_url( $link_url, PHP_URL_HOST );

                if ( empty( $link_host ) || strcasecmp( $link_host, $site_host ) === 0 || strpos( $link_host, 'googleapis.com' ) !== false || strpos( $link_host, 'unsplash.com' ) !== false || strpos( $link_host, 'gravatar.com' ) !== false ) {
                    return $matches[0];
                }

                // If a foreign agency URL was hallucinated, map it directly to this site's contact page
                return "[{$link_title}](" . esc_url( home_url( '/contact/' ) ) . ")";
            }, $text );
        }

        return $text;
    }

    /**
     * Converts markdown comparison tables into clean structured mobile-friendly bullet points.
     */
    private function convert_markdown_tables_to_bullets( $text ) {
        if ( strpos( $text, '|' ) === false ) {
            return $text;
        }

        $lines      = explode( "\n", $text );
        $new_lines  = [];
        $in_table   = false;
        $headers    = [];
        $rows       = [];
        $line_count = count( $lines );

        for ( $idx = 0; $idx < $line_count; $idx++ ) {
            $line        = trim( $lines[ $idx ] );
            $is_pipe     = ( strpos( $line, '|' ) !== false && substr_count( $line, '|' ) >= 2 );
            $is_sep_line = preg_match( '/^\|?(\s*:?-{2,}:?\s*\|)+\s*:?-{2,}:?\s*\|?$/', $line );

            if ( $is_pipe ) {
                $cells = array_map( 'trim', explode( '|', $line ) );
                if ( count( $cells ) > 0 && $cells[0] === '' ) array_shift( $cells );
                if ( count( $cells ) > 0 && end( $cells ) === '' ) array_pop( $cells );

                if ( ! $in_table ) {
                    $next_line = ( $idx + 1 < $line_count ) ? trim( $lines[ $idx + 1 ] ) : '';
                    $next_is_sep = preg_match( '/^\|?(\s*:?-{2,}:?\s*\|)+\s*:?-{2,}:?\s*\|?$/', $next_line );

                    if ( $next_is_sep ) {
                        $in_table = true;
                        $headers  = $cells;
                        $rows     = [];
                        $idx++; // skip separator line
                        continue;
                    } else {
                        $new_lines[] = $lines[ $idx ];
                    }
                } else {
                    if ( $is_sep_line ) continue;
                    $rows[] = $cells;
                }
            } else {
                if ( $in_table ) {
                    if ( ! empty( $rows ) ) {
                        foreach ( $rows as $row ) {
                            $first_cell = isset( $row[0] ) ? trim( $row[0] ) : '';
                            if ( empty( $first_cell ) ) continue;

                            $parts = [];
                            for ( $i = 1; $i < count( $row ); $i++ ) {
                                $hdr = isset( $headers[ $i ] ) ? trim( $headers[ $i ] ) : '';
                                $val = trim( $row[ $i ] );
                                if ( ! empty( $val ) ) {
                                    $parts[] = ( ! empty( $hdr ) ? "{$hdr}: " : "" ) . $val;
                                }
                            }

                            $bullet_line = "• **{$first_cell}**";
                            if ( ! empty( $parts ) ) {
                                $bullet_line .= " (" . implode( " • ", $parts ) . ")";
                            }
                            $new_lines[] = $bullet_line;
                        }
                    }
                    $headers  = [];
                    $rows     = [];
                    $in_table = false;
                }
                $new_lines[] = $lines[ $idx ];
            }
        }

        if ( $in_table && ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $first_cell = isset( $row[0] ) ? trim( $row[0] ) : '';
                if ( empty( $first_cell ) ) continue;

                $parts = [];
                for ( $i = 1; $i < count( $row ); $i++ ) {
                    $hdr = isset( $headers[ $i ] ) ? trim( $headers[ $i ] ) : '';
                    $val = trim( $row[ $i ] );
                    if ( ! empty( $val ) ) {
                        $parts[] = ( ! empty( $hdr ) ? "{$hdr}: " : "" ) . $val;
                    }
                }

                $bullet_line = "• **{$first_cell}**";
                if ( ! empty( $parts ) ) {
                    $bullet_line .= " (" . implode( " • ", $parts ) . ")";
                }
                $new_lines[] = $bullet_line;
            }
        }

        return trim( implode( "\n", $new_lines ) );
    }

    /**
     * Auto-repairs truncated or cut-off JSON so frontend never crashes or outputs broken strings.
     */
    private function repair_truncated_json( $text ) {
        if ( strpos( $text, '"products"' ) === false && strpos( $text, 'product_recommendation' ) === false ) {
            return $text;
        }

        $json_candidate = $text;
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)(?:```)?$/i', $text, $m ) ) {
            $json_candidate = trim( $m[1] );
        }

        if ( @json_decode( $json_candidate, true ) !== null ) {
            return $text;
        }

        // Attempt 1: Trim incomplete key/value back to the last complete item closing brace `}`
        $last_brace = strrpos( $json_candidate, '}' );
        if ( $last_brace !== false ) {
            $sub = substr( $json_candidate, 0, $last_brace + 1 );
            $attempt = $sub . "\n  ]\n}";
            if ( @json_decode( $attempt, true ) !== null ) {
                return "```json\n" . $attempt . "\n```";
            }
        }

        // Attempt 2: Close open quote and complete brackets
        $candidate = rtrim( $json_candidate );
        $quotes = substr_count( $candidate, '"' ) - substr_count( $candidate, '\"' );
        if ( $quotes % 2 !== 0 ) {
            $candidate .= '"';
        }
        $candidate .= "\n    }\n  ]\n}";
        if ( @json_decode( $candidate, true ) !== null ) {
            return "```json\n" . $candidate . "\n```";
        }

        return $text;
    }

}
