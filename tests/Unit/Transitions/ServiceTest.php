<?php
/**
 * Transition service tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\History\Context;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\OrderLock;
use OrderFlowWC\Transitions\Service;
use WC_Order;

/**
 * Service validation and context handling.
 *
 * @covers \OrderFlowWC\Transitions\Service
 */
final class ServiceTest extends TestCase {

	/**
	 * Statuses, one rule proof -> processing for shop managers that needs a comment.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				if ( 'ofwc_statuses' === $name ) {
					return array(
						'proof' => array(
							'slug'  => 'proof',
							'label' => 'Awaiting proof',
						),
					);
				}
				return array(
					array(
						'from'            => 'proof',
						'to'              => 'processing',
						'roles'           => array( 'shop_manager' ),
						'require_comment' => true,
					),
				);
			}
		);
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-processing' => 'Processing',
				'wc-proof'      => 'Awaiting proof',
			)
		);
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ): object {
				return (object) array( 'roles' => 2 === $id ? array( 'shop_manager' ) : array( 'customer' ) );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Context::clear();
	}

	/**
	 * Build an order mock in the "proof" status.
	 *
	 * @return \Mockery\MockInterface&WC_Order
	 */
	private function order() {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_status' )->andReturn( 'proof' );
		return $order;
	}

	/**
	 * A transition that needs a comment is refused without one and the order stays untouched.
	 */
	public function test_missing_comment_is_refused(): void {
		$order = $this->order();
		$order->shouldNotReceive( 'update_status' );

		$result = ( new Service() )->transition( $order, 'processing', '   ' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ofwc_comment_required', $result->get_error_code() );
	}

	/**
	 * A forbidden transition is refused.
	 */
	public function test_forbidden_transition_is_refused(): void {
		$order = $this->order();
		$order->shouldNotReceive( 'update_status' );

		$result = ( new Service() )->transition( $order, 'processing', 'ok', 3 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ofwc_not_allowed', $result->get_error_code() );

		$result = ( new Service() )->transition( $this->order(), 'unknown', 'ok' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ofwc_unknown_status', $result->get_error_code() );
	}

	/**
	 * With a comment the status is updated, and the context is set during the update and cleared after.
	 */
	public function test_allowed_transition_updates_status_with_context(): void {
		$seen  = array();
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_status' )->andReturn( 'proof' );
		$order->shouldReceive( 'update_status' )->once()->with( 'processing' )->andReturnUsing(
			static function () use ( &$seen ): bool {
				$seen = array( Context::comment(), Context::user_id() );
				return true;
			}
		);

		$result = ( new Service() )->transition( $order, 'wc-processing', ' Proof approved ', 2 );

		$this->assertTrue( $result );
		$this->assertSame( array( 'Proof approved', 2 ), $seen );
		$this->assertSame( '', Context::comment(), 'context is cleared afterwards' );
	}

	/**
	 * A change that cannot get the lock is refused with a clear message and touches nothing.
	 */
	public function test_busy_order_is_refused(): void {
		$order = $this->order();
		$order->shouldReceive( 'get_id' )->andReturn( 7 );
		$order->shouldNotReceive( 'update_status' );
		$lock = Mockery::mock( OrderLock::class );
		$lock->shouldReceive( 'acquire' )->once()->with( 7 )->andReturn( null );
		$lock->shouldNotReceive( 'release' );

		$result = ( new Service( null, $lock ) )->transition( $order, 'processing', 'ok', 2 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ofwc_order_busy', $result->get_error_code() );
	}

	/**
	 * Inside the lock the order is read again: a status that moved while the request waited decides the check,
	 * and the lock is released whatever happens.
	 */
	public function test_status_is_read_again_inside_the_lock_and_the_lock_is_released(): void {
		$stale = $this->order();
		$stale->shouldReceive( 'get_id' )->andReturn( 7 );
		$stale->shouldNotReceive( 'update_status' );
		// Another request already moved the order to processing.
		$fresh = Mockery::mock( WC_Order::class );
		$fresh->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$fresh->shouldNotReceive( 'update_status' );
		$lock = Mockery::mock( OrderLock::class );
		$lock->shouldReceive( 'acquire' )->once()->andReturn( 'token' );
		$lock->shouldReceive( 'fresh' )->once()->with( $stale )->andReturn( $fresh );
		$lock->shouldReceive( 'release' )->once()->with( 7, 'token' );

		$result = ( new Service( null, $lock ) )->transition( $stale, 'processing', 'ok', 2 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ofwc_same_status', $result->get_error_code(), 'the second request is a no-op, not a second change' );
	}

	/**
	 * The lock is released when the status update throws.
	 */
	public function test_lock_is_released_when_the_update_throws(): void {
		$order = $this->order();
		$order->shouldReceive( 'get_id' )->andReturn( 7 );
		$order->shouldReceive( 'update_status' )->andThrow( new \RuntimeException( 'boom' ) );
		$lock = Mockery::mock( OrderLock::class );
		$lock->shouldReceive( 'acquire' )->andReturn( 'token' );
		$lock->shouldReceive( 'fresh' )->andReturn( $order );
		$lock->shouldReceive( 'release' )->once()->with( 7, 'token' );

		$this->expectException( \RuntimeException::class );
		( new Service( null, $lock ) )->transition( $order, 'processing', 'ok', 2 );
	}
}
