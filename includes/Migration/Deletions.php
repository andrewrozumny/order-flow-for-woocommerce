<?php
/**
 * Deleting a status that still holds orders: the orders move in batches, the status goes last.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Statuses\Slug;
use OrderFlowWC\Value;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The status is marked "deleting" and stays registered while its orders move to the target status, a time
 * budget of about twenty seconds per request, the rest in the background (Action Scheduler). Customers are not
 * emailed unless the person asked for it, and no stock, sales or coupon counter changes. The status is removed
 * after the last batch. If an order cannot be moved the deletion is cancelled and the status stays.
 *
 * @phpstan-type Pending array{target: string, notify: bool, moved: int, failed: int[]}
 * @phpstan-type Outcome array{done: bool, moved: int, remaining: int, error: WP_Error|null}
 */
final class Deletions {

	public const OPTION = 'ofwc_status_deletions';
	public const HOOK   = 'ofwc_delete_status_batch';
	public const BATCH  = 50;
	public const BUDGET = 18.0;

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Clock in seconds, replaceable in tests.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 * @param callable|null   $clock      Returns the current time in seconds as float.
	 */
	public function __construct( ?Repository $repository = null, ?callable $clock = null ) {
		$this->repository = $repository ?? new Repository();
		$this->clock      = $clock;
	}

	/**
	 * Register the background job.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_job' ) );
	}

	/**
	 * Deletions in progress, by slug.
	 *
	 * @return array<string, Pending>
	 */
	public function pending(): array {
		$stored = get_option( self::OPTION, array() );
		$list   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $slug => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$failed = array();
			foreach ( is_array( $row['failed'] ?? null ) ? $row['failed'] : array() as $id ) {
				$failed[] = Value::int( $id );
			}
			$list[ (string) $slug ] = array(
				'target' => Value::string( $row['target'] ?? '' ),
				'notify' => (bool) ( $row['notify'] ?? false ),
				'moved'  => Value::int( $row['moved'] ?? 0 ),
				'failed' => $failed,
			);
		}
		return $list;
	}

	/**
	 * Whether a status is being deleted.
	 *
	 * @param string $slug Status slug.
	 */
	public function is_deleting( string $slug ): bool {
		return isset( $this->pending()[ $slug ] );
	}

	/**
	 * Mark a status as being deleted.
	 *
	 * @param string $slug   Status slug.
	 * @param string $target Target status slug, no wc- prefix.
	 * @param bool   $notify Whether customers get the emails of the target status.
	 */
	public function start( string $slug, string $target, bool $notify ): void {
		$all          = $this->pending();
		$all[ $slug ] = array(
			'target' => $target,
			'notify' => $notify,
			'moved'  => 0,
			'failed' => array(),
		);
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Move orders until the budget is used up; remove the status after the last one.
	 *
	 * @param string     $slug   Status slug.
	 * @param float|null $budget Seconds to work; null for the default.
	 * @return Outcome
	 */
	public function run( string $slug, ?float $budget = null ): array {
		$pending = $this->pending();
		if ( ! isset( $pending[ $slug ] ) ) {
			return $this->outcome( false, 0, 0, new WP_Error( 'ofwc_status_missing', __( 'This status is not being deleted.', 'order-flow-for-woocommerce' ) ) );
		}
		$state = $pending[ $slug ];
		$work  = $this->move_orders( $slug, $state, $this->now() + ( $budget ?? $this->budget() ) );

		$state['moved'] += $work['moved'];
		$state['failed'] = $work['failed'];
		$remaining       = $this->repository->count_orders( $slug );

		if ( $work['out_of_time'] && $remaining > count( $work['failed'] ) ) {
			$this->save_state( $slug, $state );
			return $this->outcome( false, $work['moved'], $remaining, null );
		}
		$this->clear( $slug );
		if ( $remaining > 0 ) {
			return $this->outcome( false, $work['moved'], $remaining, new WP_Error( 'ofwc_move_failed', __( 'Some orders could not be moved to the target status. The status was not deleted.', 'order-flow-for-woocommerce' ) ) );
		}
		$this->repository->remove( $slug );
		return $this->outcome( true, $work['moved'], 0, null );
	}

	/**
	 * Move the orders of a status to the target until none are left or the time is up.
	 *
	 * @param string               $slug     Status slug.
	 * @param array<string, mixed> $state    Deletion in progress.
	 * @phpstan-param Pending $state
	 * @param float                $deadline Time to stop at, in seconds on the clock.
	 * @return array{moved: int, failed: int[], out_of_time: bool}
	 */
	private function move_orders( string $slug, array $state, float $deadline ): array {
		$moved       = 0;
		$failed      = $state['failed'];
		$out_of_time = false;

		Isolation::run(
			function () use ( $slug, $state, $deadline, &$moved, &$failed, &$out_of_time ): void {
				while ( true ) {
					$ids = $this->ids( Slug::prefixed( $slug ), $failed );
					if ( array() === $ids ) {
						return;
					}
					foreach ( $ids as $order_id ) {
						if ( $this->expired( $deadline ) ) {
							$out_of_time = true;
							return;
						}
						if ( $this->move_order( $order_id, $state['target'] ) ) {
							++$moved;
						} else {
							$failed[] = $order_id;
						}
						Memory::relax();
					}
				}
			},
			array( $state['target'] ),
			! $state['notify']
		);

		return array(
			'moved'       => $moved,
			'failed'      => $failed,
			'out_of_time' => $out_of_time,
		);
	}

	/**
	 * The result of one run.
	 *
	 * @param bool          $done      Whether the status is gone.
	 * @param int           $moved     Orders moved in this run.
	 * @param int           $remaining Orders still in the status.
	 * @param WP_Error|null $error     Reason the deletion stopped, if it did.
	 * @return Outcome
	 */
	private function outcome( bool $done, int $moved, int $remaining, ?WP_Error $error ): array {
		return array(
			'done'      => $done,
			'moved'     => $moved,
			'remaining' => $remaining,
			'error'     => $error,
		);
	}

	/**
	 * Queue the next batch in the background.
	 *
	 * @param string $slug Status slug.
	 */
	public function schedule( string $slug ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) > 0 ) {
			as_enqueue_async_action( self::HOOK, array( $slug ), Orders::GROUP );
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $slug ) );
	}

	/**
	 * Background job: one batch, then the next one is queued while work is left.
	 *
	 * @param mixed $slug Status slug.
	 */
	public function run_job( $slug = '' ): void {
		if ( ! is_string( $slug ) || '' === $slug || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		// The next batch is queued before the work starts: a request that dies half way still leaves one behind.
		if ( $this->is_deleting( $slug ) ) {
			$this->schedule( $slug );
		}
		$this->run( $slug );
	}

	/**
	 * Move one order to the target status.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $target   Target status slug, no wc- prefix.
	 */
	private function move_order( int $order_id, string $target ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$order->update_status( $target, __( 'Order Flow: the previous status was deleted.', 'order-flow-for-woocommerce' ) );
		$order->save();
		$reloaded = wc_get_order( $order_id );
		return $reloaded instanceof \WC_Order && $target === $reloaded->get_status();
	}

	/**
	 * IDs of orders in a status, leaving out the orders in $skip (orders that could not be moved sort first
	 * in the ascending ID order, so pages are read until one holds an order that is not skipped).
	 *
	 * @param string $status Status with the wc- prefix.
	 * @param int[]  $skip   Orders to leave out.
	 * @return int[]
	 */
	private function ids( string $status, array $skip ): array {
		$offset = 0;
		do {
			$found = wc_get_orders(
				array(
					'status'  => $status,
					'limit'   => self::BATCH,
					'offset'  => $offset,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$page  = array();
			foreach ( is_array( $found ) ? $found : array() as $item ) {
				$id = Value::order_id( $item );
				if ( null !== $id ) {
					$page[] = $id;
				}
			}
			$ids = array_values( array_diff( $page, $skip ) );
			if ( array() !== $ids ) {
				return $ids;
			}
			$offset += self::BATCH;
			$more    = count( $page ) === self::BATCH;
		} while ( $more );
		return array();
	}

	/**
	 * Store the state of one deletion.
	 *
	 * @param string               $slug  Status slug.
	 * @param array<string, mixed> $state State.
	 * @phpstan-param Pending $state
	 */
	private function save_state( string $slug, array $state ): void {
		$all          = $this->pending();
		$all[ $slug ] = $state;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Forget a deletion.
	 *
	 * @param string $slug Status slug.
	 */
	private function clear( string $slug ): void {
		$all = $this->pending();
		unset( $all[ $slug ] );
		if ( array() === $all ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether the time budget is used up.
	 *
	 * @param float $deadline Deadline in seconds.
	 * @phpstan-impure
	 */
	private function expired( float $deadline ): bool {
		return Decisions::out_of_time( $this->now(), $deadline ) || Memory::low();
	}

	/**
	 * Seconds one request may work, 18 by default.
	 */
	private function budget(): float {
		/**
		 * Filters how many seconds a single request spends on a batch of bulk order work (parking, restoring,
		 * moving the orders of a deleted status). Lower it on hosts with a short request limit.
		 *
		 * @param mixed $seconds Seconds.
		 */
		$seconds = apply_filters( 'ofwc_batch_budget', self::BUDGET );
		return Value::float( $seconds, self::BUDGET );
	}

	/**
	 * Current time in seconds.
	 *
	 * @phpstan-impure
	 */
	private function now(): float {
		return null === $this->clock ? microtime( true ) : Value::float( call_user_func( $this->clock ) );
	}
}
