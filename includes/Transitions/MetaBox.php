<?php
/**
 * The Order Flow box on the order edit screen.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\History\History;
use OrderFlowWC\History\Module as HistoryModule;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current status, transition buttons, comment field and the status history.
 */
final class MetaBox {

	/**
	 * Transition rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Constructor.
	 *
	 * @param Rules $rules Transition rules.
	 */
	public function __construct( Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Render the box.
	 *
	 * @param mixed $order_or_post Order or post being edited.
	 */
	public function render( $order_or_post ): void {
		$order = $order_or_post instanceof WC_Order ? $order_or_post : ( is_object( $order_or_post ) && isset( $order_or_post->ID ) ? wc_get_order( (int) $order_or_post->ID ) : false );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$from = $order->get_status();
		if ( in_array( $from, array( 'auto-draft', 'draft', 'new', 'checkout-draft' ), true ) ) {
			echo '<p class="description">' . esc_html__( 'Save the order first. Status changes and history appear here afterwards.', 'order-flow-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<div class="ofwc-box" data-order="' . esc_attr( (string) $order->get_id() ) . '" data-nonce="' . esc_attr( wp_create_nonce( Module::ACTION . '_' . $order->get_id() ) ) . '">';

		printf(
			'<p class="ofwc-current">%1$s <mark class="order-status status-%2$s"><span>%3$s</span></mark></p>',
			esc_html__( 'Current status:', 'order-flow-for-woocommerce' ),
			esc_attr( sanitize_html_class( $from ) ),
			esc_html( wc_get_order_status_name( $from ) )
		);

		$this->render_actions( $from );
		$this->render_history( $order );

		echo '</div>';
	}

	/**
	 * Buttons and the comment field.
	 *
	 * @param string $from Current status slug.
	 */
	private function render_actions( string $from ): void {
		$targets = $this->rules->allowed_targets( $from, get_current_user_id() );
		if ( array() === $targets ) {
			echo '<p class="description">' . esc_html__( 'You cannot move this order to another status.', 'order-flow-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<div class="ofwc-actions">';
		$needs_comment = false;
		foreach ( $targets as $slug ) {
			$required      = $this->rules->requires_comment( $from, $slug );
			$needs_comment = $needs_comment || $required;
			printf(
				'<button type="button" class="button ofwc-transition" data-to="%1$s" data-comment-required="%2$d">%3$s%4$s</button> ',
				esc_attr( $slug ),
				$required ? 1 : 0,
				esc_html( wc_get_order_status_name( $slug ) ),
				$required ? ' <span class="ofwc-star" aria-hidden="true">*</span><span class="screen-reader-text">' . esc_html__( '(comment required)', 'order-flow-for-woocommerce' ) . '</span>' : ''
			);
		}
		echo '</div>';

		echo '<p class="ofwc-comment-row"><label for="ofwc_comment"><strong>' . esc_html__( 'Comment', 'order-flow-for-woocommerce' ) . '</strong> <span class="ofwc-required" hidden>' . esc_html__( '(required for this change)', 'order-flow-for-woocommerce' ) . '</span></label>';
		echo '<textarea id="ofwc_comment" name="ofwc_comment" rows="3" class="widefat"></textarea></p>';
		if ( $needs_comment ) {
			echo '<p class="description">' . esc_html__( '* This change needs a comment.', 'order-flow-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * History table.
	 *
	 * @param WC_Order $order Order.
	 */
	private function render_history( WC_Order $order ): void {
		$entries = array_reverse( History::get( $order ) );
		echo '<h4 class="ofwc-history-title">' . esc_html__( 'Status history', 'order-flow-for-woocommerce' ) . '</h4>';
		if ( array() === $entries ) {
			echo '<p class="description">' . esc_html__( 'No status changes recorded yet.', 'order-flow-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped ofwc-history"><thead><tr>';
		foreach ( array( __( 'When', 'order-flow-for-woocommerce' ), __( 'Who', 'order-flow-for-woocommerce' ), __( 'From', 'order-flow-for-woocommerce' ), __( 'To', 'order-flow-for-woocommerce' ), __( 'Comment', 'order-flow-for-woocommerce' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ); // Site settings.
		foreach ( $entries as $entry ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) wp_date( $format, $entry['time'] ) ),
				esc_html( HistoryModule::user_name( $entry['user_id'] ) ),
				esc_html( wc_get_order_status_name( $entry['from'] ) ),
				esc_html( wc_get_order_status_name( $entry['to'] ) ),
				esc_html( $entry['comment'] )
			);
		}
		echo '</tbody></table>';
	}
}
