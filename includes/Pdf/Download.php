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

		$this->answer_clearly_when_the_server_gives_up();
		$pdf = $this->generator->render_pdf( $order, $slug );
		if ( '' === $pdf ) {
			$this->refuse( $this->generator->failure() );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $this->generator->filename( $order, $status ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
		exit;
	}

	/**
	 * Explain why no PDF was made and stop.
	 *
	 * @param string $failure One of the Generator::FAILURE_ constants.
	 */
	private function refuse( string $failure ): void {
		wp_die( esc_html( Generator::explain( $failure ) ), '', array( 'response' => Generator::FAILURE_RENDER === $failure ? 500 : 503 ) );
	}

	/**
	 * A document that still exhausts memory or time ends in a fatal error, which PHP cannot be asked to survive. WordPress
	 * then shows its "critical error" page; this makes that page say what happened. Where the fatal error handler of
	 * WordPress is switched off, a shutdown function prints the same sentence.
	 */
	private function answer_clearly_when_the_server_gives_up(): void {
		$message = Generator::explain( Generator::FAILURE_SIZE );
		add_filter(
			'wp_php_error_message',
			static function () use ( $message ): string {
				return '<p>' . esc_html( $message ) . '</p>';
			}
		);
		add_filter(
			'wp_php_error_args',
			static function ( $args ): array {
				return array_merge( is_array( $args ) ? $args : array(), array( 'response' => 503 ) );
			}
		);
		if ( function_exists( 'wp_is_fatal_error_handler_enabled' ) && wp_is_fatal_error_handler_enabled() ) {
			return;
		}
		register_shutdown_function(
			static function () use ( $message ): void {
				$error = error_get_last();
				if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}
				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
				if ( ! headers_sent() ) {
					http_response_code( 503 );
					header( 'Content-Type: text/plain; charset=utf-8' );
				}
				echo esc_html( $message );
			}
		);
	}
}
