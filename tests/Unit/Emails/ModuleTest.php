<?php
/**
 * Emails module tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Emails;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\Emails\Module;
use OrderFlowWC\Emails\StatusEmail;
use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;

if ( ! class_exists( 'WC_Email' ) ) {
	require_once __DIR__ . '/WC_Email.php';
}

/**
 * Registration and triggering.
 *
 * @covers \OrderFlowWC\Emails\Module
 */
final class ModuleTest extends TestCase {

	/**
	 * Real repository over a stubbed option holding one status.
	 *
	 * @param array<string, mixed> $over Status overrides.
	 */
	private function repo( array $over = array() ): Repository {
		$status = array_merge(
			array(
				'slug'           => 'packed',
				'label'          => 'Packed',
				'email_customer' => true,
				'email_admin'    => true,
			),
			$over
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) use ( $status ) {
				return 'ofwc_statuses' === $name ? array( 'packed' => $status ) : 'shop@example.test';
			}
		);
		return new Repository();
	}

	/**
	 * The hooks are registered.
	 */
	public function test_register_hooks(): void {
		$module = new Module( $this->repo() );
		$module->register();
		$this->assertNotFalse( has_filter( 'woocommerce_email_classes', array( $module, 'register_emails' ) ) );
		$this->assertNotFalse( has_action( 'ofwc_status_entered', array( $module, 'send' ) ) );
	}

	/**
	 * Two emails per status are added to the WooCommerce list.
	 */
	public function test_registers_two_emails_per_status(): void {
		$emails = ( new Module( $this->repo() ) )->register_emails( array( 'new_order' => 'x' ) );

		$this->assertSame( array( 'new_order', 'ofwc_packed_customer', 'ofwc_packed_admin' ), array_keys( $emails ) );
		$this->assertInstanceOf( StatusEmail::class, $emails['ofwc_packed_customer'] );
		$this->assertInstanceOf( StatusEmail::class, $emails['ofwc_packed_admin'] );
	}

	/**
	 * Mailer double that hands out a customer and an admin email, each expecting one note.
	 *
	 * @param \WC_Order $order         Order.
	 * @param string    $customer_note Note the customer email must get.
	 * @param string    $admin_note    Note the admin email must get.
	 */
	private function expect_emails( $order, string $customer_note, string $admin_note ): void {
		$customer = Mockery::mock( StatusEmail::class );
		$customer->shouldReceive( 'trigger_status' )->once()->with( $order, $customer_note );
		$admin = Mockery::mock( StatusEmail::class );
		$admin->shouldReceive( 'trigger_status' )->once()->with( $order, $admin_note );

		$mailer = Mockery::mock();
		$mailer->shouldReceive( 'get_emails' )->once()->andReturn(
			array(
				'ofwc_packed_customer' => $customer,
				'ofwc_packed_admin'    => $admin,
				'ofwc_other_customer'  => Mockery::mock( StatusEmail::class ),
			)
		);
		$wc = Mockery::mock();
		$wc->shouldReceive( 'mailer' )->once()->andReturn( $mailer );
		Functions\when( 'WC' )->justReturn( $wc );
	}

	/**
	 * The shop admin gets the comment, the customer does not unless the person chose to send it.
	 */
	public function test_comment_goes_to_the_admin_only_by_default(): void {
		Context::clear();
		$order = Mockery::mock( 'WC_Order' );
		$this->expect_emails( $order, '', 'INTERNAL: credit check failed' );

		( new Module( $this->repo() ) )->send( $order, 'packed', 'processing', 'INTERNAL: credit check failed' );
	}

	/**
	 * With "Send this comment to the customer" ticked both emails carry the comment.
	 */
	public function test_comment_goes_to_the_customer_when_chosen(): void {
		Context::set( 'Looks good', 7, true );
		$order = Mockery::mock( 'WC_Order' );
		$this->expect_emails( $order, 'Looks good', 'Looks good' );

		( new Module( $this->repo() ) )->send( $order, 'packed', 'processing', 'Looks good' );
		Context::clear();
	}

	/**
	 * The tick does nothing without a comment.
	 */
	public function test_tick_without_a_comment_sends_nothing(): void {
		Context::set( '', 7, true );
		$order = Mockery::mock( 'WC_Order' );
		$this->expect_emails( $order, '', '' );

		( new Module( $this->repo() ) )->send( $order, 'packed', 'processing', '' );
		Context::clear();
	}

	/**
	 * Core statuses, unknown statuses and statuses without email flags do nothing.
	 */
	public function test_send_ignores_other_statuses(): void {
		$order = Mockery::mock( 'WC_Order' );
		Functions\expect( 'WC' )->never();

		( new Module( $this->repo() ) )->send( $order, 'processing', 'pending', '' );
		Repository::flush_cache();
		( new Module(
			$this->repo(
				array(
					'email_customer' => false,
					'email_admin'    => false,
				)
			)
		) )->send( $order, 'packed', 'processing', '' );
		$this->assertTrue( true );
	}
}
