<?php
/**
 * Status transitions with permission and comment checks.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Slug;
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
	 * Lock that lets one change at a time through for an order; null means no locking (checks only, tests).
	 *
	 * @var OrderLock|null
	 */
	private ?OrderLock $lock;

	/**
	 * Constructor.
	 *
	 * @param Rules|null     $rules Transition rules.
	 * @param OrderLock|null $lock  Per-order lock; the module passes the real one.
	 */
	public function __construct( ?Rules $rules = null, ?OrderLock $lock = null ) {
		$this->rules = $rules ?? new Rules();
		$this->lock  = $lock;
	}

	/**
	 * Move an order to a new status.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $to      Target status slug, with or without the `wc-` prefix.
	 * @param string   $comment Comment, required by some transitions.
	 * @param int|null $user_id Acting user; null means the current user.
	 * @param bool     $notify  Whether the comment may appear in the email to the customer.
	 * @return true|WP_Error
	 */
	public function transition( WC_Order $order, string $to, string $comment = '', ?int $user_id = null, bool $notify = false ) {
		$to      = Slug::strip( $to );
		$user_id = $user_id ?? get_current_user_id();
		$comment = Comment::clean( $comment );

		if ( null === $this->lock ) {
			return $this->apply( $order, $to, $comment, $user_id, $notify );
		}
		$token = $this->lock->acquire( $order->get_id() );
		if ( null === $token ) {
			return new WP_Error( 'ofwc_order_busy', __( 'This order is being changed right now. Try again in a moment.', 'order-flow-for-woocommerce' ) );
		}
		try {
			// The status is read again inside the lock: a change that ran while this request waited has moved it.
			return $this->apply( $this->lock->fresh( $order ), $to, $comment, $user_id, $notify );
		} finally {
			$this->lock->release( $order->get_id(), $token );
		}
	}

	/**
	 * Check and perform a change, the lock (if any) is held by the caller.
	 *
	 * @param WC_Order $order   Order, as the database has it.
	 * @param string   $to      Target status slug without prefix.
	 * @param string   $comment Cleaned comment.
	 * @param int      $user_id Acting user.
	 * @param bool     $notify  Whether the comment may appear in the email to the customer.
	 * @return true|WP_Error
	 */
	private function apply( WC_Order $order, string $to, string $comment, int $user_id, bool $notify ) {
		$from  = $order->get_status();
		$error = $this->check( $from, $to, $comment, $user_id );
		if ( null !== $error ) {
			return $error;
		}

		Context::set( $comment, $user_id, $notify );
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
		if ( ! array_key_exists( Slug::prefixed( $to ), wc_get_order_statuses() ) ) {
			return new WP_Error( 'ofwc_unknown_status', __( 'The target status does not exist.', 'order-flow-for-woocommerce' ) );
		}
		if ( $from === $to ) {
			return new WP_Error( 'ofwc_same_status', __( 'The order already has this status.', 'order-flow-for-woocommerce' ) );
		}
		if ( ! $this->rules->is_allowed( $from, $to, $user_id ) ) {
			return new WP_Error( 'ofwc_not_allowed', __( 'You are not allowed to make this status change.', 'order-flow-for-woocommerce' ) );
		}
		if ( Comment::is_blank( $comment ) && $this->rules->requires_comment( $from, $to ) ) {
			return new WP_Error( 'ofwc_comment_required', __( 'This status change needs a comment.', 'order-flow-for-woocommerce' ) );
		}
		return null;
	}
}
