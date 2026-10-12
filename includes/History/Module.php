<?php
/**
 * Status history recording.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\History;

use OrderFlowWC\Statuses\Repository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every status change in the order meta (the history is a product feature, and PDF documents read it),
 * and as a private order note when the change involves a custom status or carries a comment. WooCommerce already
 * notes a change between its own statuses. Entries into custom statuses are announced with the `ofwc_status_entered`
 * action for emails and documents.
 */
final class Module {

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Guard against re-entrance while recording.
	 *
	 * @var bool
	 */
	private bool $recording = false;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'record' ), 10, 4 );
	}

	/**
	 * Record one status change.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $from     Previous status without prefix.
	 * @param mixed $to       New status without prefix.
	 * @param mixed $order    Order object.
	 */
	public function record( $order_id, $from, $to, $order = null ): void {
		if ( $this->recording || ! is_string( $from ) || ! is_string( $to ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : null;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->recording = true;
		try {
			$comment = trim( Context::comment() );
			$user_id = Context::user_id();
			History::append( $order, $from, $to, $user_id, $comment );

			$custom_to = null !== $this->repository->get( $to );
			if ( $custom_to || '' !== $comment || null !== $this->repository->get( $from ) ) {
				$order->add_order_note( $this->note_text( $from, $to, $user_id, $comment ), 0, false );
			}

			if ( $custom_to ) {
				/**
				 * Fires after an order entered a custom Order Flow status and the change was recorded.
				 *
				 * @param WC_Order $order   The order.
				 * @param string   $to      New status slug without prefix.
				 * @param string   $from    Previous status slug without prefix.
				 * @param string   $comment Comment entered for the change, may be empty.
				 */
				do_action( 'ofwc_status_entered', $order, $to, $from, $comment );
			}
		} finally {
			$this->recording = false;
		}
	}

	/**
	 * Text of the private order note.
	 *
	 * @param string $from    Previous status slug.
	 * @param string $to      New status slug.
	 * @param int    $user_id Acting user.
	 * @param string $comment Comment.
	 */
	private function note_text( string $from, string $to, int $user_id, string $comment ): string {
		$text = sprintf(
			/* translators: 1: previous status, 2: new status, 3: user name. */
			__( 'Order Flow: %1$s to %2$s by %3$s.', 'order-flow-for-woocommerce' ),
			wc_get_order_status_name( $from ),
			wc_get_order_status_name( $to ),
			self::user_name( $user_id )
		);
		if ( '' !== $comment ) {
			/* translators: %s: comment text. */
			$text .= ' ' . sprintf( __( 'Comment: %s', 'order-flow-for-woocommerce' ), $comment );
		}
		return $text;
	}

	/**
	 * Display name of a user, or "system" for 0 and unknown ids.
	 *
	 * @param int $user_id User id.
	 */
	public static function user_name( int $user_id ): string {
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( false !== $user && '' !== $user->display_name ) {
				return $user->display_name;
			}
		}
		return __( 'system', 'order-flow-for-woocommerce' );
	}
}
