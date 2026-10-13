<?php
/**
 * Plugin Name: Order Flow matrix helper (development only, not shipped)
 *
 * Installed by bin/matrix-cell.sh into a throwaway test site only. It answers a few read-only questions for the
 * browserless smoke test (bin/matrix-http.py): a nonce for the signed-in user, the status of an order, the URLs
 * of the My Account pages. It never exists on a real site.
 */

add_action(
	'init',
	static function (): void {
		if ( ! isset( $_GET['of_matrix'] ) || ! is_string( $_GET['of_matrix'] ) ) { // phpcs:ignore
			return;
		}
		$what = sanitize_key( wp_unslash( $_GET['of_matrix'] ) ); // phpcs:ignore
		$out  = array( 'user' => get_current_user_id() );
		if ( 'nonce' === $what ) {
			$action       = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? wp_unslash( $_GET['action'] ) : ''; // phpcs:ignore
			$out['nonce'] = wp_create_nonce( $action );
		} elseif ( 'order' === $what ) {
			$order        = wc_get_order( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore
			$out['found'] = $order instanceof WC_Order;
			$out['status'] = $order instanceof WC_Order ? $order->get_status() : '';
			$out['history'] = $order instanceof WC_Order && is_array( $order->get_meta( '_ofwc_history' ) ) ? count( $order->get_meta( '_ofwc_history' ) ) : 0;
		} elseif ( 'urls' === $what ) {
			$id                = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
			$out['orders']     = wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) );
			$out['view_order'] = wc_get_endpoint_url( 'view-order', (string) $id, wc_get_page_permalink( 'myaccount' ) );
			$out['hpos']       = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			$out['admin']      = admin_url();
		}
		nocache_headers();
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $out ); // phpcs:ignore
		exit;
	},
	99
);
