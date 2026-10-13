<?php
/**
 * Download buttons: order edit screen box and My Account.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

use Automattic\WooCommerce\Utilities\OrderUtil;
use OrderFlowWC\Statuses\Repository;
use WC_Order;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Surfaces the available documents to staff and customers.
 *
 * @phpstan-import-type Status from Repository
 */
final class Buttons {

	/**
	 * Documents helper.
	 *
	 * @var Documents
	 */
	private Documents $documents;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->documents = new Documents( $repository );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_account_section' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_order_actions' ), 10, 2 );
	}

	/**
	 * Add the box on both the HPOS and the legacy order screens.
	 *
	 * @param string $screen_id Screen or post type being edited.
	 * @param mixed  $unused    Order or post (unused).
	 */
	public function add_meta_box( $screen_id, $unused = null ): void {
		unset( $unused );
		if ( ! class_exists( OrderUtil::class ) || OrderUtil::get_order_admin_screen() !== $screen_id ) {
			return;
		}
		add_meta_box(
			'ofwc_documents',
			__( 'Order Flow documents', 'order-flow-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			OrderUtil::get_order_admin_screen(),
			'side',
			'default'
		);
	}

	/**
	 * Box content.
	 *
	 * @param WC_Order|WP_Post $post_or_order Order object (HPOS) or post (legacy).
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$available = $this->documents->available( $order );
		if ( array() === $available ) {
			echo '<p class="description">' . esc_html__( 'No documents yet. They appear when the order enters a status with PDF documents enabled.', 'order-flow-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<p>';
		foreach ( $available as $status ) {
			printf(
				'<a href="%1$s" class="button ofwc-doc-button">%2$s</a>',
				esc_url( Documents::url( $order, $status['slug'] ) ),
				/* translators: %s: status label. */
				esc_html( sprintf( __( 'Download: %s', 'order-flow-for-woocommerce' ), $status['label'] ) )
			);
		}
		echo '</p>';
	}

	/**
	 * Documents section on the My Account view-order page.
	 *
	 * @param mixed $order Order.
	 */
	public function render_account_section( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$links = $this->customer_documents( $order );
		if ( array() === $links ) {
			return;
		}
		echo '<section class="woocommerce-order-documents"><h2>' . esc_html__( 'Documents', 'order-flow-for-woocommerce' ) . '</h2><ul>';
		foreach ( $links as $link ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( $link['url'] ),
				esc_html( $link['name'] )
			);
		}
		echo '</ul></section>';
	}

	/**
	 * Add one action per document on the My Account orders list.
	 *
	 * @param mixed $actions Actions.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public function add_order_actions( $actions, $order ) {
		if ( ! is_array( $actions ) || ! $order instanceof WC_Order ) {
			return $actions;
		}
		// The view-order page lists the documents in its own section, so do not repeat them as actions there.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) {
			return $actions;
		}
		foreach ( $this->customer_documents( $order ) as $slug => $link ) {
			$actions[ 'ofwc_pdf_' . $slug ] = array(
				'url'  => $link['url'],
				'name' => $link['name'],
			);
		}
		return $actions;
	}

	/**
	 * Documents a customer may see: only statuses shown in the account.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, array{url: string, name: string}>
	 */
	private function customer_documents( WC_Order $order ): array {
		$links = array();
		foreach ( $this->documents->available( $order ) as $status ) {
			if ( ! $status['show_in_account'] ) {
				continue;
			}
			$links[ $status['slug'] ] = array(
				'url'  => Documents::url( $order, $status['slug'] ),
				/* translators: %s: document or status name. */
				'name' => sprintf( __( 'Download %s', 'order-flow-for-woocommerce' ), $status['label'] ),
			);
		}
		return $links;
	}
}
