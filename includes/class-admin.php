<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_Admin {

    const OPTION_GROUP      = 'pitchdeck_settings';
    const OPTION_PAGE       = 'pitchdeck-settings';
    const OPTION_OPENAI     = 'pitchdeck_openai_api_key';
    const OPTION_ELEVENLABS = 'pitchdeck_elevenlabs_api_key';
    const OPTION_PRODUCT_ID = 'pitchdeck_product_id';
    const OPTION_TEXTS      = 'pitchdeck_texts';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'wp_ajax_pitchdeck_test_api_key',            [ __CLASS__, 'ajax_test_api_key' ] );
        add_action( 'wp_ajax_pitchdeck_test_elevenlabs_api_key', [ __CLASS__, 'ajax_test_elevenlabs_api_key' ] );
    }

    public static function add_menu(): void {
        add_options_page(
            'Pitchdeck Settings',
            'Pitchdeck',
            'manage_options',
            self::OPTION_PAGE,
            [ __CLASS__, 'render_page' ]
        );
    }

    // ── Text field definitions ────────────────────────────────────────

    /**
     * All configurable text fields with their section, label, type and default value.
     */
    private static function text_definitions(): array {
        return [
            // Popup window
            'popup_p1'          => [ 'section' => 'popup',   'label' => 'Popup: first paragraph',                          'type' => 'textarea', 'default' => 'Pitch deck herättää kiinnostuksen – mutta harvoin yksin riittää. Dekissä on rajallisesti tilaa kertoa idean tausta, markkina, kilpailuetu ja kasvupotentiaali. Sijoittajille jää helposti kysymyksiä, jotka vaativat erillisiä tapaamisia tai lisämateriaaleja.' ],
            'popup_p2'          => [ 'section' => 'popup',   'label' => 'Popup: second paragraph',                         'type' => 'textarea', 'default' => 'Muuttamalla pitch deckin videoksi viet viestisi seuraavalle tasolle. Video antaa mahdollisuuden avata ideaa syvällisemmin, kertoa tarina vakuuttavammin ja tuoda esiin juuri ne asiat, jotka ratkaisevat sijoittajan kiinnostuksen. Samalla tavoitat laajemman joukon kiinnostuneita sijoittajia helposti ja tehokkaasti, silloin kun heille parhaiten sopii.' ],
            'popup_btn'         => [ 'section' => 'popup',   'label' => 'Popup: button',                                   'type' => 'text',     'default' => 'Jatka' ],
            // Navigation
            'step_1'            => [ 'section' => 'nav',     'label' => 'Step 1 label',                                    'type' => 'text',     'default' => 'Aloitus' ],
            'step_2'            => [ 'section' => 'nav',     'label' => 'Step 2 label',                                    'type' => 'text',     'default' => 'Lataus' ],
            'step_3'            => [ 'section' => 'nav',     'label' => 'Step 3 label',                                    'type' => 'text',     'default' => 'Skriptit' ],
            'step_4'            => [ 'section' => 'nav',     'label' => 'Step 4 label',                                    'type' => 'text',     'default' => 'Video' ],
            // Panel 1 – Landing
            'hero_title'        => [ 'section' => 'panel1',  'label' => 'Panel 1: hero title',                             'type' => 'text',     'default' => 'Muuta esityksesi ääniselostevideoksi' ],
            'hero_body'         => [ 'section' => 'panel1',  'label' => 'Panel 1: hero body',                              'type' => 'textarea', 'default' => 'Lataa PPTX tai PDF, anna tekoälyn kirjoittaa selostusteksti jokaiselle dialle, muokkaa tekstiä ja vie valmis MP4.' ],
            'step_n1_label'     => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 1 label',                   'type' => 'text',     'default' => 'Lataa' ],
            'step_n1_desc'      => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 1 description',             'type' => 'text',     'default' => 'PPTX tai PDF' ],
            'step_n2_label'     => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 2 label',                   'type' => 'text',     'default' => 'Luo' ],
            'step_n2_desc'      => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 2 description',             'type' => 'text',     'default' => 'Tekoäly kirjoittaa skriptit' ],
            'step_n3_label'     => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 3 label',                   'type' => 'text',     'default' => 'Muokkaa' ],
            'step_n3_desc'      => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 3 description',             'type' => 'text',     'default' => 'Tarkista selostukset' ],
            'step_n4_label'     => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 4 label',                   'type' => 'text',     'default' => 'Vie' ],
            'step_n4_desc'      => [ 'section' => 'panel1',  'label' => 'Panel 1: process step 4 description',             'type' => 'text',     'default' => 'Lataa MP4' ],
            'btn_start'         => [ 'section' => 'panel1',  'label' => 'Panel 1: get started button',                     'type' => 'text',     'default' => 'Aloita →' ],
            // Panel 2 – Upload
            'p2_title'          => [ 'section' => 'panel2',  'label' => 'Panel 2: title',                                  'type' => 'text',     'default' => 'Lataa esityksesi' ],
            'p2_subtitle'       => [ 'section' => 'panel2',  'label' => 'Panel 2: subtitle',                               'type' => 'text',     'default' => 'Tuetut formaatit: .pptx ja .pdf' ],
            'dropzone_text'     => [ 'section' => 'panel2',  'label' => 'Panel 2: dropzone text',                          'type' => 'text',     'default' => 'Valitse tiedosto tai vedä se tähän' ],
            'dropzone_hint'     => [ 'section' => 'panel2',  'label' => 'Panel 2: dropzone hint',                          'type' => 'text',     'default' => '.pptx tai .pdf' ],
            'lang_label'        => [ 'section' => 'panel2',  'label' => 'Panel 2: language selector label',                'type' => 'text',     'default' => 'Skriptin kieli' ],
            'voice_label'       => [ 'section' => 'panel2',  'label' => 'Panel 2: voice picker label',                     'type' => 'text',     'default' => 'Ääni' ],
            'btn_upload'        => [ 'section' => 'panel2',  'label' => 'Panel 2: generate scripts button',                'type' => 'text',     'default' => 'Luo skriptit' ],
            // Panel 3 – Scripts
            'p3_title'          => [ 'section' => 'panel3',  'label' => 'Panel 3: title',                                  'type' => 'text',     'default' => 'Tarkista ja muokkaa skriptit' ],
            'p3_subtitle'       => [ 'section' => 'panel3',  'label' => 'Panel 3: subtitle',                               'type' => 'textarea', 'default' => 'Jokainen skripti luetaan ääneen sen dialle. Äänitys käyttää täsmälleen sitä, mitä näet tässä.' ],
            'btn_audio'         => [ 'section' => 'panel3',  'label' => 'Panel 3: generate all audio button',              'type' => 'text',     'default' => 'Luo kaikki äänitykset' ],
            'btn_video'         => [ 'section' => 'panel3',  'label' => 'Panel 3: generate video button',                  'type' => 'text',     'default' => 'Luo video' ],
            // Panel 4 – Video
            'p4_title'          => [ 'section' => 'panel4',  'label' => 'Panel 4: title',                                  'type' => 'text',     'default' => 'Videosi on valmis' ],
            'btn_buy'           => [ 'section' => 'panel4',  'label' => 'Panel 4: buy button',                             'type' => 'text',     'default' => 'Osta ja lataa MP4' ],
            'btn_restart'       => [ 'section' => 'panel4',  'label' => 'Panel 4: start over button',                      'type' => 'text',     'default' => 'Aloita alusta' ],
            // Dynamic JS texts
            'slide_label'       => [ 'section' => 'dynamic', 'label' => 'Slide heading prefix ({n} = slide number)',        'type' => 'text',     'default' => 'Dia' ],
            'btn_slide_audio'   => [ 'section' => 'dynamic', 'label' => 'Per-slide audio button',                          'type' => 'text',     'default' => 'Luo äänitys tälle dialle' ],
            'overlay_upload'    => [ 'section' => 'dynamic', 'label' => 'Overlay: uploading file',                         'type' => 'text',     'default' => 'Ladataan ja puretaan dioja,…' ],
            'overlay_save'      => [ 'section' => 'dynamic', 'label' => 'Overlay: saving slides ({n} = slide count)',      'type' => 'text',     'default' => 'Tallennetaan {n} dia…' ],
            'overlay_script'    => [ 'section' => 'dynamic', 'label' => 'Overlay: generating scripts',                     'type' => 'text',     'default' => 'Luodaan käsikirjoitusta,… tämä voi kestää hetken.' ],
            'overlay_audio'     => [ 'section' => 'dynamic', 'label' => 'Overlay: generating all audio',                   'type' => 'text',     'default' => 'Luodaan äänityksiä… tämä voi kestää hetken.' ],
            'overlay_slide_audio' => [ 'section' => 'dynamic', 'label' => 'Overlay: per-slide audio ({n} = slide number)', 'type' => 'text',     'default' => 'Luodaan äänitys dialle {n}…' ],
            'overlay_video'     => [ 'section' => 'dynamic', 'label' => 'Overlay: generating video',                       'type' => 'text',     'default' => 'Luodaan videota,… tämä voi kestää useita minuutteja.' ],
            'overlay_checkout'  => [ 'section' => 'dynamic', 'label' => 'Overlay: redirecting to checkout',                'type' => 'text',     'default' => 'Siirrytään kassalle…' ],
        ];
    }

    /**
     * Returns the saved text for $key, falling back to the hardcoded default.
     * Empty string in the saved option is treated as "not set" so the default shows.
     */
    public static function get_text( string $key ): string {
        static $saved = null;
        if ( null === $saved ) {
            $saved = (array) get_option( self::OPTION_TEXTS, [] );
        }
        if ( isset( $saved[ $key ] ) && '' !== $saved[ $key ] ) {
            return $saved[ $key ];
        }
        $defs = self::text_definitions();
        return $defs[ $key ]['default'] ?? '';
    }

    /**
     * Returns all texts as a flat key→value array (for wp_localize_script).
     */
    public static function get_all_texts(): array {
        $out = [];
        foreach ( array_keys( self::text_definitions() ) as $key ) {
            $out[ $key ] = self::get_text( $key );
        }
        return $out;
    }

    // ── Settings registration ─────────────────────────────────────────

    public static function register_settings(): void {
        // API keys
        register_setting( self::OPTION_GROUP, self::OPTION_OPENAI,     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( self::OPTION_GROUP, self::OPTION_ELEVENLABS, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( self::OPTION_GROUP, self::OPTION_PRODUCT_ID, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ] );

        add_settings_section( 'pitchdeck_api_section',         'API Keys',    '__return_false', self::OPTION_PAGE );
        add_settings_section( 'pitchdeck_woocommerce_section', 'WooCommerce', '__return_false', self::OPTION_PAGE );

        add_settings_field( self::OPTION_OPENAI,     'OpenAI API Key',      [ __CLASS__, 'render_api_key_field' ],          self::OPTION_PAGE, 'pitchdeck_api_section' );
        add_settings_field( self::OPTION_ELEVENLABS, 'ElevenLabs API Key',  [ __CLASS__, 'render_elevenlabs_api_key_field' ], self::OPTION_PAGE, 'pitchdeck_api_section' );
        add_settings_field( self::OPTION_PRODUCT_ID, 'Pitchdeck Product ID', [ __CLASS__, 'render_product_id_field' ],       self::OPTION_PAGE, 'pitchdeck_woocommerce_section' );

        // Texts
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_TEXTS,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize_texts' ],
                'default'           => [],
            ]
        );

        add_settings_section( 'pitchdeck_texts_section', 'Texts &amp; Labels', '__return_false', self::OPTION_PAGE );

        add_settings_field(
            self::OPTION_TEXTS,
            '',
            [ __CLASS__, 'render_texts_field' ],
            self::OPTION_PAGE,
            'pitchdeck_texts_section'
        );
    }

    public static function sanitize_texts( $input ): array {
        $clean = [];
        foreach ( self::text_definitions() as $key => $def ) {
            if ( ! isset( $input[ $key ] ) ) {
                continue;
            }
            $clean[ $key ] = 'textarea' === $def['type']
                ? sanitize_textarea_field( $input[ $key ] )
                : sanitize_text_field( $input[ $key ] );
        }
        return $clean;
    }

    // ── Field renderers ───────────────────────────────────────────────

    public static function render_texts_field(): void {
        $defs  = self::text_definitions();
        $saved = (array) get_option( self::OPTION_TEXTS, [] );

        $sections = [
            'popup'   => 'Popup window',
            'nav'     => 'Navigation steps',
            'panel1'  => 'Panel 1 – Landing',
            'panel2'  => 'Panel 2 – Upload',
            'panel3'  => 'Panel 3 – Scripts',
            'panel4'  => 'Panel 4 – Video',
            'dynamic' => 'Dynamic messages (JS)',
        ];

        foreach ( $sections as $section_key => $section_label ) {
            echo '<h3 style="margin-top:2em;padding-top:1em;border-top:1px solid #ddd;">' . esc_html( $section_label ) . '</h3>';
            echo '<table class="form-table" role="presentation"><tbody>';

            foreach ( $defs as $key => $def ) {
                if ( $def['section'] !== $section_key ) {
                    continue;
                }

                $value      = $saved[ $key ] ?? '';
                $field_name = self::OPTION_TEXTS . '[' . $key . ']';
                $field_id   = 'pd_text_' . $key;

                echo '<tr>';
                echo '<th scope="row" style="width:220px;"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $def['label'] ) . '</label></th>';
                echo '<td>';

                if ( 'textarea' === $def['type'] ) {
                    echo '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
                } else {
                    echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
                }

                echo '<p class="description" style="color:#888;">Default: ' . esc_html( $def['default'] ) . '</p>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    public static function render_api_key_field(): void {
        $value = get_option( self::OPTION_OPENAI, '' );
        ?>
        <input
            type="password"
            id="<?php echo esc_attr( self::OPTION_OPENAI ); ?>"
            name="<?php echo esc_attr( self::OPTION_OPENAI ); ?>"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
            autocomplete="off"
        />
        <button
            type="button"
            id="pitchdeck-test-api-btn"
            class="button button-secondary"
            style="margin-left:8px;"
        >Test API Key</button>
        <span id="pitchdeck-test-api-result" style="margin-left:10px;"></span>
        <p class="description">
            Your OpenAI API key. Found at
            <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>.
        </p>
        <script>
        document.getElementById('pitchdeck-test-api-btn').addEventListener('click', function() {
            var btn    = this;
            var result = document.getElementById('pitchdeck-test-api-result');
            var key    = document.getElementById('<?php echo esc_js( self::OPTION_OPENAI ); ?>').value.trim();

            if ( ! key ) {
                result.style.color = '#cc0000';
                result.textContent = 'Enter an API key first.';
                return;
            }

            btn.disabled    = true;
            result.style.color = '#555';
            result.textContent = 'Testing\u2026';

            var data = new FormData();
            data.append( 'action', 'pitchdeck_test_api_key' );
            data.append( 'nonce',  '<?php echo esc_js( wp_create_nonce( 'pitchdeck_test_api_key' ) ); ?>' );
            data.append( 'api_key', key );

            fetch( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                body:   data,
            } )
            .then( function(r) { return r.json(); } )
            .then( function(res) {
                if ( res.success ) {
                    result.style.color = '#00a000';
                    result.textContent = '\u2713 ' + res.data;
                } else {
                    result.style.color = '#cc0000';
                    result.textContent = '\u2717 ' + res.data;
                }
            } )
            .catch( function() {
                result.style.color = '#cc0000';
                result.textContent = 'Request failed.';
            } )
            .finally( function() {
                btn.disabled = false;
            } );
        } );
        </script>
        <?php
    }

    /**
     * AJAX handler — calls OpenAI Responses API and returns a haiku on success.
     */
    public static function ajax_test_api_key(): void {
        check_ajax_referer( 'pitchdeck_test_api_key', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

        if ( empty( $api_key ) ) {
            wp_send_json_error( 'No API key provided.' );
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            [
                'timeout' => 20,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode( [
                    'model' => 'gpt-5-nano',
                    'input' => 'write a haiku about ai',
                    'store' => true,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Connection error: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== (int) $code ) {
            $msg = $body['error']['message'] ?? "HTTP {$code}";
            wp_send_json_error( $msg );
        }

        // Extract text from the response output array.
        $text = '';
        foreach ( $body['output'] ?? [] as $item ) {
            foreach ( $item['content'] ?? [] as $part ) {
                if ( 'output_text' === ( $part['type'] ?? '' ) ) {
                    $text = $part['text'];
                    break 2;
                }
            }
        }

        wp_send_json_success( $text ?: 'API key is valid.' );
    }

    public static function render_elevenlabs_api_key_field(): void {
        $value = get_option( self::OPTION_ELEVENLABS, '' );
        ?>
        <input
            type="password"
            id="<?php echo esc_attr( self::OPTION_ELEVENLABS ); ?>"
            name="<?php echo esc_attr( self::OPTION_ELEVENLABS ); ?>"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
            autocomplete="off"
        />
        <button
            type="button"
            id="pitchdeck-test-elevenlabs-btn"
            class="button button-secondary"
            style="margin-left:8px;"
        >Test API Key</button>
        <span id="pitchdeck-test-elevenlabs-result" style="margin-left:10px;"></span>
        <p class="description">
            Your ElevenLabs API key. Found at
            <a href="https://elevenlabs.io/app/settings/api-keys" target="_blank" rel="noopener">elevenlabs.io/app/settings/api-keys</a>.
            Required only when using ElevenLabs voices.
        </p>
        <script>
        document.getElementById('pitchdeck-test-elevenlabs-btn').addEventListener('click', function() {
            var btn    = this;
            var result = document.getElementById('pitchdeck-test-elevenlabs-result');
            var key    = document.getElementById('<?php echo esc_js( self::OPTION_ELEVENLABS ); ?>').value.trim();

            if ( ! key ) {
                result.style.color = '#cc0000';
                result.textContent = 'Enter an API key first.';
                return;
            }

            btn.disabled       = true;
            result.style.color = '#555';
            result.textContent = 'Testing\u2026';

            var data = new FormData();
            data.append( 'action', 'pitchdeck_test_elevenlabs_api_key' );
            data.append( 'nonce',  '<?php echo esc_js( wp_create_nonce( 'pitchdeck_test_elevenlabs_api_key' ) ); ?>' );
            data.append( 'api_key', key );

            fetch( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                body:   data,
            } )
            .then( function(r) { return r.json(); } )
            .then( function(res) {
                if ( res.success ) {
                    result.style.color = '#00a000';
                    result.textContent = '\u2713 ' + res.data;
                } else {
                    result.style.color = '#cc0000';
                    result.textContent = '\u2717 ' + res.data;
                }
            } )
            .catch( function() {
                result.style.color = '#cc0000';
                result.textContent = 'Request failed.';
            } )
            .finally( function() {
                btn.disabled = false;
            } );
        } );
        </script>
        <?php
    }

    /**
     * AJAX handler — verifies ElevenLabs API key via GET /v1/user.
     */
    public static function ajax_test_elevenlabs_api_key(): void {
        check_ajax_referer( 'pitchdeck_test_elevenlabs_api_key', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

        if ( empty( $api_key ) ) {
            wp_send_json_error( 'No API key provided.' );
        }

        $response = wp_remote_get(
            'https://api.elevenlabs.io/v1/user',
            [
                'timeout' => 15,
                'headers' => [
                    'xi-api-key' => $api_key,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Connection error: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== (int) $code ) {
            $msg = $body['detail']['message'] ?? ( is_string( $body['detail'] ?? null ) ? $body['detail'] : null ) ?? "HTTP {$code}";
            wp_send_json_error( $msg );
        }

        $tier = $body['subscription']['tier'] ?? 'unknown';
        wp_send_json_success( "API key is valid. Plan: {$tier}." );
    }

    public static function render_product_id_field(): void {
        $value = (int) get_option( self::OPTION_PRODUCT_ID, 0 );
        ?>
        <input
            type="number"
            id="<?php echo esc_attr( self::OPTION_PRODUCT_ID ); ?>"
            name="<?php echo esc_attr( self::OPTION_PRODUCT_ID ); ?>"
            value="<?php echo esc_attr( $value ?: '' ); ?>"
            class="small-text"
            min="0"
            step="1"
        />
        <p class="description">
            The ID of the WooCommerce product customers purchase to download their video.
            Create a <strong>Simple</strong> product in WooCommerce (virtual, not downloadable —
            the plugin handles the download), set your price, then paste its ID here.
        </p>
        <?php
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Pitchdeck Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::OPTION_PAGE );
                submit_button( 'Save Settings' );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Helper to retrieve the OpenAI API key from anywhere in the plugin.
     */
    public static function get_openai_api_key(): string {
        return (string) get_option( self::OPTION_OPENAI, '' );
    }

    /**
     * Helper to retrieve the ElevenLabs API key from anywhere in the plugin.
     */
    public static function get_elevenlabs_api_key(): string {
        return (string) get_option( self::OPTION_ELEVENLABS, '' );
    }
}
