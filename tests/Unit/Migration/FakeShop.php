<?php
/**
 * In-memory orders for the migration tests.
 *
 * @package OrderFlowWC
 */

namespace OrderFlowWC\Tests\Unit\Migration;

/**
 * Order storage shared by the double.
 */
final class FakeShop {

	/**
	 * Orders: id => status, meta, notes, fail (cannot change status).
	 *
	 * @var array<int, array{status: string, meta: array<string, mixed>, notes: string[], fail: bool, mails: int}>
	 */
	public static array $orders = array();

	/**
	 * Statuses WooCommerce knows.
	 *
	 * @var array<string, string>
	 */
	public static array $statuses = array(
		'wc-pending'   => 'Pending payment',
		'wc-on-hold'   => 'On hold',
		'wc-completed' => 'Completed',
		'wc-cancelled' => 'Cancelled',
		'wc-rv-await'  => 'Awaiting approval',
		'wc-rv-prod'   => 'In production',
	);

	/**
	 * Status changes seen, in order: id => status.
	 *
	 * @var array<int, string>
	 */
	public static array $log = array();

	/**
	 * Reset to an empty shop.
	 */
	public static function reset(): void {
		self::$orders = array();
		self::$log    = array();
	}

	/**
	 * Add orders in a status.
	 *
	 * @param int                  $count  Number of orders.
	 * @param string               $status Status without the wc- prefix.
	 * @param array<string, mixed> $meta Meta.
	 * @return int[] IDs.
	 */
	public static function add( int $count, string $status, array $meta = array() ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id                  = count( self::$orders ) + 1;
			self::$orders[ $id ] = array(
				'status' => $status,
				'meta'   => $meta,
				'notes'  => array(),
				'fail'   => false,
				'mails'  => 0,
			);
			$ids[]               = $id;
		}
		return $ids;
	}

	/**
	 * Query like wc_get_orders() with return=ids for the arguments the plugin uses.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return mixed
	 */
	public static function query( array $args ) {
		$status = isset( $args['status'] ) && is_string( $args['status'] ) ? $args['status'] : 'any';
		$offset = isset( $args['offset'] ) && is_int( $args['offset'] ) ? $args['offset'] : 0;
		$meta   = null;
		if ( isset( $args['meta_query'][0]['key'] ) ) {
			$meta = $args['meta_query'][0]['key'];
		}
		$ids = array();
		foreach ( self::$orders as $id => $order ) {
			if ( 'any' !== $status && 'wc-' . $order['status'] !== $status ) {
				continue;
			}
			if ( null !== $meta && ! isset( $order['meta'][ $meta ] ) ) {
				continue;
			}
			$ids[] = $id;
		}
		if ( ! empty( $args['paginate'] ) ) {
			return (object) array( 'total' => count( $ids ) );
		}
		return array_slice( $ids, $offset, isset( $args['limit'] ) && is_int( $args['limit'] ) ? $args['limit'] : null );
	}
}
