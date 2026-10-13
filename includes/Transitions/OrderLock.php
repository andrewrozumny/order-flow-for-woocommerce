<?php
/**
 * One status change at a time for an order.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use Automattic\WooCommerce\Utilities\OrderUtil;
use OrderFlowWC\Statuses\Slug;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A change reads the status, checks the rules and saves. Two requests doing that for one order at the same time
 * both pass the check and both send their emails. The lock is a row of the options table that only one request can
 * insert (`INSERT IGNORE` is atomic), with an expiry so that a request that died cannot block the order for long.
 * The row is never loaded into the options cache: it is written and deleted by direct queries only.
 */
class OrderLock {

	public const PREFIX = 'ofwc_lock_';
	public const TTL    = 30;
	public const WAIT   = 5.0;
	public const STEP   = 100000;

	/**
	 * Seconds to wait for a change that is still running.
	 *
	 * @var float
	 */
	private float $wait;

	/**
	 * Constructor.
	 *
	 * @param float $wait Seconds to wait for the lock before giving up.
	 */
	public function __construct( float $wait = self::WAIT ) {
		$this->wait = $wait;
	}

	/**
	 * Take the lock, waiting a few seconds for a change that is still running.
	 *
	 * @param int $order_id Order id.
	 * @return string|null Token to release the lock with, null when another change kept the order busy.
	 */
	public function acquire( int $order_id ): ?string {
		$deadline = microtime( true ) + $this->wait;
		do {
			$token = $this->try_once( $order_id );
			if ( null !== $token ) {
				return $token;
			}
			usleep( self::STEP );
		} while ( microtime( true ) < $deadline );
		return null;
	}

	/**
	 * Give the lock back. Only the holder's own token releases it.
	 *
	 * @param int    $order_id Order id.
	 * @param string $token    Token from acquire().
	 */
	public function release( int $order_id, string $token ): void {
		/**
		 * Database access.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock row is never cached.
		$wpdb->query( (string) $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::PREFIX . $order_id, $token ) );
	}

	/**
	 * The order as the database has it now. An object that was loaded before the lock may be out of date: when its
	 * status differs from the stored one, the caches are dropped and the order is loaded again.
	 *
	 * @param WC_Order $order Order loaded earlier.
	 */
	public function fresh( WC_Order $order ): WC_Order {
		$id     = $order->get_id();
		$stored = $this->stored_status( $id );
		if ( null === $stored || Slug::strip( $stored ) === $order->get_status() ) {
			return $order;
		}
		// The copy in this request's object cache is the stale one (another request saved the order meanwhile); a
		// persistent cache was emptied by that request's save.
		clean_post_cache( $id );
		wp_cache_flush_runtime();
		$reloaded = wc_get_order( $id );
		return $reloaded instanceof WC_Order ? $reloaded : $order;
	}

	/**
	 * One attempt: insert the row, or take over a row whose time is up.
	 *
	 * @param int $order_id Order id.
	 * @return string|null Token, null when the order is locked.
	 */
	private function try_once( int $order_id ): ?string {
		/**
		 * Database access.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$name  = self::PREFIX . $order_id;
		$token = (string) (int) ( ( microtime( true ) + self::TTL ) * 1000 );

		$insert = (string) $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, $name, $token );
		if ( 1 === $this->affected( $insert ) ) {
			return $token;
		}
		// The row of a request that died is taken over once its time is up.
		$this->affected( (string) $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $name, (int) ( microtime( true ) * 1000 ) ) );
		return 1 === $this->affected( $insert ) ? $token : null;
	}

	/**
	 * Run a statement and report the number of rows it changed.
	 *
	 * @phpstan-impure
	 *
	 * @param string $sql Prepared statement.
	 */
	private function affected( string $sql ): int {
		/**
		 * Database access.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- A lock row is never cached; the statement comes from prepare() at the call site.
		$rows = $wpdb->query( $sql );
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Status stored in the database for an order, with its `wc-` prefix.
	 *
	 * @param int $order_id Order id.
	 */
	private function stored_status( int $order_id ): ?string {
		/**
		 * Database access.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The point is to bypass the caches.
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$value = $wpdb->get_var( (string) $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $wpdb->prefix . 'wc_orders', $order_id ) );
		} else {
			$value = $wpdb->get_var( (string) $wpdb->prepare( 'SELECT post_status FROM %i WHERE ID = %d', $wpdb->posts, $order_id ) );
		}
		// phpcs:enable
		return is_string( $value ) ? $value : null;
	}
}
