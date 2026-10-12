<?php
/**
 * Order Flow status email (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/ofwc-status.php.
 *
 * @package OrderFlowWC\Templates\Emails\Plain
 * @version 0.1.0
 *
 * @var WC_Order $order         Order.
 * @var string   $email_heading Heading.
 * @var string   $body          Body as plain text.
 * @var bool     $sent_to_admin Whether the email goes to the shop.
 * @var bool     $plain_text    Plain text mode.
 * @var WC_Email $email         Email object.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- These are WooCommerce core email hooks.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html( $body ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
