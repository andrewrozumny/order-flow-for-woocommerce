<?php
/**
 * History module tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\History;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\History\Context;
use OrderFlowWC\History\History;
use OrderFlowWC\History\Module;
use OrderFlowWC\Tests\Unit\TestCase;
use WC_Order;

/**
 * Recording of status changes.
 *
 * @covers \OrderFlowWC\History\Module
 * @covers \OrderFlowWC\History\History
 * @covers \OrderFlowWC\History\Context
 */
final class ModuleTest extends TestCase {

	/**
	 * One custom status "proof".
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_option' )->justReturn(
			array(
				'proof' => array(
					'slug'  => 'proof',
					'label' => 'Awaiting proof',
				),
			)
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $slug ): string {
				return ucfirst( $slug );
			}
		);
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Sam Manager' ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Context::clear();
	}

	/**
	 * Order mock that keeps its meta in memory.
	 *
	 * @param array<int, mixed> $store Meta store, by reference.
	 * @return \Mockery\MockInterface&WC_Order
	 */
	private function order( array &$store ) {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function () use ( &$store ) {
				return $store['history'] ?? '';
			}
		);
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( string $key, $value ) use ( &$store ): void {
				$store[ $key ]    = $value;
				$store['history'] = $value;
			}
		);
		$order->shouldReceive( 'save_meta_data' )->andReturnNull();
		return $order;
	}

	/**
	 * A change into a custom status is recorded, noted and announced.
	 */
	public function test_records_entry_note_and_fires_action(): void {
		$store = array();
		$order = $this->order( $store );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Order Flow: Processing to Proof by Sam Manager. Comment: Looks good', 0, false );

		Actions\expectDone( 'ofwc_status_entered' )->once()->with( $order, 'proof', 'processing', 'Looks good' );

		Context::set( 'Looks good', 7 );
		( new Module() )->record( 5, 'processing', 'proof', $order );

		$entries = History::get( $order );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'processing', $entries[0]['from'] );
		$this->assertSame( 'proof', $entries[0]['to'] );
		$this->assertSame( 7, $entries[0]['user_id'] );
		$this->assertSame( 'Looks good', $entries[0]['comment'] );
	}

	/**
	 * A change between core statuses is recorded but not announced.
	 */
	public function test_core_change_does_not_fire_status_entered(): void {
		$store = array();
		$order = $this->order( $store );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Order Flow: Pending to Processing by Sam Manager.', 0, false );

		Actions\expectDone( 'ofwc_status_entered' )->never();

		( new Module() )->record( 5, 'pending', 'processing', $order );

		$this->assertCount( 1, History::get( $order ) );
	}
}
