<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_REST_API {

    const NAMESPACE = 'pitchdeck/v1';

    /**
     * Register REST routes. Called on 'rest_api_init'.
     */
    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/upload', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'handle_upload' ],
            'permission_callback' => [ __CLASS__, 'check_permissions' ],
        ] );

        register_rest_route( self::NAMESPACE, '/generate-script', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'handle_generate_script' ],
            'permission_callback' => [ __CLASS__, 'check_permissions' ],
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'language' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'Finnish',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/generate-video', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'handle_generate_video' ],
            'permission_callback' => [ __CLASS__, 'check_permissions' ],
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/generate-audio', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'handle_generate_audio' ],
            'permission_callback' => [ __CLASS__, 'check_permissions' ],
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'slide_number' => [
                    'required' => false,
                    'type'     => 'integer',
                ],
                'scripts' => [
                    'required' => false,
                    'type'     => 'array',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/save-slides', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'handle_save_slides' ],
            'permission_callback' => [ __CLASS__, 'check_permissions' ],
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'slides' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ] );
    }

    /**
     * Permission check. WordPress verifies the X-WP-Nonce header automatically.
     * Returning true allows any request with a valid nonce.
     * TODO: restrict to logged-in users in production.
     */
    public static function check_permissions(): bool {
        return true;
    }

    /**
     * POST /wp-json/pitchdeck/v1/upload
     *
     * Accepts: multipart/form-data with field 'pptx_file'
     * Returns: { job_id: string, slides: SlideStruct[] }
     */
    public static function handle_upload( WP_REST_Request $request ) {
        $files = $request->get_file_params();

        if ( empty( $files['pptx_file'] ) || UPLOAD_ERR_OK !== $files['pptx_file']['error'] ) {
            return new WP_Error( 'no_file', 'No valid PPTX file was uploaded.', [ 'status' => 400 ] );
        }

        $file = $files['pptx_file'];

        // Validate extension.
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'pptx', 'pdf' ], true ) ) {
            return new WP_Error( 'invalid_file_type', 'Only .pptx or .pdf files are accepted.', [ 'status' => 415 ] );
        }

        // Move to WP uploads/pitchdeck/.
        $upload_dir = wp_upload_dir();
        $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'pitchdeck/';
        wp_mkdir_p( $dest_dir );

        $job_id = self::generate_uuid_v4();
        $dest   = $dest_dir . $job_id . '.' . $ext;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'upload_failed', 'Could not save the uploaded file.', [ 'status' => 500 ] );
        }

        // Give the parser more room for large files.
        set_time_limit( 120 );
        wp_raise_memory_limit( 'image' );

        // Parse the file.
        try {
            if ( 'pdf' === $ext ) {
                $slides = Pitchdeck_PDF_Parser::parse( $dest );
            } else {
                $slides = Pitchdeck_PPTX_Parser::parse( $dest );
            }
        } catch ( RuntimeException $e ) {
            @unlink( $dest );
            return new WP_Error( 'parse_failed', $e->getMessage(), [ 'status' => 422 ] );
        }

        return rest_ensure_response( [
            'job_id' => $job_id,
            'slides' => $slides,
        ] );
    }

    /**
     * POST /wp-json/pitchdeck/v1/save-slides
     *
     * Accepts: application/json { job_id: string, slides: SlideStruct[] }
     * Returns: { success: bool, saved_count: int }
     */
    public static function handle_save_slides( WP_REST_Request $request ) {
        $job_id = $request->get_param( 'job_id' );
        $slides = $request->get_param( 'slides' );

        if ( empty( $job_id ) || ! is_array( $slides ) || empty( $slides ) ) {
            return new WP_Error( 'invalid_data', 'job_id and a non-empty slides array are required.', [ 'status' => 400 ] );
        }

        foreach ( $slides as $i => $slide ) {
            if ( ! isset( $slide['slide_number'], $slide['slide_text'] ) ) {
                return new WP_Error(
                    'invalid_slide',
                    "Slide at index {$i} is missing slide_number or slide_text.",
                    [ 'status' => 400 ]
                );
            }
        }

        $success = Pitchdeck_DB::save_slides( $job_id, $slides );

        if ( ! $success ) {
            return new WP_Error( 'db_error', 'One or more slides could not be saved.', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'success'     => true,
            'saved_count' => count( $slides ),
        ] );
    }

    /**
     * POST /wp-json/pitchdeck/v1/generate-script
     *
     * Accepts: application/json { job_id: string }
     * Returns: { success: bool, scripts: [{slide_number, script_text}, ...] }
     */
    public static function handle_generate_script( WP_REST_Request $request ) {
        $job_id   = $request->get_param( 'job_id' );
        $language = $request->get_param( 'language' ) ?: 'Finnish';

        $slides = Pitchdeck_DB::get_slides_by_job( $job_id );

        if ( empty( $slides ) ) {
            return new WP_Error( 'no_slides', 'No slides found for this job. Save slides first.', [ 'status' => 404 ] );
        }

        try {
            set_time_limit( 120 );
            $scripts = Pitchdeck_OpenAI::generate_scripts( $slides, $language );
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'openai_error', $e->getMessage(), [ 'status' => 502 ] );
        }

        // Format for the frontend: array of {slide_number, script_text}.
        $output = [];
        foreach ( $scripts as $slide_number => $script_text ) {
            $output[] = [
                'slide_number' => $slide_number,
                'script_text'  => $script_text,
            ];
        }
        usort( $output, fn( $a, $b ) => $a['slide_number'] <=> $b['slide_number'] );

        return rest_ensure_response( [
            'success' => true,
            'scripts' => $output,
        ] );
    }

    /**
     * POST /wp-json/pitchdeck/v1/generate-video
     *
     * Accepts: application/json { job_id: string }
     * Returns: { success: bool, video_url: string }
     *
     * Pipeline:
     *   1. Render each slide as a PNG (LibreOffice + pdftoppm).
     *   2. Combine each PNG with its MP3 into a short clip (ffmpeg).
     *   3. Concatenate all clips into one output.mp4 (ffmpeg concat).
     *
     * Server requirements: libreoffice, pdftoppm, ffmpeg.
     */
    public static function handle_generate_video( WP_REST_Request $request ) {
        $job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
        $slides = Pitchdeck_DB::get_slides_by_job( $job_id );

        if ( empty( $slides ) ) {
            return new WP_Error( 'no_slides', 'No slides found for this job.', [ 'status' => 404 ] );
        }

        $upload_dir  = wp_upload_dir();
        $base_dir    = trailingslashit( $upload_dir['basedir'] ) . 'pitchdeck/';
        $base_url    = trailingslashit( $upload_dir['baseurl'] ) . 'pitchdeck/';
        $job_dir     = $base_dir . $job_id . '/';

        // Locate the original source file (.pptx or .pdf).
        $source_file = null;
        $source_ext  = null;
        foreach ( [ 'pptx', 'pdf' ] as $try_ext ) {
            $try = $base_dir . $job_id . '.' . $try_ext;
            if ( file_exists( $try ) ) {
                $source_file = $try;
                $source_ext  = $try_ext;
                break;
            }
        }

        if ( ! $source_file ) {
            return new WP_Error( 'no_source', 'Original presentation file not found on disk.', [ 'status' => 404 ] );
        }

        // Confirm ffmpeg is available before doing any heavy work.
        exec( 'which ffmpeg 2>/dev/null', $ffmpeg_check, $ffmpeg_code );
        if ( 0 !== $ffmpeg_code ) {
            return new WP_Error(
                'missing_ffmpeg',
                'ffmpeg is not installed or not on the system PATH.',
                [ 'status' => 500 ]
            );
        }

        set_time_limit( 600 );
        wp_mkdir_p( $job_dir );

        // --- Step 1: render slide images ---
        try {
            $images = Pitchdeck_Slide_Renderer::render( $source_file, $job_dir, $source_ext );
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'render_error', $e->getMessage(), [ 'status' => 500 ] );
        }

        // --- Step 2: build per-slide video clips ---
        $clip_files = [];
        foreach ( $slides as $slide ) {
            $slide_number = (int) $slide->slide_number;
            $audio_file   = trim( $slide->audio_file ?? '' );
            $image_path   = $images[ $slide_number - 1 ] ?? null;

            // Fall back to the conventional path if the DB column is empty.
            if ( empty( $audio_file ) ) {
                $audio_file = $job_dir . "slide-{$slide_number}.mp3";
            }

            if ( ! file_exists( $audio_file ) ) {
                return new WP_Error(
                    'missing_audio',
                    "Slide {$slide_number} has no audio file. Generate voiceover audio first.",
                    [ 'status' => 400 ]
                );
            }

            if ( ! $image_path || ! file_exists( $image_path ) ) {
                return new WP_Error(
                    'missing_image',
                    "No rendered image found for slide {$slide_number}.",
                    [ 'status' => 500 ]
                );
            }

            $clip_path = $job_dir . "clip-{$slide_number}.mp4";

            $cmd = implode( ' ', [
                'ffmpeg -y',
                '-loop 1 -i', escapeshellarg( $image_path ),
                '-i',         escapeshellarg( $audio_file ),
                '-c:v libx264 -tune stillimage',
                '-c:a aac -b:a 192k',
                '-pix_fmt yuv420p',
                '-shortest',
                '-vf', escapeshellarg(
                    'scale=1920:1080:force_original_aspect_ratio=decrease,' .
                    'pad=1920:1080:(ow-iw)/2:(oh-ih)/2:color=black'
                ),
                escapeshellarg( $clip_path ),
                '2>&1',
            ] );

            exec( $cmd, $ffmpeg_out, $ffmpeg_code );

            if ( 0 !== $ffmpeg_code || ! file_exists( $clip_path ) ) {
                return new WP_Error(
                    'ffmpeg_clip_error',
                    "ffmpeg failed building clip for slide {$slide_number}: " . implode( ' ', $ffmpeg_out ),
                    [ 'status' => 500 ]
                );
            }

            $clip_files[] = $clip_path;
        }

        // --- Step 3: concatenate clips ---
        $concat_list = $job_dir . 'concat.txt';
        $list_lines  = array_map(
            fn( $f ) => "file '" . str_replace( "'", "'\\''", $f ) . "'",
            $clip_files
        );
        file_put_contents( $concat_list, implode( "\n", $list_lines ) );

        $video_path = $job_dir . 'output.mp4';

        $cmd = implode( ' ', [
            'ffmpeg -y',
            '-f concat -safe 0 -i', escapeshellarg( $concat_list ),
            '-c copy',
            escapeshellarg( $video_path ),
            '2>&1',
        ] );

        exec( $cmd, $ffmpeg_out, $ffmpeg_code );

        if ( 0 !== $ffmpeg_code || ! file_exists( $video_path ) ) {
            return new WP_Error(
                'ffmpeg_concat_error',
                'ffmpeg concat failed: ' . implode( ' ', $ffmpeg_out ),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [
            'success'   => true,
            'video_url' => $base_url . $job_id . '/output.mp4',
        ] );
    }

    /**
     * POST /wp-json/pitchdeck/v1/generate-audio
     *
     * Accepts: application/json {
     *   job_id: string,
     *   slide_number?: int,          // generate only this slide
     *   scripts?: [{slide_number, script_text}]  // use these texts instead of DB
     * }
     * Returns: { success: bool, audio: [{slide_number, audio_url}, ...] }
     */
    public static function handle_generate_audio( WP_REST_Request $request ) {
        $job_id       = $request->get_param( 'job_id' );
        $only_slide   = $request->get_param( 'slide_number' );
        $scripts_param = $request->get_param( 'scripts' );

        $slides = Pitchdeck_DB::get_slides_by_job( $job_id );

        if ( empty( $slides ) ) {
            return new WP_Error( 'no_slides', 'No slides found for this job. Save slides first.', [ 'status' => 404 ] );
        }

        // Build a map of slide_number => script_text from the request if provided.
        $script_overrides = [];
        if ( is_array( $scripts_param ) ) {
            foreach ( $scripts_param as $item ) {
                $num = (int) ( $item['slide_number'] ?? 0 );
                if ( $num > 0 ) {
                    $script_overrides[ $num ] = $item['script_text'] ?? '';
                }
            }
        }

        $upload_dir = wp_upload_dir();
        $audio_dir  = trailingslashit( $upload_dir['basedir'] ) . 'pitchdeck/' . $job_id . '/';
        $audio_url  = trailingslashit( $upload_dir['baseurl'] ) . 'pitchdeck/' . $job_id . '/';
        wp_mkdir_p( $audio_dir );

        set_time_limit( 300 );

        $output = [];
        foreach ( $slides as $slide ) {
            $slide_number = (int) $slide->slide_number;

            // Skip slides not requested when generating a single slide.
            if ( null !== $only_slide && $slide_number !== (int) $only_slide ) {
                continue;
            }

            // Use only the on-page script text passed from the frontend.
            if ( ! array_key_exists( $slide_number, $script_overrides ) ) {
                continue;
            }
            $script_text = trim( $script_overrides[ $slide_number ] );

            if ( empty( $script_text ) ) {
                continue;
            }

            try {
                $audio_binary = Pitchdeck_OpenAI::generate_audio( $script_text );
            } catch ( RuntimeException $e ) {
                return new WP_Error( 'openai_tts_error', $e->getMessage(), [ 'status' => 502 ] );
            }

            $filename = "slide-{$slide_number}.mp3";
            $filepath = $audio_dir . $filename;

            if ( false === file_put_contents( $filepath, $audio_binary ) ) {
                return new WP_Error( 'file_write_error', "Could not write audio for slide {$slide_number}.", [ 'status' => 500 ] );
            }

            Pitchdeck_DB::save_audio_file( $job_id, $slide_number, $filepath );

            $output[] = [
                'slide_number' => $slide_number,
                'audio_url'    => $audio_url . $filename,
            ];
        }

        return rest_ensure_response( [
            'success' => true,
            'audio'   => $output,
        ] );
    }

    /**
     * Generate a UUID v4 string using random_bytes().
     */
    private static function generate_uuid_v4(): string {
        $data    = random_bytes( 16 );
        $data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // version 4
        $data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // variant
        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
    }
}
