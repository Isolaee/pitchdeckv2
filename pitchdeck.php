<?php
/**
 * Plugin Name: Pitchdeck
 * Description: Upload a PPTX, extract slide text, add notes per slide, generate voiceover scripts.
 * Version:     0.2.0
 * Author:      Eero Isola
 * Text Domain: pitchdeck
 */

defined( 'ABSPATH' ) || exit;

define( 'PITCHDECK_VERSION',    '0.2.0' );
define( 'PITCHDECK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PITCHDECK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( PITCHDECK_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once PITCHDECK_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once PITCHDECK_PLUGIN_DIR . 'includes/class-db.php';
require_once PITCHDECK_PLUGIN_DIR . 'includes/class-pptx-parser.php';
require_once PITCHDECK_PLUGIN_DIR . 'includes/class-pdf-parser.php';
require_once PITCHDECK_PLUGIN_DIR . 'includes/class-openai.php';
require_once PITCHDECK_PLUGIN_DIR . 'includes/class-slide-renderer.php';
require_once PITCHDECK_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once PITCHDECK_PLUGIN_DIR . 'includes/class-admin.php';

// Create / upgrade DB table on activation and on plugin load when version changes.
register_activation_hook( __FILE__, [ 'Pitchdeck_DB', 'create_table' ] );
add_action( 'plugins_loaded', function () {
    if ( get_option( 'pitchdeck_db_version' ) !== PITCHDECK_VERSION ) {
        Pitchdeck_DB::create_table();
    }
} );

// Boot REST API.
add_action( 'rest_api_init', [ 'Pitchdeck_REST_API', 'register_routes' ] );

// Boot admin settings.
if ( is_admin() ) {
    Pitchdeck_Admin::init();
}

// Register shortcode.
add_shortcode( 'pitchdeck', 'pitchdeck_shortcode_render' );

// Register assets (only enqueued when shortcode is on the page).
add_action( 'wp_enqueue_scripts', 'pitchdeck_register_assets' );

function pitchdeck_register_assets(): void {
    wp_register_script(
        'pitchdeck-js',
        PITCHDECK_PLUGIN_URL . 'assets/pitchdeck.js',
        [],
        PITCHDECK_VERSION,
        true
    );
    wp_localize_script( 'pitchdeck-js', 'pitchdeck_config', [
        'rest_url' => esc_url_raw( rest_url( 'pitchdeck/v1' ) ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
    ] );
    wp_register_style(
        'pitchdeck-css',
        PITCHDECK_PLUGIN_URL . 'assets/pitchdeck.css',
        [],
        PITCHDECK_VERSION
    );
}

function pitchdeck_shortcode_render( array $atts ): string {
    wp_enqueue_script( 'pitchdeck-js' );
    wp_enqueue_style( 'pitchdeck-css' );

    ob_start();
    ?>
    <div id="pitchdeck-app">

        <!-- Step indicator -->
        <nav class="pd-steps">
            <div class="pd-step pd-step--active" data-step="1"><span class="pd-step-circle">1</span><span class="pd-step-label">Start</span></div>
            <div class="pd-step" data-step="2"><span class="pd-step-circle">2</span><span class="pd-step-label">Upload</span></div>
            <div class="pd-step" data-step="3"><span class="pd-step-circle">3</span><span class="pd-step-label">Scripts</span></div>
            <div class="pd-step" data-step="4"><span class="pd-step-circle">4</span><span class="pd-step-label">Video</span></div>
        </nav>

        <!-- Status message (lives outside panels so position stays fixed) -->
        <div id="pitchdeck-status" class="pitchdeck-status" hidden></div>

        <!-- Panel 1: Landing -->
        <section id="pd-panel-1" class="pd-panel">
            <div class="pd-hero">
                <h2>Turn your presentation into a voiceover video</h2>
                <p>Upload a PPTX or PDF, let AI write a narration script for each slide, refine the text, and export a ready-to-share MP4.</p>
            </div>
            <div class="pd-process">
                <div class="pd-process-item"><span class="pd-process-n">1</span><strong>Upload</strong><span>Your PPTX or PDF</span></div>
                <div class="pd-process-item"><span class="pd-process-n">2</span><strong>Generate</strong><span>AI writes slide scripts</span></div>
                <div class="pd-process-item"><span class="pd-process-n">3</span><strong>Refine</strong><span>Edit each narration</span></div>
                <div class="pd-process-item"><span class="pd-process-n">4</span><strong>Export</strong><span>Download your MP4</span></div>
            </div>
            <div class="pd-hero-action">
                <button id="pd-get-started-btn" class="pd-btn pd-btn--primary pd-btn--lg">Get Started &rarr;</button>
            </div>
        </section>

        <!-- Panel 2: Upload -->
        <section id="pd-panel-2" class="pd-panel" hidden>
            <h2>Upload your presentation</h2>
            <p class="pd-subtitle">Supported formats: <strong>.pptx</strong> and <strong>.pdf</strong></p>
            <form id="pitchdeck-upload-form" enctype="multipart/form-data">
                <label class="pd-dropzone" for="pitchdeck-file">
                    <svg class="pd-dropzone-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="pd-dropzone-text">Choose a file or drag it here</span>
                    <span class="pd-dropzone-hint">.pptx or .pdf</span>
                    <span id="pd-file-name" class="pd-dropzone-filename"></span>
                    <input type="file" id="pitchdeck-file" name="pptx_file" accept=".pptx,.pdf" required />
                </label>
                <div class="pd-form-row">
                    <label for="pitchdeck-language" class="pd-label">Script language</label>
                    <select id="pitchdeck-language" class="pd-select">
                        <option value="Finnish">Finnish</option>
                        <option value="English">English</option>
                        <option value="Swedish">Swedish</option>
                    </select>
                </div>
                <button type="submit" class="pd-btn pd-btn--primary">Generate Scripts</button>
            </form>
        </section>

        <!-- Panel 3: Edit Scripts -->
        <section id="pd-panel-3" class="pd-panel" hidden>
            <h2>Review &amp; edit scripts</h2>
            <p class="pd-subtitle">Each script is read aloud for its slide. The voiceover uses exactly what you see here.</p>
            <div id="pitchdeck-scripts-container"></div>
            <div class="pd-action-row">
                <button id="pitchdeck-audio-btn" class="pd-btn pd-btn--primary">Generate All Voiceovers</button>
                <button id="pitchdeck-video-btn" class="pd-btn pd-btn--success" hidden>Generate Video</button>
            </div>
        </section>

        <!-- Panel 4: Video -->
        <section id="pd-panel-4" class="pd-panel" hidden>
            <h2>Your video is ready</h2>
            <div class="pd-video-wrap">
                <video id="pitchdeck-video-player" controls></video>
            </div>
            <div class="pd-action-row">
                <a id="pitchdeck-video-download" href="#" download class="pd-btn pd-btn--primary">Download MP4</a>
                <button id="pd-start-over-btn" class="pd-btn pd-btn--ghost">Start over</button>
            </div>
        </section>

        <!-- Loading overlay -->
        <div id="pd-overlay" hidden>
            <div class="pd-overlay-box">
                <div class="pd-spinner"></div>
                <p id="pd-overlay-msg"></p>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
