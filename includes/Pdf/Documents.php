<?php
/**
 * Which documents an order has, who may download them, and the download URL.
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
 * A document exists for every custom status with PDF enabled that the order is in now
 * or has been in (read from the `_ofwc_history` order meta).
 */
final class Documents {

	public const ACTION = 'ofwc_pdf';

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Statuses (config arrays keyed by slug, in status order) whose document is available for the order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, array<string, mixed>>
	 */
	public function available( WC_Order $order ): array {
		$seen = array( $this->normalize( $order->get_status() ) => true );

		$history = $order->get_meta( '_ofwc_history' );
		if ( is_array( $history ) ) {
			foreach ( $history as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				foreach ( array( 'from', 'to' ) as $key ) {
					if ( isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) ) {
						$seen[ $this->normalize( $entry[ $key ] ) ] = true;
					}
				}
			}
		}

		$result = array();
		foreach ( $this->repository->all() as $slug => $status ) {
			if ( ! empty( $status['pdf_enabled'] ) && isset( $seen[ $slug ] ) ) {
				$result[ $slug ] = $status;
			}
		}
		return $result;
	}

	/**
	 * Slugs only, see available().
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	public function slugs( WC_Order $order ): array {
		return array_map( 'strval', array_keys( $this->available( $order ) ) );
	}

	/**
	 * Permission rule for a download.
	 *
	 * @param bool $can_manage       User may manage orders (edit_shop_orders or manage_woocommerce).
	 * @param int  $order_customer   Customer id of the order (0 for guests).
	 * @param int  $user_id          Current user id (0 when logged out).
	 * @param bool $show_in_account  The status is visible to customers.
	 */
	public static function may_download( bool $can_manage, int $order_customer, int $user_id, bool $show_in_account ): bool {
		if ( $can_manage ) {
			return true;
		}
		return $user_id > 0 && $order_customer === $user_id && $show_in_account;
	}

	/**
	 * Signed download URL.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $slug  Status slug.
	 */
	public static function url( WC_Order $order, string $slug ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'order_id' => $order->get_id(),
					'status'   => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $order->get_id(), $slug )
		);
	}

	/**
	 * Nonce action string.
	 *
	 * @param int    $order_id Order id.
	 * @param string $slug     Status slug.
	 */
	public static function nonce_action( int $order_id, string $slug ): string {
		return 'ofwc_pdf_' . $order_id . '_' . $slug;
	}

	/**
	 * Strip the wc- prefix.
	 *
	 * @param string $status Status key.
	 */
	private function normalize( string $status ): string {
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}
}
