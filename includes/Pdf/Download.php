<?php
/**
 * PDF download endpoint (admin-post.php?action=ofwc_pdf).
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

use OrderFlowWC\Statuses\Repository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logged-in users only. Admins and shop managers get any available document; customers only
 * their own orders and only for statuses shown to customers.
 */
final class Download {

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Documents helper.
	 *
	 * @var Documents
	 */
	private Documents $documents;

	/**
	 * PDF renderer.
	 *
	 * @var Generator
	 */
	private Generator $generator;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
		$this->documents  = new Documents( $this->repository );
		$this->generator  = new Generator( $this->repository );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . Documents::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Check the request and stream the PDF.
	 */
	public function handle(): void {
		$order_id = isset( $_GET['order_id'] ) && is_string( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified right below.
		$slug     = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified right below.

		check_admin_referer( Documents::nonce_action( $order_id, $slug ) );

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'order-flow-for-woocommerce' ), '', array( 'response' => 404 ) );
		}
		$status = $this->repository->get( $slug );
		if ( null === $status || ! isset( $this->documents->available( $order )[ $slug ] ) ) {
			wp_die( esc_html__( 'This document is not available for the order.', 'order-flow-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		$can_manage = current_user_can( 'edit_shop_orders' ) || // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			current_user_can( 'manage_woocommerce' );
		if ( ! Documents::may_download( $can_manage, $order->get_customer_id(), get_current_user_id(), $status['show_in_account'] ) ) {
			wp_die( esc_html__( 'You are not allowed to download this document.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$pdf = $this->generator->render_pdf( $order, $slug );
		if ( '' === $pdf ) {
			wp_die( esc_html__( 'The PDF could not be created.', 'order-flow-for-woocommerce' ), '', array( 'response' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $this->generator->filename( $order, $status ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
		exit;
	}
}
