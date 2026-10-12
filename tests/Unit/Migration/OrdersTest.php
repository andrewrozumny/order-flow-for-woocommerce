<?php
/**
 * Parking and restoring orders.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use OrderFlowWC\Migration\Orders;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;

require_once __DIR__ . '/FakeHooks.php';

/**
 * Batches with a time budget, the On hold guard on restore and the absence of side effects.
 *
 * Separate processes: the test defines WooCommerce functions and a WC_Order double.
 *
 * @covers \OrderFlowWC\Migration\Orders
 * @covers \OrderFlowWC\Migration\Isolation
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OrdersTest extends TestCase {

	use FakeHooks;

	/**
	 * Options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Fake time in seconds.
	 *
	 * @var float
	 */
	private float $time = 0.0;

	/**
	 * Calls of the stock handler WooCommerce attaches to On hold.
	 *
	 * @var int
	 */
	public static int $stock_calls = 0;

	/**
	 * Stub WordPress storage and start with an empty shop and two custom statuses.
	 */
	protected function set_up(): void {
		parent::set_up();
		// Declares global WooCommerce functions: only safe in these separate-process tests.
		require_once __DIR__ . '/FakeWooCommerce.php';
		FakeShop::reset();
		self::$stock_calls = 0;
		$this->time        = 0.0;
		$this->options     = array(
			'ofwc_statuses' => array(
				'rv-await' => array(
					'slug'  => 'rv-await',
					'label' => 'Awaiting approval',
				),
				'rv-prod'  => array(
					'slug'  => 'rv-prod',
					'label' => 'In production',
				),
			),
		);
		$this->transients  = array();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( string $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_roles' )->justReturn( (object) array() );
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		Functions\when( 'number_format_i18n' )->returnArg();
		$this->install_hooks();
	}

	/**
	 * Migration with a clock that moves 10 ms every time it is read.
	 */
	private function orders(): Orders {
		return new Orders(
			new Repository(),
			function (): float {
				$this->time += 0.01;
				return $this->time;
			}
		);
	}

	/**
	 * Order statuses by ID.
	 *
	 * @return array<int, string>
	 */
	private function statuses(): array {
		return array_map(
			static function ( array $order ): string {
				return $order['status'];
			},
			FakeShop::$orders
		);
	}

	/**
	 * 1 000 orders are parked in several batches, none is left in an unknown state in between, and a
	 * later run continues where the earlier one stopped.
	 */
	public function test_thousand_orders_are_parked_in_several_batches(): void {
		FakeShop::add( 600, 'rv-await' );
		FakeShop::add( 400, 'rv-prod' );
		$orders = $this->orders();
		$orders->begin_parking();

		$runs      = 0;
		$total     = 0;
		$remaining = 1000;
		while ( $remaining > 0 ) {
			$result    = $orders->park_batch( 3.0 );
			$remaining = $result['remaining'];
			$total    += $result['moved'];
			++$runs;
			$this->assertLessThan( 20, $runs, 'the run does not make progress' );
			// An interrupted run leaves every order either in its custom status or fully parked.
			foreach ( FakeShop::$orders as $order ) {
				if ( 'on-hold' === $order['status'] ) {
					$this->assertContains( $order['meta'][ Orders::META ], array( 'rv-await', 'rv-prod' ) );
				} else {
					$this->assertContains( $order['status'], array( 'rv-await', 'rv-prod' ) );
					$this->assertArrayNotHasKey( Orders::META, $order['meta'] );
				}
			}
		}

		$this->assertGreaterThan( 1, $runs, 'one request must not do all the work' );
		$this->assertSame( 1000, $total );
		$this->assertSame( array( 'on-hold' ), array_values( array_unique( $this->statuses() ) ) );
		$this->assertSame( 'rv-prod', FakeShop::$orders[1000]['meta'][ Orders::META ] );
		$this->assertCount( 1, FakeShop::$orders[1]['notes'] );
		$this->assertSame( 1000, $orders->parking_progress()['moved'] );
	}

	/**
	 * An order that refuses to change status is skipped for good and does not stop the run.
	 */
	public function test_failed_order_does_not_block_the_run(): void {
		$ids                                 = FakeShop::add( 5, 'rv-await' );
		FakeShop::$orders[ $ids[1] ]['fail'] = true;
		$orders                              = $this->orders();
		$orders->begin_parking();

		$result = $orders->park_batch( 5.0 );

		$this->assertSame( 4, $result['moved'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertSame( 'rv-await', FakeShop::$orders[ $ids[1] ]['status'] );
	}

	/**
	 * More failing orders than fit on one page do not hide the orders behind them.
	 */
	public function test_many_failures_do_not_hide_later_orders(): void {
		$ids = FakeShop::add( 130, 'rv-await' );
		foreach ( array_slice( $ids, 0, 110 ) as $id ) {
			FakeShop::$orders[ $id ]['fail'] = true;
		}
		$orders = $this->orders();
		$orders->begin_parking();

		$runs = 0;
		do {
			$result = $orders->park_batch( 30.0 );
			++$runs;
		} while ( $result['remaining'] > 0 && $result['moved'] > 0 && $runs < 10 );

		$this->assertSame( 20, $orders->parking_progress()['moved'] );
		$this->assertSame( 110, $result['failed'] );
		$this->assertSame( 0, $result['remaining'] );
	}

	/**
	 * The review scenario: an order that was completed while the plugin was inactive is not pulled back.
	 */
	public function test_restore_leaves_orders_whose_status_changed(): void {
		$ids = FakeShop::add( 1, 'completed', array( Orders::META => 'rv-await' ) );

		$restored = $this->orders()->restore();

		$this->assertSame( 0, $restored );
		$this->assertSame( 'completed', FakeShop::$orders[ $ids[0] ]['status'] );
		$this->assertArrayNotHasKey( Orders::META, FakeShop::$orders[ $ids[0] ]['meta'] );
		$this->assertStringContainsString( 'not restored', FakeShop::$orders[ $ids[0] ]['notes'][0] );
	}

	/**
	 * Parked orders return, a parked order of a vanished status stays in On hold with its marker.
	 */
	public function test_restore_returns_parked_orders_only(): void {
		$parked = FakeShop::add( 2, 'on-hold', array( Orders::META => 'rv-prod' ) );
		$gone   = FakeShop::add( 1, 'on-hold', array( Orders::META => 'rv-removed' ) );
		$moved  = FakeShop::add( 1, 'cancelled', array( Orders::META => 'rv-prod' ) );

		$restored = $this->orders()->restore();

		$this->assertSame( 2, $restored );
		$this->assertSame( 'rv-prod', FakeShop::$orders[ $parked[0] ]['status'] );
		$this->assertSame( 'rv-prod', FakeShop::$orders[ $parked[1] ]['status'] );
		$this->assertSame( 'on-hold', FakeShop::$orders[ $gone[0] ]['status'] );
		$this->assertSame( 'rv-removed', FakeShop::$orders[ $gone[0] ]['meta'][ Orders::META ] );
		$this->assertSame( 'cancelled', FakeShop::$orders[ $moved[0] ]['status'] );
	}

	/**
	 * 1 000 parked orders come back over several jobs; the skip list carries the orders left in On hold.
	 */
	public function test_restore_runs_in_batches_and_continues(): void {
		FakeShop::add( 995, 'on-hold', array( Orders::META => 'rv-await' ) );
		FakeShop::add( 5, 'on-hold', array( Orders::META => 'rv-removed' ) );
		$orders = $this->orders();

		$skip     = array();
		$restored = 0;
		$jobs     = 0;
		do {
			$result    = $orders->restore_batch( $skip, 3.0 );
			$skip      = $result['skip'];
			$restored += $result['restored'];
			++$jobs;
			$this->assertLessThan( 20, $jobs );
		} while ( ! $result['done'] );

		$this->assertGreaterThan( 1, $jobs );
		$this->assertSame( 995, $restored );
		$this->assertCount( 5, $skip );
		$counts = array_count_values( $this->statuses() );
		$this->assertSame( 995, $counts['rv-await'] );
		$this->assertSame( 5, $counts['on-hold'] );
	}

	/**
	 * Parking and restoring leave stock handlers and mail alone, and put every hook back.
	 */
	public function test_moves_have_no_side_effects(): void {
		Functions\when( 'wc_release_stock_for_order' )->alias(
			static function (): void {
				++self::$stock_calls;
			}
		);
		add_action( 'woocommerce_order_status_on-hold', 'wc_release_stock_for_order', 11 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core hook name.
		FakeShop::add( 3, 'rv-await' );
		$orders = $this->orders();
		$orders->begin_parking();

		$orders->park_batch( 5.0 );
		$orders->restore();

		$this->assertSame( 0, self::$stock_calls, 'stock handler ran during the move' );
		$this->assertSame( 11, has_action( 'woocommerce_order_status_on-hold', 'wc_release_stock_for_order' ), 'handler not put back' );
		$this->assertFalse( has_filter( 'pre_wp_mail', '__return_false' ) );
		$this->assertFalse( has_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false' ) );
		foreach ( FakeShop::$orders as $order ) {
			$this->assertSame( 0, $order['mails'], 'a mail would have been sent' );
		}

		// A normal status change afterwards still reaches the handler.
		do_action( 'woocommerce_order_status_on-hold', 1 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core hook name.
		$this->assertSame( 1, self::$stock_calls );
	}

	/**
	 * Activation only queues the restore; without Action Scheduler a flag waits for the next admin request.
	 */
	public function test_activation_queues_and_never_touches_orders(): void {
		FakeShop::add( 3, 'on-hold', array( Orders::META => 'rv-prod' ) );

		$this->orders()->restore_or_defer();
		$this->assertSame( '1', $this->options[ Orders::PENDING_FLAG ] );
		$this->assertSame( array( 'on-hold' ), array_values( array_unique( $this->statuses() ) ), 'activation moved orders' );

		$queued = array();
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$queued ): int {
				$queued[] = array( $hook, $args, $group );
				return 7;
			}
		);
		$this->orders()->maybe_run_pending();

		$this->assertSame( array( array( Orders::RESTORE_HOOK, array( array() ), Orders::GROUP ) ), $queued );
		$this->assertArrayNotHasKey( Orders::PENDING_FLAG, $this->options );
		$this->assertSame( array( 'on-hold' ), array_values( array_unique( $this->statuses() ) ) );
	}

	/**
	 * The background job restores one batch and queues itself again while work is left.
	 */
	public function test_restore_job_requeues_itself(): void {
		FakeShop::add( 400, 'on-hold', array( Orders::META => 'rv-await' ) );
		$queued = array();
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args ) use ( &$queued ): int {
				$queued[] = $args;
				return 1;
			}
		);
		$clock = function (): float {
			$this->time += 0.05;
			return $this->time;
		};

		( new Orders( new Repository(), $clock ) )->run_restore_job( array() );

		$this->assertCount( 1, $queued, 'the job should ask for a next batch' );
		$counts = array_count_values( $this->statuses() );
		$this->assertGreaterThan( 0, $counts['rv-await'] );
		$this->assertGreaterThan( 0, $counts['on-hold'] );
	}
}
