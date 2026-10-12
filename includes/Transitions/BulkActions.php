<?php
/**
 * The "Change status to ..." bulk actions of Order Flow.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Value;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One bulk action per custom status the user can reach from some status. Orders whose move is not allowed, or needs
 * a comment, are skipped and counted.
 */
final class BulkActions {

	public const PREFIX = 'ofwc_to_';

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Transition rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Transition service.
	 *
	 * @var Service
	 */
	private Service $service;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Status storage.
	 * @param Rules      $rules      Transition rules.
	 * @param Service    $service    Transition service.
	 */
	public function __construct( Repository $repository, Rules $rules, Service $service ) {
		$this->repository = $repository;
		$this->rules      = $rules;
		$this->service    = $service;
	}

	/**
	 * Register hooks for the HPOS and the legacy order list.
	 */
	public function register(): void {
		foreach ( array( 'bulk_actions-woocommerce_page_wc-orders', 'bulk_actions-edit-shop_order' ) as $filter ) {
			add_filter( $filter, array( $this, 'add_bulk_actions' ), 10 );
		}
		foreach ( array( 'handle_bulk_actions-woocommerce_page_wc-orders', 'handle_bulk_actions-edit-shop_order' ) as $filter ) {
			add_filter( $filter, array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * Add "Change status to X" for each custom status the user can reach from some status.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return mixed
	 */
	public function add_bulk_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		$user = get_current_user_id();
		foreach ( $this->repository->all() as $status ) {
			if ( $this->rules->can_reach( $status['slug'], $user ) ) {
				$actions[ self::PREFIX . $status['slug'] ] = sprintf(
					/* translators: %s: status name. */
					__( 'Change status to %s', 'order-flow-for-woocommerce' ),
					$status['label']
				);
			}
		}
		return $actions;
	}

	/**
	 * Run one of our bulk actions.
	 *
	 * @param mixed $redirect Redirect URL.
	 * @param mixed $action   Chosen bulk action.
	 * @param mixed $ids      Selected order ids.
	 * @return mixed
	 */
	public function handle_bulk_action( $redirect, $action, $ids ) {
		if ( ! is_string( $action ) || 0 !== strpos( $action, self::PREFIX ) || ! is_array( $ids ) ) {
			return $redirect;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return $redirect;
		}
		$to = substr( $action, strlen( self::PREFIX ) );
		if ( null === $this->repository->get( $to ) ) {
			return $redirect;
		}

		$changed = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( Value::int( $id ) );
			if ( ! $order instanceof WC_Order || true !== $this->service->transition( $order, $to ) ) {
				++$skipped;
				continue;
			}
			++$changed;
		}

		Notices::add(
			$skipped > 0 ? 'warning' : 'success',
			sprintf(
				/* translators: 1: status name, 2: changed orders, 3: skipped orders. */
				__( 'Change status to %1$s: %2$d changed, %3$d skipped (not allowed, already in this status or a comment is required).', 'order-flow-for-woocommerce' ),
				wc_get_order_status_name( $to ),
				$changed,
				$skipped
			)
		);
		return $redirect;
	}
}
