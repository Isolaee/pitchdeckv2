<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_OpenAI {

    /**
     * Generate voiceover scripts for all slides in a single API call.
     *
     * @param  array  $slides  Array of objects/arrays with slide_number, slide_text, extra_info.
     * @return array           Keyed by slide_number, value is the generated script string.
     * @throws RuntimeException On API error or unexpected response shape.
     */
    public static function generate_scripts( array $slides, string $language = 'Finnish' ): array {
        $api_key = Pitchdeck_Admin::get_openai_api_key();
        if ( empty( $api_key ) ) {
            throw new RuntimeException( 'OpenAI API key is not configured. Set it in Settings > Pitchdeck.' );
        }

        $prompt = self::build_prompt( $slides, $language );

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => 60,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode( [
                    'model'       => 'gpt-4o-mini',
                    'temperature' => 0.7,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => 'You are writing voiceover scripts for a business presentation. Write natural, spoken language. No markdown, no bullet points, no headers. Plain sentences only. Respond strictly in the JSON format requested.',
                        ],
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'OpenAI connection error: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            $msg = $body['error']['message'] ?? "OpenAI returned HTTP {$code}.";
            throw new RuntimeException( $msg );
        }

        $raw_text = $body['choices'][0]['message']['content'] ?? '';

        return self::parse_response( $raw_text, $slides );
    }

    /**
     * Build a single prompt that requests a JSON array with one script per slide.
     */
    private static function build_prompt( array $slides, string $language = 'Finnish' ): string {
        $lines = [];
        $lines[] = "Generate voiceover scripts for each slide listed below. Write every script in {$language}.";
        $lines[] = '';
        $lines[] = 'Requirements:';
        $lines[] = '- Each script must be 2-5 natural spoken sentences.';
        $lines[] = "- Language: {$language}. Do not use any other language.";
        $lines[] = '- Plain text only. No markdown, no formatting.';
        $lines[] = '- Suitable for text-to-speech.';
        $lines[] = '- Incorporate the extra instructions when provided.';
        $lines[] = '';
        $lines[] = 'Respond with ONLY a valid JSON array, no extra text. Format:';
        $lines[] = '[{"slide_number": 1, "script": "..."}, ...]';
        $lines[] = '';
        $lines[] = '--- SLIDES ---';

        foreach ( $slides as $slide ) {
            $num        = (int) self::slide_field( $slide, 'slide_number', 0 );
            $text       = trim( self::slide_field( $slide, 'slide_text', '' ) );
            $extra      = trim( self::slide_field( $slide, 'extra_info', '' ) );

            $lines[] = '';
            $lines[] = "Slide {$num}:";
            $lines[] = "Text: {$text}";
            if ( $extra ) {
                $lines[] = "Extra instructions: {$extra}";
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Parse the JSON array response from OpenAI into a slide_number => script map.
     * Falls back gracefully if JSON is wrapped in a code fence.
     */
    private static function parse_response( string $raw, array $slides ): array {
        // Strip markdown code fences if the model added them.
        $cleaned = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
        $cleaned = preg_replace( '/\s*```$/', '', $cleaned );

        $decoded = json_decode( $cleaned, true );

        if ( ! is_array( $decoded ) ) {
            throw new RuntimeException( 'OpenAI returned an unexpected response format. Raw: ' . substr( $raw, 0, 300 ) );
        }

        $result = [];
        foreach ( $decoded as $item ) {
            $num    = (int) ( $item['slide_number'] ?? 0 );
            $script = trim( $item['script'] ?? '' );
            if ( $num > 0 && $script ) {
                $result[ $num ] = $script;
            }
        }

        // Fallback: if a slide is missing from the response, insert a placeholder.
        foreach ( $slides as $slide ) {
            $num = (int) self::slide_field( $slide, 'slide_number', 0 );
            if ( $num > 0 && ! isset( $result[ $num ] ) ) {
                $result[ $num ] = '';
            }
        }

        return $result;
    }

    /**
     * Generate TTS audio for a single script string using OpenAI /v1/audio/speech.
     *
     * @param  string $script  The text to convert to speech.
     * @param  string $voice   One of: alloy, echo, fable, onyx, nova, shimmer.
     * @return string          Raw MP3 binary data.
     * @throws RuntimeException On API error.
     */
    public static function generate_audio( string $script, string $voice = 'alloy' ): string {
        $api_key = Pitchdeck_Admin::get_openai_api_key();
        if ( empty( $api_key ) ) {
            throw new RuntimeException( 'OpenAI API key is not configured. Set it in Settings > Pitchdeck.' );
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/audio/speech',
            [
                'timeout' => 60,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode( [
                    'model' => 'tts-1',
                    'input' => $script,
                    'voice' => $voice,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'OpenAI TTS connection error: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['error']['message'] ?? "OpenAI TTS returned HTTP {$code}.";
            throw new RuntimeException( $msg );
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Read a field from a slide that may be a stdClass object (from DB) or an array.
     *
     * @param object|array $slide
     * @param string       $field
     * @param mixed        $default
     * @return mixed
     */
    private static function slide_field( $slide, string $field, $default ) {
        if ( is_object( $slide ) ) {
            return $slide->$field ?? $default;
        }
        return $slide[ $field ] ?? $default;
    }
}
