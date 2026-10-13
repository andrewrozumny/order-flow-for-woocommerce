<?php
/**
 * Moves orders out of custom statuses on deactivation and back on activation.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Statuses\Slug;
use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HPOS safe: only wc_get_orders() and the order CRUD are used.
 *
 * Every move runs in batches against a time budget, so one request never works longer than about
 * twenty seconds and an interrupted run simply continues. While an order is moved nothing but the
 * status changes: no emails, no stock changes, no sales or coupon counters, no Order Flow hooks.
 *
 * @phpstan-type ParkResult array{moved: int, failed: int, remaining: int}
 * @phpstan-type RestoreResult array{restored: int, released: int, skip: int[], done: bool}
 */
final class Orders {

	public const META         = '_ofwc_pre_deactivation_status';
	public const PENDING_FLAG = 'ofwc_restore_pending';
	public const PROGRESS     = 'ofwc_park_progress';
	public const RESTORE_HOOK = 'ofwc_restore_batch';
	public const GROUP        = 'order-flow-for-woocommerce';
	public const BATCH        = 50;
	public const BUDGET       = 18.0;
	public const SKIP_LIMIT   = 5000;

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
	 * Whether the restore batch of this request ran to its end.
	 *
	 * @var bool
	 */
	private bool $batch_finished = true;

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
	 * Order count per custom status that has at least one order.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array();
		foreach ( array_column( $this->repository->all(), 'slug' ) as $slug ) {
			$count = $this->repository->count_orders( $slug );
			if ( $count > 0 ) {
				$counts[ $slug ] = $count;
			}
		}
		return $counts;
	}

	/**
	 * Start a parking run: forget earlier progress and remember how many orders there are.
	 */
	public function begin_parking(): void {
		set_transient(
			self::PROGRESS,
			array(
				'total'  => Decisions::total( $this->counts() ),
				'moved'  => 0,
				'failed' => array(),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Progress of the current parking run.
	 *
	 * @return array{total: int, moved: int, failed: int[]}
	 */
	public function parking_progress(): array {
		$state  = get_transient( self::PROGRESS );
		$result = array(
			'total'  => 0,
			'moved'  => 0,
			'failed' => array(),
		);
		if ( is_array( $state ) ) {
			$result['total'] = Value::int( $state['total'] ?? 0 );
			$result['moved'] = Value::int( $state['moved'] ?? 0 );
			$failed          = $state['failed'] ?? array();
			foreach ( is_array( $failed ) ? $failed : array() as $id ) {
				$result['failed'][] = Value::int( $id );
			}
		}
		return $result;
	}

	/**
	 * Move orders in custom statuses to On hold, remembering where each came from, until the budget is used up.
	 *
	 * @param float|null $budget Seconds to work; null for the default.
	 * @return ParkResult
	 */
	public function park_batch( ?float $budget = null ): array {
		$state    = $this->parking_progress();
		$deadline = $this->now() + ( $budget ?? $this->budget() );
		$moved    = 0;
		$failed   = $state['failed'];

		Isolation::run(
			function () use ( &$moved, &$failed, $deadline ): void {
				foreach ( array_column( $this->repository->all(), 'slug' ) as $slug ) {
					while ( ! $this->expired( $deadline ) ) {
						$ids = $this->ids(
							array( 'status' => Slug::prefixed( $slug ) ),
							$failed
						);
						if ( array() === $ids ) {
							break;
						}
						foreach ( $ids as $order_id ) {
							if ( $this->expired( $deadline ) ) {
								return;
							}
							if ( $this->park_order( $order_id, $slug ) ) {
								++$moved;
							} else {
								$failed[] = $order_id;
							}
							Memory::relax();
						}
					}
				}
			}
		);

		$state['moved'] += $moved;
		$state['failed'] = $failed;
		set_transient( self::PROGRESS, $state, HOUR_IN_SECONDS );

		return array(
			'moved'     => $moved,
			'failed'    => count( $failed ),
			'remaining' => max( 0, Decisions::total( $this->counts() ) - count( $failed ) ),
		);
	}

	/**
	 * Move every order in a custom status to On hold in one go (tests, scripts).
	 *
	 * @return int Number of orders moved.
	 */
	public function move_to_on_hold(): int {
		$this->begin_parking();
		do {
			$result = $this->park_batch( INF );
		} while ( $result['remaining'] > 0 && $result['moved'] > 0 );
		$state = $this->parking_progress();
		delete_transient( self::PROGRESS );
		return $state['moved'];
	}

	/**
	 * Put orders back into the status they were parked from, until the budget is used up.
	 *
	 * Only an order that is still in On hold goes back. Any other order changed status while the plugin was
	 * inactive: it keeps its status, loses the marker and gets a note.
	 *
	 * @param int[]      $skip   Orders to leave out (parked orders whose status no longer exists).
	 * @param float|null $budget Seconds to work; null for the default.
	 * @return RestoreResult
	 */
	public function restore_batch( array $skip = array(), ?float $budget = null ): array {
		$restored = 0;
		$released = 0;
		$done     = false;
		$known    = array_column( $this->repository->all(), 'slug' );
		$deadline = $this->now() + ( $budget ?? $this->budget() );

		Isolation::run(
			function () use ( &$restored, &$released, &$skip, &$done, $known, $deadline ): void {
				while ( ! $this->expired( $deadline ) ) {
					$ids = $this->ids(
						array(
							'status'       => 'any',
							// Not `meta_query`: the legacy posts store drops it and would return every order.
							'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Background restore after activation.
							'meta_compare' => 'EXISTS',
						),
						$skip
					);
					if ( array() === $ids ) {
						$done = true;
						return;
					}
					foreach ( $ids as $order_id ) {
						if ( $this->expired( $deadline ) ) {
							return;
						}
						$outcome = $this->restore_order( $order_id, $known );
						Memory::relax();
						if ( Decisions::RESTORE === $outcome ) {
							++$restored;
						} elseif ( Decisions::RELEASE === $outcome ) {
							++$released;
						} else {
							$skip[] = $order_id;
						}
					}
				}
			}
		);

		return array(
			'restored' => $restored,
			'released' => $released,
			'skip'     => array_slice( $skip, 0, self::SKIP_LIMIT ),
			'done'     => $done,
		);
	}

	/**
	 * Restore everything in one go (tests, scripts).
	 *
	 * @return int Number of orders restored.
	 */
	public function restore(): int {
		$total = 0;
		$skip  = array();
		do {
			$result = $this->restore_batch( $skip, INF );
			$total += $result['restored'];
			$skip   = $result['skip'];
		} while ( ! $result['done'] );
		return $total;
	}

	/**
	 * Queue the restore after activation. No order is touched here.
	 *
	 * When Action Scheduler is not ready yet (WooCommerce was not loaded during activation) a flag is stored and
	 * the next admin request queues the job.
	 */
	public function restore_or_defer(): void {
		if ( $this->queue_ready() ) {
			$this->enqueue_once();
			delete_option( self::PENDING_FLAG );
			return;
		}
		update_option( self::PENDING_FLAG, '1', false );
	}

	/**
	 * Queue a deferred restore on admin_init.
	 */
	public function maybe_run_pending(): void {
		if ( '1' !== get_option( self::PENDING_FLAG ) || ! $this->queue_ready() ) {
			return;
		}
		$this->enqueue_once();
		delete_option( self::PENDING_FLAG );
	}

	/**
	 * Action Scheduler job: restore one batch, queue the next one when work is left.
	 *
	 * @param mixed $payload Job arguments: array with the optional key "skip" (order IDs).
	 */
	public function run_restore_job( $payload = array() ): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$skip = array();
		if ( is_array( $payload ) && isset( $payload['skip'] ) && is_array( $payload['skip'] ) ) {
			foreach ( $payload['skip'] as $id ) {
				$skip[] = Value::int( $id );
			}
		}
		// If the request dies half way (memory, a host limit), the next batch is still queued, with the same skip list.
		$this->batch_finished = false;
		register_shutdown_function(
			function () use ( $skip ): void {
				if ( ! $this->batch_finished ) {
					$this->enqueue( array( 'skip' => $skip ) );
				}
			}
		);
		$result               = $this->restore_batch( $skip );
		$this->batch_finished = true;
		if ( ! $result['done'] ) {
			$this->enqueue( array( 'skip' => $result['skip'] ) );
		}
	}

	/**
	 * Whether jobs can be queued right now.
	 */
	private function queue_ready(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'wc_get_orders' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Queue the first restore job unless one is already waiting.
	 */
	private function enqueue_once(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::RESTORE_HOOK, null, self::GROUP ) ) {
			return;
		}
		$this->enqueue( array() );
	}

	/**
	 * Queue a restore job.
	 *
	 * @param array<string, mixed> $payload Job arguments.
	 */
	private function enqueue( array $payload ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) > 0 ) {
			as_enqueue_async_action( self::RESTORE_HOOK, array( $payload ), self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RESTORE_HOOK, array( $payload ) );
	}

	/**
	 * Park one order. The marker and the note are saved together with the status change.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $slug     Custom status slug the order is in.
	 */
	private function park_order( int $order_id, string $slug ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
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
		return $reloaded instanceof \WC_Order && 'on-hold' === $reloaded->get_status();
	}

	/**
	 * Restore, release or keep one parked order.
	 *
	 * @param int      $order_id Order ID.
	 * @param string[] $known    Slugs of existing custom statuses.
	 * @return string One of the Decisions outcomes.
	 */
	private function restore_order( int $order_id, array $known ): string {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return Decisions::KEEP_HOLD;
		}
		$stored  = $order->get_meta( self::META );
		$outcome = Decisions::restore_action( $order->get_status(), $stored, $known );

		if ( Decisions::RELEASE === $outcome ) {
			$order->delete_meta_data( self::META );
			$order->add_order_note( __( 'Order Flow: the status changed while the plugin was inactive, not restored.', 'order-flow-for-woocommerce' ) );
			$order->save();
		} elseif ( Decisions::RESTORE === $outcome ) {
			$target = Decisions::restore_target( $stored, $known );
			if ( null === $target ) {
				return Decisions::KEEP_HOLD;
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
		}
		return $outcome;
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

	/**
	 * Order ids matching a query, one batch, leaving out the orders in $skip.
	 *
	 * The orders to skip stay in the result set and sort among the first ones (ascending ID), so pages are read
	 * until one holds an order that is not skipped.
	 *
	 * @param array<string, mixed> $args Extra wc_get_orders() arguments.
	 * @param int[]                $skip Order IDs to leave out.
	 * @return int[]
	 */
	private function ids( array $args, array $skip = array() ): array {
		$offset = 0;
		do {
			$found = wc_get_orders(
				array_merge(
					$args,
					array(
						'limit'   => self::BATCH,
						'offset'  => $offset,
						'return'  => 'ids',
						'orderby' => 'ID',
						'order'   => 'ASC',
					)
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
}
