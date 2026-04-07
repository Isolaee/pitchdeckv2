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
        echo '</div>';
    }
}
