<?php
/**
 * StatusEmail tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Emails;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\Emails\StatusEmail;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;

if ( ! class_exists( 'WC_Email' ) ) {
	require_once __DIR__ . '/WC_Email.php';
}

/**
 * Status email behaviour.
 *
 * @covers \OrderFlowWC\Emails\StatusEmail
 */
final class StatusEmailTest extends TestCase {

	/**
	 * Stub the WordPress helpers the email uses.
	 */
	protected function set_up(): void {
		parent::set_up();
		\WC_Email::$options = array();
		\WC_Email::$sent    = array();
		Functions\when( 'get_option' )->justReturn( 'shop@example.test' );
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_format_datetime' )->justReturn( 'October 12, 2026' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Shop' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wc_get_template_html' )->justReturn( '<html></html>' );
	}

	/**
	 * Status config with overrides.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function status( array $over = array() ): array {
		return array_merge(
			( new Repository() )->defaults(),
			array(
				'slug'           => 'packed',
				'label'          => 'Packed',
				'email_customer' => true,
				'email_admin'    => false,
				'email_subject'  => 'Order {order_number} is {status}',
				'email_heading'  => 'Now {status}',
				'email_body'     => '<p>Hi {customer_name}. {note}</p>',
			),
			$over
		);
	}

	/**
	 * Order double.
	 *
	 * @param string $email Billing email.
	 */
	private function order( string $email = 'ann@example.test' ): \WC_Order {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '1042' );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'Ann' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( 'Lee' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $email );
		$order->shouldReceive( 'get_date_created' )->andReturn( new \DateTimeImmutable( '2026-10-12' ) );
		$order->shouldReceive( 'get_formatted_order_total' )->andReturn( '$25.00' );
		return $order;
	}

	/**
	 * Identity and title.
	 */
	public function test_identity(): void {
		$customer = new StatusEmail( $this->status(), 'customer' );
		$admin    = new StatusEmail( $this->status(), 'admin' );

		$this->assertSame( 'ofwc_packed_customer', $customer->id );
		$this->assertSame( 'ofwc_packed_admin', $admin->id );
		$this->assertTrue( $customer->customer_email );
		$this->assertFalse( $admin->customer_email );
		$this->assertSame( 'Order Flow: Packed (customer)', $customer->title );
		$this->assertSame( 'Order Flow: Packed (admin)', $admin->title );
		$this->assertSame( 'emails/ofwc-status.php', $customer->template_html );
		$this->assertStringEndsWith( 'templates/', $customer->template_base );
	}

	/**
	 * Placeholders are replaced in subject and heading once an order is set.
	 */
	public function test_subject_and_heading_placeholders(): void {
		$email = new StatusEmail( $this->status(), 'customer' );
		$this->assertSame( 'Order {order_number} is {status}', $email->get_subject() );

		$email->set_object( $this->order() );
		$this->assertSame( 'Order 1042 is Packed', $email->get_subject() );
		$this->assertSame( 'Now Packed', $email->get_heading() );
	}

	/**
	 * The note reaches the body.
	 */
	public function test_note_in_body(): void {
		$email = new StatusEmail( $this->status(), 'customer' );
		$email->trigger_status( $this->order(), 'Ready <b>today</b>' );

		$this->assertSame( '<p>Hi Ann Lee. Ready &lt;b&gt;today&lt;/b&gt;</p>', $email->get_body_html() );
		$this->assertSame( 'Hi Ann Lee. Ready today', $email->get_body_plain() );
	}

	/**
	 * Both the WooCommerce switch and the status flag must be on.
	 */
	public function test_is_enabled_respects_flags(): void {
		$this->assertTrue( ( new StatusEmail( $this->status(), 'customer' ) )->is_enabled() );
		$this->assertFalse( ( new StatusEmail( $this->status(), 'admin' ) )->is_enabled() );
		$this->assertTrue( ( new StatusEmail( $this->status( array( 'email_admin' => true ) ), 'admin' ) )->is_enabled() );

		\WC_Email::$options['enabled'] = 'no';
		$this->assertFalse( ( new StatusEmail( $this->status(), 'customer' ) )->is_enabled() );
	}

	/**
	 * Admin form has a recipient field, customer form does not.
	 */
	public function test_form_fields(): void {
		$customer = new StatusEmail( $this->status(), 'customer' );
		$admin    = new StatusEmail( $this->status(), 'admin' );

		$this->assertSame( array( 'enabled', 'email_type' ), array_keys( $customer->form_fields ) );
		$this->assertSame( array( 'enabled', 'recipient', 'email_type' ), array_keys( $admin->form_fields ) );
		$this->assertSame( 'shop@example.test', $admin->recipient );
	}

	/**
	 * A customer email goes to the billing address; no address means no mail.
	 */
	public function test_trigger_recipients(): void {
		$email = new StatusEmail( $this->status(), 'customer' );
		$this->assertTrue( $email->trigger_status( $this->order() ) );
		$this->assertSame( 'ann@example.test', \WC_Email::$sent[0]['to'] );
		$this->assertSame( 'Order 1042 is Packed', \WC_Email::$sent[0]['subject'] );

		\WC_Email::$sent = array();
		$this->assertFalse( $email->trigger_status( $this->order( '' ) ) );
		$this->assertSame( array(), \WC_Email::$sent );
	}

	/**
	 * Attachments come from the filter only when the PDF is enabled and attached.
	 */
	public function test_attachment_filter_only_when_pdf_attach(): void {
		Filters\expectApplied( 'ofwc_email_attachments' )->once()->with( array(), Mockery::type( 'WC_Order' ), 'packed' )->andReturn( array( '/tmp/a.pdf' ) );

		$on = new StatusEmail(
			$this->status(
				array(
					'pdf_enabled' => true,
					'pdf_attach'  => true,
				)
			),
			'customer'
		);
		$on->set_object( $this->order() );
		$this->assertSame( array( '/tmp/a.pdf' ), $on->get_attachments() );

		$no_attach = new StatusEmail(
			$this->status(
				array(
					'pdf_enabled' => true,
					'pdf_attach'  => false,
				)
			),
			'customer'
		);
		$no_attach->set_object( $this->order() );
		$this->assertSame( array(), $no_attach->get_attachments() );

		$no_pdf = new StatusEmail(
			$this->status(
				array(
					'pdf_enabled' => false,
					'pdf_attach'  => true,
				)
			),
			'customer'
		);
		$no_pdf->set_object( $this->order() );
		$this->assertSame( array(), $no_pdf->get_attachments() );
	}
}
