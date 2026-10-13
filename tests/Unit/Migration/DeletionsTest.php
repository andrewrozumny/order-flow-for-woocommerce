<?php
/**
 * Deleting a status that holds orders.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use OrderFlowWC\Migration\Deletions;
use OrderFlowWC\Migration\Memory;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;
use WP_Error;

require_once __DIR__ . '/FakeHooks.php';

/**
 * The status stays until its last order has moved; moving sends no mail and touches no stock.
 *
 * Separate processes: the test defines WooCommerce functions and a WC_Order double.
 *
 * @covers \OrderFlowWC\Migration\Deletions
 * @covers \OrderFlowWC\Statuses\Repository
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DeletionsTest extends TestCase {

	use FakeHooks;

	/**
	 * Options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Fake time in seconds.
	 *
	 * @var float
	 */
	private float $time = 0.0;

	/**
	 * Calls of the stock handlers WooCommerce attaches to Cancelled.
	 *
	 * @var int
	 */
	public static int $stock_calls = 0;

	/**
	 * Stub storage; one custom status exists.
	 */
	protected function set_up(): void {
		parent::set_up();
		// Declares global WooCommerce functions: only safe in these separate-process tests.
		require_once __DIR__ . '/FakeWooCommerce.php';
		FakeShop::reset();
		self::$stock_calls = 0;
		$this->time        = 0.0;
		$this->options     = array(
			'ofwc_statuses'    => array(
				'rv-await' => array(
					'slug'  => 'rv-await',
					'label' => 'Awaiting approval',
				),
			),
			'ofwc_transitions' => array(
				array(
					'from' => 'rv-await',
					'to'   => 'completed',
				),
			),
		);
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
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( '_n' )->alias(
			static function ( string $single, string $plural, int $number ): string {
				return 1 === $number ? $single : $plural;
			}
		);
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		$this->install_hooks();
		Repository::flush_cache();
	}

	/**
	 * Repository and runner on the fake clock.
	 *
	 * @return array{0: Repository, 1: Deletions}
	 */
	private function pair(): array {
		$repo = new Repository();
		$del  = new Deletions(
			$repo,
			function (): float {
				$this->time += 0.01;
				return $this->time;
			}
		);
		return array( $repo, $del );
	}

	/**
	 * 1 000 orders move in several batches; the status is still there until the last one is done.
	 */
	public function test_status_is_removed_after_the_last_batch(): void {
		FakeShop::add( 1000, 'rv-await' );
		list( , $del ) = $this->pair();
		$del->start( 'rv-await', 'completed', false );

		$runs = 0;
		do {
			$result = $del->run( 'rv-await', 3.0 );
			++$runs;
			$this->assertLessThan( 20, $runs );
			if ( ! $result['done'] ) {
				$this->assertNull( $result['error'] );
				$this->assertTrue( $del->is_deleting( 'rv-await' ) );
				$this->assertArrayHasKey( 'rv-await', $this->options['ofwc_statuses'], 'the status went too early' );
				// An interrupted run leaves every order in the old or in the new status, nothing else.
				foreach ( FakeShop::$orders as $order ) {
					$this->assertContains( $order['status'], array( 'rv-await', 'completed' ) );
				}
			}
		} while ( ! $result['done'] );

		$this->assertGreaterThan( 1, $runs );
		$this->assertSame( array( 'completed' ), array_values( array_unique( array_column( FakeShop::$orders, 'status' ) ) ) );
		$this->assertArrayNotHasKey( 'rv-await', $this->options['ofwc_statuses'] );
		$this->assertSame( array(), $this->options['ofwc_transitions'], 'rules that mention the status go with it' );
		$this->assertFalse( $del->is_deleting( 'rv-await' ) );
		$this->assertSame( 'Order Flow: the previous status was deleted.', FakeShop::$orders[1]['notes'][0] );
	}

	/**
	 * A batch stops when the memory is used up, leaves the status in place and every order in the old or the new status.
	 */
	public function test_batch_stops_when_memory_is_low(): void {
		FakeShop::add( 40, 'rv-await' );
		list( , $del ) = $this->pair();
		$del->start( 'rv-await', 'completed', false );
		$calls         = 0;
		Memory::$probe = static function () use ( &$calls ): array {
			++$calls;
			return array( $calls > 10 ? 70 : 10, 100 ); // Over 60 percent after ten looks.
		};
		try {
			$result = $del->run( 'rv-await', 1000.0 );
		} finally {
			Memory::$probe = null;
		}

		$this->assertFalse( $result['done'] );
		$this->assertNull( $result['error'] );
		$this->assertGreaterThan( 0, $result['moved'] );
		$this->assertLessThan( 40, $result['moved'] );
		$this->assertTrue( $del->is_deleting( 'rv-await' ) );
	}

	/**
	 * Deleting a status with orders queues the next batch before it moves the first order.
	 */
	public function test_next_batch_is_queued_before_the_work(): void {
		FakeShop::add( 5, 'rv-await' );
		$order_of_events = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$order_of_events ): bool {
				$order_of_events[] = 'queued:' . count( array_filter( FakeShop::$orders, static fn( $o ) => 'completed' === $o['status'] ) );
				return true;
			}
		);
		$repo = new Repository();

		$repo->delete( 'rv-await', 'completed' );

		$this->assertSame( array( 'queued:0' ), $order_of_events );
	}

	/**
	 * Customers get no email and stock does not move, even for a target like Cancelled.
	 */
	public function test_no_mail_and_no_stock_changes(): void {
		Functions\when( 'wc_maybe_increase_stock_levels' )->alias(
			static function (): void {
				++self::$stock_calls;
			}
		);
		add_action( 'woocommerce_order_status_cancelled', 'wc_maybe_increase_stock_levels' );
		FakeShop::add( 3, 'rv-await' );
		list( , $del ) = $this->pair();
		$del->start( 'rv-await', 'cancelled', false );

		$result = $del->run( 'rv-await', 5.0 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, self::$stock_calls );
		foreach ( FakeShop::$orders as $order ) {
			$this->assertSame( 0, $order['mails'] );
		}
		$this->assertSame( 10, has_action( 'woocommerce_order_status_cancelled', 'wc_maybe_increase_stock_levels' ) );
	}

	/**
	 * With "notify customers" ticked, mail is not suppressed.
	 */
	public function test_notify_keeps_mail_enabled(): void {
		FakeShop::add( 2, 'rv-await' );
		list( , $del ) = $this->pair();
		$del->start( 'rv-await', 'completed', true );

		$del->run( 'rv-await', 5.0 );

		$this->assertSame( 1, FakeShop::$orders[1]['mails'] );
	}

	/**
	 * An order that cannot move cancels the deletion: the status stays and the mark is cleared.
	 */
	public function test_failure_keeps_the_status(): void {
		$ids                                 = FakeShop::add( 3, 'rv-await' );
		FakeShop::$orders[ $ids[2] ]['fail'] = true;
		list( , $del )                       = $this->pair();
		$del->start( 'rv-await', 'completed', false );

		$result = $del->run( 'rv-await', 5.0 );

		$this->assertFalse( $result['done'] );
		$this->assertInstanceOf( WP_Error::class, $result['error'] );
		$this->assertSame( 'ofwc_move_failed', $result['error']->get_error_code() );
		$this->assertArrayHasKey( 'rv-await', $this->options['ofwc_statuses'] );
		$this->assertFalse( $del->is_deleting( 'rv-await' ) );
	}

	/**
	 * Repository::delete() with many orders reports that the work continues and queues the next batch; the
	 * background job finishes it. Without orders the status goes at once.
	 */
	public function test_repository_delete_with_orders_continues_in_background(): void {
		FakeShop::add( 400, 'rv-await' );
		$queued = array();
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args ) use ( &$queued ): int {
				$queued[] = array( $hook, $args );
				return 1;
			}
		);
		$time    = 0.0;
		$no_time = static function (): float {
			return 0.0;
		};
		add_filter( 'ofwc_batch_budget', $no_time );

		$result = ( new Repository() )->delete( 'rv-await', 'completed' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_delete_pending', $result->get_error_code() );
		$this->assertSame( array( array( Deletions::HOOK, array( 'rv-await' ) ) ), $queued );
		$this->assertArrayHasKey( 'rv-await', $this->options['ofwc_statuses'] );
		$this->assertTrue( ( new Repository() )->is_deleting( 'rv-await' ) );

		// The background job picks up the remaining orders until the status is gone.
		remove_filter( 'ofwc_batch_budget', $no_time );
		$del  = new Deletions(
			new Repository(),
			static function () use ( &$time ): float {
				$time += 0.2;
				return $time;
			}
		);
		$jobs = 0;
		while ( $del->is_deleting( 'rv-await' ) && $jobs < 30 ) {
			$del->run_job( 'rv-await' );
			++$jobs;
		}
		$this->assertGreaterThan( 0, $jobs );
		$this->assertArrayNotHasKey( 'rv-await', $this->options['ofwc_statuses'] );
		$this->assertSame( array( 'completed' ), array_values( array_unique( array_column( FakeShop::$orders, 'status' ) ) ) );

		// Without orders the status goes at once.
		$this->options['ofwc_statuses']['empty'] = array(
			'slug'  => 'empty',
			'label' => 'Empty',
		);
		Repository::flush_cache();
		$this->assertTrue( ( new Repository() )->delete( 'empty' ) );
		$this->assertArrayNotHasKey( 'empty', $this->options['ofwc_statuses'] );
	}

	/**
	 * Delete refuses a status that is itself waiting to be deleted as the target.
	 */
	public function test_target_cannot_be_a_status_being_deleted(): void {
		FakeShop::add( 2, 'rv-await' );
		$this->options['ofwc_statuses']['rv-prod'] = array(
			'slug'  => 'rv-prod',
			'label' => 'Prod',
		);
		Repository::flush_cache();
		list( $repo, $del ) = $this->pair();
		$del->start( 'rv-prod', 'completed', false );
		FakeShop::$statuses['wc-rv-prod'] = 'Prod';

		$result = $repo->delete( 'rv-await', 'rv-prod' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_target_invalid', $result->get_error_code() );
	}
}
