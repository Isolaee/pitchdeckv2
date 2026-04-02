<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_ElevenLabs {

    /**
     * ElevenLabs voices: voice_id => [display_name, description, stability, speed, use_speaker_boost].
     */
    const VOICES = [
        'fC33e0BIKA7wWK2MeARj' => [ 'Miika',  'Diplomaattinen ja vakaa',    0.39, 0.87, true  ],
        'ULbs8g3EYdQWA5MDrrx1' => [ 'Akseli', 'Rauhallinen ja selkeä',      0.50, 1.00, true  ],
        'Gp43kq9FsSlavD7esRtx' => [ 'Vaino',  'Rauhallinen ja sielullinen', 0.39, 1.03, true  ],
        'RiWFFlzYFZuu4lPMig3i' => [ 'Soili',  'Neutraali ja kevyt',         0.77, 0.95, false ],
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
                        'stability'         => self::VOICES[ $voice_id ][2] ?? 0.5,
                        'similarity_boost'  => 0.75,
                        'speed'             => self::VOICES[ $voice_id ][3] ?? 1.0,
                        'use_speaker_boost' => self::VOICES[ $voice_id ][4] ?? true,
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
