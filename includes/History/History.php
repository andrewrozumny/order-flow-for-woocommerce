<?php
/**
 * Read access to the status history of an order.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\History;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and appends entries of the `_ofwc_history` order meta.
 */
final class History {

	public const META_KEY = '_ofwc_history';

	/**
	 * History entries of an order, oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{time: int, user_id: int, from: string, to: string, comment: string}>
	 */
	public static function get( WC_Order $order ): array {
		$raw     = $order->get_meta( self::META_KEY, true );
		$entries = array();
		if ( ! is_array( $raw ) ) {
			return $entries;
		}
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$entries[] = array(
				'time'    => isset( $item['time'] ) && is_numeric( $item['time'] ) ? (int) $item['time'] : 0,
				'user_id' => isset( $item['user_id'] ) && is_numeric( $item['user_id'] ) ? (int) $item['user_id'] : 0,
				'from'    => isset( $item['from'] ) && is_string( $item['from'] ) ? $item['from'] : '',
				'to'      => isset( $item['to'] ) && is_string( $item['to'] ) ? $item['to'] : '',
				'comment' => isset( $item['comment'] ) && is_string( $item['comment'] ) ? $item['comment'] : '',
			);
		}
		return $entries;
	}

	/**
	 * Append an entry and persist the order meta (no full order save, so no status hooks re-fire).
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $from    Previous status slug without prefix.
	 * @param string   $to      New status slug without prefix.
	 * @param int      $user_id Acting user, 0 for the system.
	 * @param string   $comment Comment, may be empty.
	 * @return array{time: int, user_id: int, from: string, to: string, comment: string} The stored entry.
	 */
	public static function append( WC_Order $order, string $from, string $to, int $user_id, string $comment ): array {
		$entry     = array(
			'time'    => time(),
			'user_id' => $user_id,
			'from'    => $from,
			'to'      => $to,
			'comment' => $comment,
		);
		$entries   = self::get( $order );
		$entries[] = $entry;
		$order->update_meta_data( self::META_KEY, $entries );
		$order->save_meta_data();
		return $entry;
	}
}
