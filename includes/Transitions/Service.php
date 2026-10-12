<?php
/**
 * Status transitions with permission and comment checks.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\History\Context;
use WC_Order;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that changes an order status on behalf of a person.
 */
final class Service {

	/**
	 * Transition rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Constructor.
	 *
	 * @param Rules|null $rules Transition rules.
	 */
	public function __construct( ?Rules $rules = null ) {
		$this->rules = $rules ?? new Rules();
	}

	/**
	 * Move an order to a new status.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $to      Target status slug, with or without the `wc-` prefix.
	 * @param string   $comment Comment, required by some transitions.
	 * @param int|null $user_id Acting user; null means the current user.
	 * @return true|WP_Error
	 */
	public function transition( WC_Order $order, string $to, string $comment = '', ?int $user_id = null ) {
		$to      = 0 === strpos( $to, 'wc-' ) ? substr( $to, 3 ) : $to;
		$user_id = $user_id ?? get_current_user_id();
		$comment = trim( $comment );
		$from    = $order->get_status();

		$error = $this->check( $from, $to, $comment, $user_id );
		if ( null !== $error ) {
			return $error;
		}

		Context::set( $comment, $user_id );
		try {
			$order->update_status( $to );
		} finally {
			Context::clear();
		}
		return true;
	}

	/**
	 * Validate a transition without performing it.
	 *
	 * @param string $from    Current status slug.
	 * @param string $to      Target status slug.
	 * @param string $comment Trimmed comment.
	 * @param int    $user_id Acting user.
	 */
	public function check( string $from, string $to, string $comment, int $user_id ): ?WP_Error {
		if ( ! array_key_exists( 'wc-' . $to, wc_get_order_statuses() ) ) {
			return new WP_Error( 'ofwc_unknown_status', __( 'The target status does not exist.', 'order-flow-for-woocommerce' ) );
		}
		if ( $from === $to ) {
			return new WP_Error( 'ofwc_same_status', __( 'The order already has this status.', 'order-flow-for-woocommerce' ) );
		}
		if ( ! $this->rules->is_allowed( $from, $to, $user_id ) ) {
			return new WP_Error( 'ofwc_not_allowed', __( 'You are not allowed to make this status change.', 'order-flow-for-woocommerce' ) );
		}
		if ( '' === trim( $comment ) && $this->rules->requires_comment( $from, $to ) ) {
			return new WP_Error( 'ofwc_comment_required', __( 'This status change needs a comment.', 'order-flow-for-woocommerce' ) );
		}
		return null;
	}
}
