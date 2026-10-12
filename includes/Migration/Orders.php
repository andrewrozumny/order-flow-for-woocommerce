<?php
/**
 * Moves orders out of custom statuses on deactivation and back on activation.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Statuses\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HPOS safe: only wc_get_orders() and the order CRUD are used.
 *
 * Status emails (Order Flow's and WooCommerce's "on hold" email) are suppressed during the
 * migration, because the customer did not cause these changes.
 */
final class Orders {

	public const META         = '_ofwc_pre_deactivation_status';
	public const PENDING_FLAG = 'ofwc_restore_pending';
	public const BATCH        = 50;

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
	 * Order count per custom status that has at least one order.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array();
		foreach ( array_keys( $this->repository->all() ) as $slug ) {
			$count = $this->repository->count_orders( $slug );
			if ( $count > 0 ) {
				$counts[ $slug ] = $count;
			}
		}
		return $counts;
	}

	/**
	 * Move every order in a custom status to On hold, remembering where it came from.
	 *
	 * @return int Number of orders moved.
	 */
	public function move_to_on_hold(): int {
		$moved = 0;
		$this->quiet(
			function () use ( &$moved ): void {
				foreach ( array_keys( $this->repository->all() ) as $slug ) {
					$failed = array();
					do {
						$ids = $this->ids(
							array(
								'status' => 'wc-' . $slug,
								'offset' => count( $failed ),
							)
						);
						foreach ( $ids as $order_id ) {
							$order = wc_get_order( $order_id );
							if ( ! $order instanceof \WC_Order ) {
								$failed[] = $order_id;
								continue;
							}
							$order->update_meta_data( self::META, $slug );
							$order->add_order_note(
								sprintf(
									/* translators: %s: status slug. */
									__( 'Order Flow: moved to On hold because the plugin is being deactivated. It will return to "%s" when the plugin is activated again.', 'order-flow-for-woocommerce' ),
									$slug
								)
							);
							$order->update_status( 'on-hold' );
							$order->save();
							$reloaded = wc_get_order( $order_id );
							if ( $reloaded instanceof \WC_Order && 'on-hold' === $reloaded->get_status() ) {
								++$moved;
							} else {
								$failed[] = $order_id;
							}
						}
					} while ( array() !== $ids );
				}
			}
		);
		return $moved;
	}

	/**
	 * Put orders back into the status they were moved from, when it still exists.
	 *
	 * @return int Number of orders restored.
	 */
	public function restore(): int {
		$restored = 0;
		$known    = array_keys( $this->repository->all() );
		$this->quiet(
			function () use ( &$restored, $known ): void {
				$skipped = array();
				do {
					$ids = $this->ids(
						array(
							'status'     => 'any',
							'offset'     => count( $skipped ),
							'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Rare one-off restore on activation.
								array(
									'key'     => self::META,
									'compare' => 'EXISTS',
								),
							),
						)
					);
					foreach ( $ids as $order_id ) {
						$order = wc_get_order( $order_id );
						if ( ! $order instanceof \WC_Order ) {
							$skipped[] = $order_id;
							continue;
						}
						$target = Decisions::restore_target( $order->get_meta( self::META ), $known );
						if ( null === $target ) {
							$skipped[] = $order_id;
							continue;
						}
						$order->delete_meta_data( self::META );
						$order->add_order_note(
							sprintf(
								/* translators: %s: status slug. */
								__( 'Order Flow: restored to "%s" after the plugin was activated again.', 'order-flow-for-woocommerce' ),
								$target
							)
						);
						$order->update_status( $target );
						$order->save();
						++$restored;
					}
				} while ( array() !== $ids );
			}
		);
		return $restored;
	}

	/**
	 * Restore now, or schedule it when WooCommerce is not available yet.
	 */
	public function restore_or_defer(): void {
		if ( function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order_statuses' ) ) {
			$this->restore();
			delete_option( self::PENDING_FLAG );
			return;
		}
		update_option( self::PENDING_FLAG, '1', false );
	}

	/**
	 * Run a deferred restore on admin_init.
	 */
	public function maybe_run_pending(): void {
		if ( '1' !== get_option( self::PENDING_FLAG ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return;
		}
		$this->restore();
		delete_option( self::PENDING_FLAG );
	}

	/**
	 * Order ids matching a query, one batch.
	 *
	 * @param array<string, mixed> $args Extra wc_get_orders() arguments.
	 * @return int[]
	 */
	private function ids( array $args ): array {
		// Batches run in ascending id order. Orders that could not be handled stay in the result set and
		// always sort before the unprocessed ones, so an offset equal to their count skips exactly them.
		$found = wc_get_orders(
			array_merge(
				$args,
				array(
					'limit'   => self::BATCH,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			)
		);
		$ids   = array();
		foreach ( is_array( $found ) ? $found : array() as $item ) {
			if ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			} elseif ( $item instanceof \WC_Order ) {
				$ids[] = $item->get_id();
			}
		}
		return $ids;
	}

	/**
	 * Run a callback with status emails switched off.
	 *
	 * @param callable $callback Work to do.
	 */
	private function quiet( callable $callback ): void {
		global $wp_filter;
		$saved = isset( $wp_filter['ofwc_status_entered'] ) ? $wp_filter['ofwc_status_entered'] : null;
		unset( $wp_filter['ofwc_status_entered'] );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false', 999 );
		add_filter( 'woocommerce_email_enabled_new_order', '__return_false', 999 );
		try {
			$callback();
		} finally {
			remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false', 999 );
			remove_filter( 'woocommerce_email_enabled_new_order', '__return_false', 999 );
			if ( null !== $saved ) {
				$wp_filter['ofwc_status_entered'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the hooks removed above.
			}
		}
	}
}
