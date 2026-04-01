<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_ElevenLabs {

    /**
     * Known pre-made ElevenLabs voices: voice_id => [display_name, description].
     */
    const VOICES = [
        '21m00Tcm4TlvDq8ikWAM' => [ 'Rachel',    'Neutraali nainen' ],
        'pNInz6obpgDQGcFmaJgB' => [ 'Adam',      'Syvä mies' ],
        'onwK4e9ZLuTAKqWW03F9' => [ 'Daniel',    'Brittiläinen mies' ],
        'XB0fDUnXU5powFXDhCwa' => [ 'Charlotte', 'Pehmeä nainen' ],
        'TX3LPaxmHKxFdv7VOQHJ' => [ 'Liam',      'Selkeä mies' ],
        'pFZP5JQG7iQjIQuC4Bku' => [ 'Lily',      'Brittiläinen nainen' ],
    ];

    /**
     * Generate TTS audio using the ElevenLabs API.
     *
     * @param  string $script    Text to synthesise.
     * @param  string $voice_id  ElevenLabs voice ID.
     * @return string            Raw MP3 binary data.
     * @throws RuntimeException  On API error.
     */
    public static function generate_audio( string $script, string $voice_id ): string {
        $api_key = Pitchdeck_Admin::get_elevenlabs_api_key();
        if ( empty( $api_key ) ) {
            throw new RuntimeException( 'ElevenLabs API key is not configured. Set it in Settings > Pitchdeck.' );
        }

        $response = wp_remote_post(
            'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $voice_id ),
            [
                'timeout' => 60,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'xi-api-key'   => $api_key,
                ],
                'body' => wp_json_encode( [
                    'text'           => $script,
                    'model_id'       => 'eleven_multilingual_v2',
                    'voice_settings' => [
                        'stability'        => 0.5,
                        'similarity_boost' => 0.75,
                    ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'ElevenLabs connection error: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['detail']['message'] ?? ( is_string( $body['detail'] ?? null ) ? $body['detail'] : null ) ?? "ElevenLabs TTS returned HTTP {$code}.";
            throw new RuntimeException( $msg );
        }

        return wp_remote_retrieve_body( $response );
    }
}
