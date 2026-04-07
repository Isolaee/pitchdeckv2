<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_WooCommerce {

    public static function init(): void {
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'attach_job_id_to_order' ] );
        add_action( 'woocommerce_thankyou',               [ __CLASS__, 'render_download_button' ] );
    }

    /**
     * After the WooCommerce order row is created (before payment), pull the
     * pitchdeck job_id from the WC session and store it as order meta so we
     * can find the video file after payment completes.
     */
    public static function attach_job_id_to_order( $order ): void {
        if ( ! WC()->session ) {
            return;
        }
        $job_id = WC()->session->get( 'pitchdeck_job_id' );
        if ( ! $job_id ) {
            return;
        }
        $order->update_meta_data( '_pitchdeck_job_id', sanitize_text_field( $job_id ) );
        $order->save();
        WC()->session->__unset( 'pitchdeck_job_id' );
    }

    /**
     * Render a download button on the WooCommerce order-received page when
     * the order carries a pitchdeck job_id and has been paid.
     */
    public static function render_download_button( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $job_id = $order->get_meta( '_pitchdeck_job_id' );
        if ( ! $job_id ) {
            return;
        }

        if ( ! $order->is_paid() ) {
            echo '<p>' . esc_html__( 'Videosi on ladattavissa heti maksun vahvistuttua.', 'pitchdeck' ) . '</p>';
            return;
        }

        $download_url = add_query_arg(
            [
                'order_id'  => $order_id,
                'order_key' => $order->get_order_key(),
            ],
            rest_url( 'pitchdeck/v1/download' )
        );

        echo '<div class="pitchdeck-thankyou-download" style="margin:2em 0;">';
        echo '<h2>' . esc_html__( 'Pitchdeck-videosi', 'pitchdeck' ) . '</h2>';
        echo '<p>' . esc_html__( 'Videosi on valmis. Klikkaa alla olevaa painiketta ladataksesi MP4-tiedoston.', 'pitchdeck' ) . '</p>';
        echo '<a href="' . esc_url( $download_url ) . '" class="button wc-forward">'
            . esc_html__( 'Lataa MP4', 'pitchdeck' ) . '</a>';
        echo '</div>';
    }
}
