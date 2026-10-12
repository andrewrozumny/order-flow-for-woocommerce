<?php
/**
 * Order Flow status email (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/ofwc-status.php.
 *
 * @package OrderFlowWC\Templates\Emails
 * @version 0.1.0
 *
 * @var WC_Order $order         Order.
 * @var string   $email_heading Heading.
 * @var string   $body          Body HTML (already escaped and filtered).
 * @var bool     $sent_to_admin Whether the email goes to the shop.
 * @var bool     $plain_text    Plain text mode.
 * @var WC_Email $email         Email object.
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- These are WooCommerce core email hooks.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Output the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email );

echo wp_kses_post( $body );

/*
 * Order details table.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * Order meta.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * Customer details and addresses.
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * Output the email footer.
 */
do_action( 'woocommerce_email_footer', $email );
