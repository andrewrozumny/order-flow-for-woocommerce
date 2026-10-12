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
}
