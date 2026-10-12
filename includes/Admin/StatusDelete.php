<?php
/**
 * Statuses tab: the delete screen.
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
 * Asks where the orders of a status go, says what deleting does, and shows the progress of a deletion that
 * has started.
 */
final class StatusDelete {

	use FormFields;

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
	 * Render the delete confirmation with a target status when orders exist.
	 *
	 * @param string $slug Status slug.
	 */
	public function render( string $slug ): void {
		$status = $this->repository->get( $slug );
		if ( null === $status ) {
			( new StatusList( $this->repository ) )->render();
			return;
		}
		$count    = $this->repository->count_orders( $slug );
		$deleting = $this->repository->is_deleting( $slug );

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %s: status label. */
				__( 'Delete status "%s"', 'order-flow-for-woocommerce' ),
				$status['label']
			)
		) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ofwc-form">';
		wp_nonce_field( StatusesTab::ACTION_DELETE );
		echo '<input type="hidden" name="action" value="' . esc_attr( StatusesTab::ACTION_DELETE ) . '">';
		echo '<input type="hidden" name="status" value="' . esc_attr( $slug ) . '">';

		if ( $deleting ) {
			$this->render_delete_progress( $count );
		} elseif ( $count > 0 ) {
			$this->render_delete_target( $slug, $count );
			$this->render_delete_effects();
		} else {
			echo '<p>' . esc_html__( 'No orders are in this status. Transition rules that mention it will be removed.', 'order-flow-for-woocommerce' ) . '</p>';
		}

		submit_button( $deleting ? __( 'Continue now', 'order-flow-for-woocommerce' ) : __( 'Delete status', 'order-flow-for-woocommerce' ), 'delete' );
		printf( '<a href="%1$s">%2$s</a>', esc_url( Page::url( 'statuses' ) ), esc_html__( 'Cancel', 'order-flow-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * A deletion that has started: how much is left.
	 *
	 * @param int $remaining Orders still in the status.
	 */
	private function render_delete_progress( int $remaining ): void {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of orders still to move. */
				_n( 'This status is being deleted. %s order is left to move. The rest happens in the background and the status disappears after the last order has moved.', 'This status is being deleted. %s orders are left to move. The rest happens in the background and the status disappears after the last order has moved.', $remaining, 'order-flow-for-woocommerce' ),
				number_format_i18n( $remaining )
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'Use the button to move the next batch now.', 'order-flow-for-woocommerce' ) . '</p>';
	}

	/**
	 * The question where the orders of a status should go.
	 *
	 * @param string $slug  Status slug.
	 * @param int    $count Orders in the status.
	 */
	private function render_delete_target( string $slug, int $count ): void {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of orders. */
				_n( '%s order is currently in this status. Choose where to move it.', '%s orders are currently in this status. Choose where to move them.', $count, 'order-flow-for-woocommerce' ),
				number_format_i18n( $count )
			)
		) . '</p>';
		echo '<p><label for="ofwc-target">' . esc_html__( 'Move orders to', 'order-flow-for-woocommerce' ) . '</label> ';
		echo '<select id="ofwc-target" name="target" required><option value="">' . esc_html__( 'Select a status', 'order-flow-for-woocommerce' ) . '</option>';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			if ( Slug::prefixed( $slug ) === $key ) {
				continue;
			}
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select></p>';
	}

	/**
	 * What deleting a status with orders does and does not do, and the choice about customer emails.
	 */
	private function render_delete_effects(): void {
		echo '<h3>' . esc_html__( 'What happens', 'order-flow-for-woocommerce' ) . '</h3><ul class="ofwc-policy-list">';
		foreach (
			array(
				__( 'The orders move in batches. The first batch runs now, the rest in the background, and the status disappears after the last order has moved.', 'order-flow-for-woocommerce' ),
				__( 'Every order gets a note that its previous status was deleted.', 'order-flow-for-woocommerce' ),
				__( 'Stock is not changed, whatever the target status does when an order enters it.', 'order-flow-for-woocommerce' ),
				__( 'Transition rules are not applied to these moves, and the rules that mention this status are removed.', 'order-flow-for-woocommerce' ),
				__( 'No emails are sent unless you tick the box below.', 'order-flow-for-woocommerce' ),
			) as $line
		) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		$this->checkbox(
			'notify_customers',
			__( 'Notify customers', 'order-flow-for-woocommerce' ),
			false,
			__( 'Customers and the shop get the emails of the target status for every order that moves.', 'order-flow-for-woocommerce' )
		);
	}
}
