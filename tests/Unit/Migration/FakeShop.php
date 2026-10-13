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
	 * Queries seen since reset(). A loop that never ends stops here instead of running for twenty minutes.
	 *
	 * @var int
	 */
	public static int $queries = 0;

	public const MAX_QUERIES = 20000;

	/**
	 * Reset to an empty shop.
	 */
	public static function reset(): void {
		self::$orders  = array();
		self::$log     = array();
		self::$queries = 0;
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
	 * @throws \LogicException For `meta_query` (not supported by the legacy posts store) and for a loop without end.
	 */
	public static function query( array $args ) {
		if ( ++self::$queries > self::MAX_QUERIES ) {
			throw new \LogicException( 'more than ' . self::MAX_QUERIES . ' order queries: the code under test loops without end' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
		}
		$status = isset( $args['status'] ) && is_string( $args['status'] ) ? $args['status'] : 'any';
		$offset = isset( $args['offset'] ) && is_int( $args['offset'] ) ? $args['offset'] : 0;
		$meta   = null;
		// The legacy posts store of WooCommerce drops `meta_query` and raises a "called incorrectly" notice, so a
		// query that uses it must fail here. `meta_key` with `meta_compare` works in both stores.
		if ( isset( $args['meta_query'] ) ) {
			throw new \LogicException( 'meta_query is not supported by the legacy posts order store' );
		}
		if ( isset( $args['meta_key'] ) && is_string( $args['meta_key'] ) ) {
			$meta = $args['meta_key'];
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
