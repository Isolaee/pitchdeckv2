<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_WooCommerce {

    public static function init(): void {
        // Inject the job_id into cart item data when the add-to-cart URL
        // carries a pitchdeck_token parameter.
        add_filter( 'woocommerce_add_cart_item_data',           [ __CLASS__, 'inject_job_id_into_cart_item' ], 10, 2 );

        // After injecting, redirect straight to checkout instead of cart.
        add_filter( 'woocommerce_add_to_cart_redirect',         [ __CLASS__, 'redirect_to_checkout' ], 10, 2 );

        // When the order line item is created during checkout, copy the
        // job_id from cart item data onto the parent order as meta.
        add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'copy_job_id_to_order' ], 10, 3 );

        // Show the download button on the thank-you page.
        add_action( 'woocommerce_thankyou',                     [ __CLASS__, 'render_download_button' ] );

        // Include download link in order confirmation / completed emails.
        add_action( 'woocommerce_email_after_order_table',      [ __CLASS__, 'add_download_link_to_email' ], 10, 4 );

        // Schedule 48-hour video deletion when payment is confirmed.
        add_action( 'woocommerce_payment_complete',             [ __CLASS__, 'schedule_video_cleanup' ] );

        // Cron handler.
        add_action( 'pitchdeck_delete_video',                   [ __CLASS__, 'delete_video_files' ] );
    }

    /**
     * When the pitchdeck product is added to the cart and a pitchdeck_token
     * is present in the URL, look up the job_id from the transient and store
     * it in the cart item data so it survives to checkout.
     *
     * @param array $cart_item_data
     * @param int   $product_id
     * @return array
     */
    public static function inject_job_id_into_cart_item( array $cart_item_data, int $product_id ): array {
        $configured_id = (int) get_option( 'pitchdeck_product_id', 0 );
        if ( ! $configured_id || $product_id !== $configured_id ) {
            return $cart_item_data;
        }

        $token = sanitize_text_field( wp_unslash( $_GET['pitchdeck_token'] ?? '' ) );
        if ( ! $token ) {
            return $cart_item_data;
        }

        $job_id = get_transient( 'pitchdeck_job_' . $token );
        if ( $job_id ) {
            $cart_item_data['pitchdeck_job_id'] = $job_id;
            delete_transient( 'pitchdeck_job_' . $token );
        }

        return $cart_item_data;
    }

    /**
     * When a pitchdeck token is present in the add-to-cart request, skip the
     * cart page and go directly to checkout.
     *
     * @param string     $url
     * @param WC_Product $product
     * @return string
     */
    public static function redirect_to_checkout( string $url, $product ): string {
        if ( ! empty( $_GET['pitchdeck_token'] ) ) {
            return wc_get_checkout_url();
        }
        return $url;
    }

    /**
     * During checkout order creation, copy the pitchdeck_job_id from cart
     * item data onto the WC_Order as order meta so we can find it later.
     *
     * @param WC_Order_Item_Product $item
     * @param string                $cart_item_key
     * @param array                 $values  Cart item data.
     */
    public static function copy_job_id_to_order( $item, string $cart_item_key, array $values ): void {
        if ( empty( $values['pitchdeck_job_id'] ) ) {
            return;
        }
        $order = $item->get_order();
        if ( $order ) {
            $order->update_meta_data( '_pitchdeck_job_id', sanitize_text_field( $values['pitchdeck_job_id'] ) );
        }
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
        echo '<p style="color:#888;font-size:0.9em;">'
            . esc_html__( 'Linkki on voimassa 48 tuntia.', 'pitchdeck' ) . '</p>';
        echo '</div>';
    }

    /**
     * Add the download link to customer-facing WooCommerce emails.
     *
     * @param WC_Order $order
     * @param bool     $sent_to_admin
     * @param bool     $plain_text
     * @param WC_Email $email
     */
    public static function add_download_link_to_email( $order, bool $sent_to_admin, bool $plain_text, $email ): void {
        // Only attach to customer emails (not admin notifications).
        if ( $sent_to_admin ) {
            return;
        }

        // Only attach once the order is paid (processing / completed emails).
        if ( ! $order->is_paid() ) {
            return;
        }

        $job_id = $order->get_meta( '_pitchdeck_job_id' );
        if ( ! $job_id ) {
            return;
        }

        $download_url = add_query_arg(
            [
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            rest_url( 'pitchdeck/v1/download' )
        );

        if ( $plain_text ) {
            echo "\n";
            echo esc_html__( 'Pitchdeck-videosi', 'pitchdeck' ) . "\n";
            echo esc_html__( 'Lataa MP4-video alla olevasta linkistä (linkki on voimassa 48 tuntia):', 'pitchdeck' ) . "\n";
            echo esc_url( $download_url ) . "\n\n";
        } else {
            echo '<div style="margin:24px 0;padding:16px;border:1px solid #e0e0e0;border-radius:4px;">';
            echo '<h2 style="margin:0 0 8px;">' . esc_html__( 'Pitchdeck-videosi', 'pitchdeck' ) . '</h2>';
            echo '<p style="margin:0 0 12px;">'
                . esc_html__( 'Lataa MP4-video alla olevasta linkistä. Linkki on voimassa 48 tuntia.', 'pitchdeck' )
                . '</p>';
            echo '<a href="' . esc_url( $download_url ) . '" '
                . 'style="display:inline-block;padding:10px 20px;background:#2271b1;color:#fff;'
                . 'text-decoration:none;border-radius:3px;font-weight:bold;">'
                . esc_html__( 'Lataa MP4', 'pitchdeck' )
                . '</a>';
            echo '</div>';
        }
    }

    /**
     * Schedule deletion of the video files 48 hours after payment.
     *
     * @param int $order_id
     */
    public static function schedule_video_cleanup( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $job_id = $order->get_meta( '_pitchdeck_job_id' );
        if ( ! $job_id ) {
            return;
        }

        // Avoid scheduling duplicates (e.g. if the payment webhook fires twice).
        if ( wp_next_scheduled( 'pitchdeck_delete_video', [ $job_id ] ) ) {
            return;
        }

        wp_schedule_single_event( time() + 2 * DAY_IN_SECONDS, 'pitchdeck_delete_video', [ $job_id ] );
    }

    /**
     * Delete all files in the job's upload directory.
     * Called by the 'pitchdeck_delete_video' cron event.
     *
     * @param string $job_id
     */
    public static function delete_video_files( string $job_id ): void {
        $upload_dir = wp_upload_dir();
        $job_dir    = trailingslashit( $upload_dir['basedir'] ) . 'pitchdeck/' . $job_id . '/';

        if ( ! is_dir( $job_dir ) ) {
            return;
        }

        $files = glob( $job_dir . '*' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    @unlink( $file );
                }
            }
        }

        @rmdir( $job_dir );
    }
}
