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
                $endpoint = rtrim( $this->custom_endpoint, '/' );
                if ( ! str_ends_with( $endpoint, '/chat/completions' ) ) {
                    $endpoint .= '/chat/completions';
                }
                return $this->call_openai_compatible( $endpoint, $this->model_name ?: 'gpt-3.5-turbo', $system_prompt, $user_message, $history );
            case 'openrouter':
            default:
                return $this->call_openai_compatible( 'https://openrouter.ai/api/v1/chat/completions', $this->model_name ?: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning-20260428:free', $system_prompt, $user_message, $history );
        }
    }

    private function call_openai_compatible( $endpoint, $default_model, $system_prompt, $user_message, $history = [] ) {
        $model = ! empty( $this->model_name ) ? $this->model_name : $default_model;

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

        $max_tokens = (int) get_option( 'aam_max_tokens', 500 );
        $payload    = [
            'model'    => $model,
            'messages' => $messages,
        ];
        if ( $max_tokens > 0 ) {
            $payload['max_tokens'] = $max_tokens;
        }

        $body = wp_json_encode( $payload );

        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
                'HTTP-Referer'  => esc_url_raw( get_site_url() ),
            ],
            'body'    => $body,
            'timeout' => 30,
        ]);

        if ( is_wp_error( $response ) ) {
            return __( 'Connection error. Please try again later.', 'arrodes-ai-manager' );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            return $this->clean_ai_response( $data['choices'][0]['message']['content'] );
        }

        if ( isset( $data['error']['message'] ) ) {
            return 'API Error: ' . $data['error']['message'];
        }

        return __( 'No response received from AI service.', 'arrodes-ai-manager' );
    }

    private function call_gemini( $system_prompt, $user_message, $history = [] ) {
        $model = ! empty( $this->model_name ) ? $this->model_name : 'gemini-2.5-flash';
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode( $this->api_key );

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

        $max_tokens = (int) get_option( 'aam_max_tokens', 500 );
        $payload    = [
            'system_instruction' => [
                'parts' => [ [ 'text' => $system_prompt ] ]
            ],
            'contents' => $contents
        ];
        if ( $max_tokens > 0 ) {
            $payload['generationConfig'] = [ 'maxOutputTokens' => $max_tokens ];
        }

        $body = wp_json_encode( $payload );

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $body,
            'timeout' => 30,
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

        if ( empty( $cleaned ) ) {
            return __( 'Hello! Welcome to our store. How can I help you today?', 'arrodes-ai-manager' );
        }

        return $cleaned;
    }

}
