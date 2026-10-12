<?php
/**
 * Placeholder replacement for status texts.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces {placeholders} in email and document texts.
 */
final class Placeholders {

	/**
	 * Placeholder tag => description, for help texts in the admin.
	 *
	 * @return array<string, string>
	 */
	public static function tags(): array {
		return array(
			'{order_number}'  => __( 'Order number', 'order-flow-for-woocommerce' ),
			'{status}'        => __( 'Status label', 'order-flow-for-woocommerce' ),
			'{customer_name}' => __( 'Customer billing name', 'order-flow-for-woocommerce' ),
			'{note}'          => __( 'Comment entered with the status change', 'order-flow-for-woocommerce' ),
			'{order_date}'    => __( 'Date the order was placed', 'order-flow-for-woocommerce' ),
			'{site_title}'    => __( 'Site title', 'order-flow-for-woocommerce' ),
			'{order_total}'   => __( 'Order total', 'order-flow-for-woocommerce' ),
		);
	}

	/**
	 * Replace the placeholders in a text.
	 *
	 * @param string   $text         Text with placeholders.
	 * @param WC_Order $order        Order.
	 * @param string   $status_label Label of the status the text belongs to.
	 * @param string   $note         Comment entered with the status change.
	 * @param bool     $html         True when the result goes into HTML: values are escaped.
	 */
	public static function replace( string $text, WC_Order $order, string $status_label, string $note = '', bool $html = false ): string {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$date = $order->get_date_created();

		$total = $order->get_formatted_order_total();
		$total = html_entity_decode( wp_strip_all_tags( $total ), ENT_QUOTES, 'UTF-8' );

		$values = array(
			'{order_number}'  => $order->get_order_number(),
			'{status}'        => $status_label,
			'{customer_name}' => $name,
			'{note}'          => $note,
			'{order_date}'    => null === $date ? '' : wc_format_datetime( $date ),
			'{site_title}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{order_total}'   => $total,
		);

		if ( $html ) {
			$values = array_map( 'esc_html', $values );
		}

		return strtr( $text, $values );
	}
}
