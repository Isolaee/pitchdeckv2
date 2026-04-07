<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_Admin {

    const OPTION_GROUP      = 'pitchdeck_settings';
    const OPTION_PAGE       = 'pitchdeck-settings';
    const OPTION_OPENAI     = 'pitchdeck_openai_api_key';
    const OPTION_ELEVENLABS = 'pitchdeck_elevenlabs_api_key';
    const OPTION_PRODUCT_ID = 'pitchdeck_product_id';

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

    public static function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_OPENAI,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        add_settings_section(
            'pitchdeck_api_section',
            'API Keys',
            '__return_false',
            self::OPTION_PAGE
        );

        add_settings_field(
            self::OPTION_OPENAI,
            'OpenAI API Key',
            [ __CLASS__, 'render_api_key_field' ],
            self::OPTION_PAGE,
            'pitchdeck_api_section'
        );

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_ELEVENLABS,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        add_settings_field(
            self::OPTION_ELEVENLABS,
            'ElevenLabs API Key',
            [ __CLASS__, 'render_elevenlabs_api_key_field' ],
            self::OPTION_PAGE,
            'pitchdeck_api_section'
        );

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_PRODUCT_ID,
            [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ]
        );

        add_settings_section(
            'pitchdeck_woocommerce_section',
            'WooCommerce',
            '__return_false',
            self::OPTION_PAGE
        );

        add_settings_field(
            self::OPTION_PRODUCT_ID,
            'Pitchdeck Product ID',
            [ __CLASS__, 'render_product_id_field' ],
            self::OPTION_PAGE,
            'pitchdeck_woocommerce_section'
        );
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
