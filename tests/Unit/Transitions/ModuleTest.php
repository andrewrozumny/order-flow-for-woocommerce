<?php
/**
 * Transitions module tests: order form, box, notify choice.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Masking;
use OrderFlowWC\Statuses\Slug;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\MetaBox;
use OrderFlowWC\Transitions\Module;
use OrderFlowWC\Transitions\Rules;
use WC_Order;

/**
 * Posted comment and notify choice reach the code that records and mails the change.
 *
 * @covers \OrderFlowWC\Transitions\Module
 * @covers \OrderFlowWC\Transitions\MetaBox
 * @covers \OrderFlowWC\Statuses\Slug
 * @covers \OrderFlowWC\Statuses\Masking
 */
final class ModuleTest extends TestCase {

	use RulesFixture;

	/**
	 * Clean request.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_rules( array( $this->rule( 'proof', 'packed', array( 'shop_manager' ) ) ) );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wc_get_order_status_name' )->alias( 'ucfirst' );
		Context::clear();
		$_POST = array();
	}

	/**
	 * Leave no request data behind.
	 */
	protected function tear_down(): void {
		$_POST = array();
		Context::clear();
		parent::tear_down();
	}

	/**
	 * Order double in a status.
	 *
	 * @param string $status Status.
	 * @return \Mockery\MockInterface&WC_Order
	 */
	private function order( string $status ) {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_id' )->andReturn( 41 );
		return $order;
	}

	/**
	 * The order form hands its comment to the Context when the status changes; the notify box is off by default.
	 */
	public function test_form_comment_reaches_the_context_without_the_customer_tick(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'proof' ) );
		$_POST = array(
			'order_status' => 'wc-packed',
			'ofwc_comment' => '  INTERNAL: credit check  ',
		);

		( new Module() )->prepare_order_save( 41 );

		$this->assertSame( 'INTERNAL: credit check', Context::comment() );
		$this->assertFalse( Context::notify_customer() );
		$this->assertSame( 2, Context::user_id() );
	}

	/**
	 * With the tick the comment may go to the customer.
	 */
	public function test_form_tick_marks_the_comment_for_the_customer(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'proof' ) );
		$_POST = array(
			'order_status'         => 'wc-packed',
			'ofwc_comment'         => 'Ready on Friday',
			'ofwc_notify_customer' => '1',
		);

		( new Module() )->prepare_order_save( 41 );

		$this->assertTrue( Context::notify_customer() );
	}

	/**
	 * No status change, no context.
	 */
	public function test_unchanged_status_sets_no_context(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'proof' ) );
		$_POST = array(
			'order_status' => 'wc-proof',
			'ofwc_comment' => 'x',
		);

		( new Module() )->prepare_order_save( 41 );

		$this->assertSame( '', Context::comment() );
	}

	/**
	 * Orders that are not created yet start from "new" for the rules.
	 */
	public function test_origin_of_new_orders(): void {
		$this->assertSame( 'new', Module::origin( '' ) );
		$this->assertSame( 'new', Module::origin( 'auto-draft' ) );
		$this->assertSame( 'new', Module::origin( 'checkout-draft' ) );
		$this->assertSame( 'proof', Module::origin( 'proof' ) );
	}

	/**
	 * The Order Flow box offers a customer tick that is off by default, and says who sees the comment.
	 */
	public function test_box_has_the_customer_tick_off_by_default(): void {
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				return 'ofwc_statuses' === $name ? array( 'proof' => array( 'slug' => 'proof' ) ) : array();
			}
		);
		$order = $this->order( 'pending' );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );

		ob_start();
		( new MetaBox( new Rules() ) )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="ofwc_notify_customer"', $html );
		$this->assertStringContainsString( 'Send this comment to the customer', $html );
		$this->assertStringNotContainsString( 'name="ofwc_notify_customer" value="1" checked', $html );
		$this->assertStringContainsString( 'Customers see it only when you tick the box', $html );
	}

	/**
	 * WooCommerce is switched on but did not load (recovery mode): the screens that ask for its order utility
	 * must not fatal on every post edit screen.
	 */
	public function test_screens_survive_a_woocommerce_that_did_not_load(): void {
		$this->assertFalse( class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil', false ) );
		Functions\expect( 'add_meta_box' )->never();
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'post' ) );
		Functions\expect( 'wp_enqueue_script' )->never();

		$module = new Module();
		$module->add_meta_box( 'post' );
		$module->enqueue();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Prefix helpers.
	 */
	public function test_slug_helpers(): void {
		$this->assertSame( 'proof', Slug::strip( 'wc-proof' ) );
		$this->assertSame( 'proof', Slug::strip( 'proof' ) );
		$this->assertSame( 'wc-proof', Slug::prefixed( 'proof' ) );
		$this->assertSame( 'wc-proof', Slug::prefixed( 'wc-proof' ) );
	}

	/**
	 * A hidden status shows the stand-in label to the customer.
	 */
	public function test_masking_label(): void {
		Functions\when( 'wc_get_order_status_name' )->justReturn( 'Processing' );

		$this->assertSame(
			'Processing',
			Masking::customer_label(
				array_merge(
					( new \OrderFlowWC\Statuses\Repository() )->defaults(),
					array(
						'label'           => 'Proof',
						'show_in_account' => false,
					)
				)
			)
		);
		$this->assertSame(
			'Proof',
			Masking::customer_label(
				array_merge(
					( new \OrderFlowWC\Statuses\Repository() )->defaults(),
					array(
						'label'           => 'Proof',
						'show_in_account' => true,
					)
				)
			)
		);
	}
}
