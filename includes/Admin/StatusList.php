<?php
/**
 * Statuses tab: the list of statuses.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Statuses\Slug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The table of custom statuses with their flags and order counts.
 *
 * @phpstan-import-type Status from Repository
 */
final class StatusList {

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Status storage.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Render the list.
	 */
	public function render(): void {
		$statuses = $this->repository->all();

		echo '<p>' . esc_html__( 'Custom order statuses appear next to the WooCommerce ones in the order list, the order screen and reports.', 'order-flow-for-woocommerce' ) . '</p>';
		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a></p>',
			esc_url( Page::url( 'statuses', array( 'view' => 'add' ) ) ),
			esc_html__( 'Add status', 'order-flow-for-woocommerce' )
		);

		if ( array() === $statuses ) {
			echo '<div class="ofwc-empty"><p>' . esc_html__( 'No custom statuses yet. Add your first one to start building your order flow.', 'order-flow-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped ofwc-table"><thead><tr>';
		printf(
			'<th scope="col" class="column-primary">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col" class="column-orders">%4$s</th></tr></thead><tbody>',
			esc_html__( 'Status', 'order-flow-for-woocommerce' ),
			esc_html__( 'Slug', 'order-flow-for-woocommerce' ),
			esc_html__( 'Settings', 'order-flow-for-woocommerce' ),
			esc_html__( 'Orders', 'order-flow-for-woocommerce' )
		);

		foreach ( $statuses as $status ) {
			$this->render_row( $status );
		}
		echo '</tbody></table>';
	}

	/**
	 * One row of the table.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 */
	private function render_row( array $status ): void {
		$slug   = $status['slug'];
		$edit   = Page::url(
			'statuses',
			array(
				'view'   => 'edit',
				'status' => $slug,
			)
		);
		$delete = Page::url(
			'statuses',
			array(
				'view'   => 'delete',
				'status' => $slug,
			)
		);
		$count  = $this->repository->count_orders( $slug );

		echo '<tr><td class="column-primary">';
		printf(
			'<span class="ofwc-swatch %1$s"></span><span class="dashicons %2$s ofwc-icon"></span><strong><a href="%3$s">%4$s</a></strong>',
			esc_attr( Page::swatch_class( $slug ) ),
			esc_attr( $status['icon'] ),
			esc_url( $edit ),
			esc_html( $status['label'] )
		);
		printf(
			'<div class="row-actions visible"><span class="edit"><a href="%1$s">%2$s</a> | </span><span class="delete"><a href="%3$s" class="submitdelete">%4$s</a></span></div>',
			esc_url( $edit ),
			esc_html__( 'Edit', 'order-flow-for-woocommerce' ),
			esc_url( $delete ),
			esc_html__( 'Delete', 'order-flow-for-woocommerce' )
		);
		echo '</td>';
		echo '<td><code>' . esc_html( Slug::prefixed( $slug ) ) . '</code></td>';
		echo '<td>';
		if ( $this->repository->is_deleting( $slug ) ) {
			echo '<span class="ofwc-flag ofwc-flag-deleting">' . esc_html__( 'Deleting', 'order-flow-for-woocommerce' ) . '</span> ';
		}
		foreach ( $this->flags( $status ) as $flag ) {
			echo '<span class="ofwc-flag">' . esc_html( $flag ) . '</span> ';
		}
		echo '</td>';
		echo '<td class="column-orders">' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
	}

	/**
	 * Short labels for the enabled flags of a status.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 * @return string[]
	 */
	private function flags( array $status ): array {
		$map = array(
			'paid'            => __( 'Paid', 'order-flow-for-woocommerce' ),
			'show_in_account' => __( 'Visible to customer', 'order-flow-for-woocommerce' ),
			'reduce_stock'    => __( 'Reduces stock', 'order-flow-for-woocommerce' ),
			'email_customer'  => __( 'Customer email', 'order-flow-for-woocommerce' ),
			'email_admin'     => __( 'Admin email', 'order-flow-for-woocommerce' ),
			'pdf_enabled'     => __( 'PDF', 'order-flow-for-woocommerce' ),
		);
		$out = array();
		foreach ( $map as $key => $label ) {
			if ( $status[ $key ] ) {
				$out[] = $label;
			}
		}
		return $out;
	}
}
