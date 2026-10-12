<?php
/**
 * Order list buttons and bulk actions.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\OrderActions;
use OrderFlowWC\Transitions\Rules;
use OrderFlowWC\Transitions\Service;
use WC_Order;

/**
 * The screen offers only what the rules allow.
 *
 * @covers \OrderFlowWC\Transitions\OrderActions
 */
final class OrderActionsTest extends TestCase {

	use RulesFixture;

	/**
	 * A shop manager who may move proof to packed, and only that.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_rules( array( $this->rule( 'proof', 'packed', array( 'shop_manager' ) ) ) );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Functions\when( 'wp_specialchars_decode' )->alias( 'htmlspecialchars_decode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * The class under test.
	 */
	private function actions(): OrderActions {
		return new OrderActions( new Rules(), new Service() );
	}

	/**
	 * Order in a status.
	 *
	 * @param string $status Status.
	 * @return \Mockery\MockInterface&WC_Order
	 */
	private function order( string $status ) {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		return $order;
	}

	/**
	 * A WooCommerce status button.
	 *
	 * @param string $status Target status.
	 * @return array<string, string>
	 */
	private function button( string $status ): array {
		return array(
			'url'    => 'https://shop.test/wp-admin/admin-ajax.php?action=woocommerce_mark_order_status&amp;status=' . $status . '&amp;order_id=5&amp;_wpnonce=abc',
			'name'   => ucfirst( $status ),
			'action' => $status,
		);
	}

	/**
	 * Buttons the person cannot apply to this order disappear; others stay, and so do non-status buttons.
	 */
	public function test_row_buttons_follow_the_rules(): void {
		$buttons = array(
			'processing' => $this->button( 'processing' ),
			'complete'   => $this->button( 'completed' ),
			'view'       => array(
				'url'  => 'https://shop.test/wp-admin/post.php?post=5&action=edit',
				'name' => 'View',
			),
		);

		$from_proof = $this->actions()->filter_row_actions( $buttons, $this->order( 'proof' ) );
		$this->assertSame( array( 'view' ), array_keys( (array) $from_proof ), 'leaving proof for core statuses needs a rule' );

		$from_core = $this->actions()->filter_row_actions( $buttons, $this->order( 'pending' ) );
		$this->assertSame( array( 'processing', 'complete', 'view' ), array_keys( (array) $from_core ) );
	}

	/**
	 * Preview window buttons are cut the same way.
	 */
	public function test_preview_buttons_follow_the_rules(): void {
		$actions = array(
			'status' => array(
				'group'   => 'Change status: ',
				'actions' => array(
					'complete' => $this->button( 'completed' ),
				),
			),
		);

		$result = (array) $this->actions()->filter_preview_actions( $actions, $this->order( 'proof' ) );

		$this->assertSame( array(), $result['status']['actions'] );
		$this->assertSame( 'Change status: ', $result['status']['group'] );
	}

	/**
	 * An administrator keeps every button.
	 */
	public function test_administrator_keeps_all_buttons(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$buttons = array( 'complete' => $this->button( 'completed' ) );

		$this->assertSame( $buttons, $this->actions()->filter_row_actions( $buttons, $this->order( 'proof' ) ) );
	}

	/**
	 * A bulk action to a status the person cannot reach from any status is removed; the rest stay.
	 */
	public function test_bulk_actions_without_a_way_in_are_removed(): void {
		$actions = array(
			'mark_completed' => 'Change status to completed',
			'mark_packed'    => 'Change status to packed',
			'mark_proof'     => 'Change status to proof',
			'trash'          => 'Move to Trash',
			'ofwc_to_packed' => 'Change status to Packed',
		);

		$result = (array) $this->actions()->filter_bulk_actions( $actions );

		$this->assertSame( array( 'mark_completed', 'mark_packed', 'trash', 'ofwc_to_packed' ), array_keys( $result ) );
	}

	/**
	 * Other filter values pass through untouched.
	 */
	public function test_non_arrays_pass_through(): void {
		$this->assertSame( 'x', $this->actions()->filter_bulk_actions( 'x' ) );
		$this->assertSame( 'x', $this->actions()->filter_row_actions( 'x', $this->order( 'proof' ) ) );
		$this->assertSame( 'x', $this->actions()->filter_preview_actions( 'x', $this->order( 'proof' ) ) );
	}
}
