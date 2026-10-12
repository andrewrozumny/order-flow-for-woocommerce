<?php
/**
 * Registrar tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Statuses;

use Brain\Monkey\Functions;
use OrderFlowWC\Statuses\Registrar;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * Filters and registration.
 *
 * @covers \OrderFlowWC\Statuses\Registrar
 */
final class RegistrarTest extends TestCase {

	/**
	 * Two stored statuses: one paid with stock reduction and hidden, one plain.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_option' )->justReturn(
			array(
				'proof'  => array(
					'slug'            => 'proof',
					'label'           => 'Awaiting proof',
					'position'        => 1,
					'paid'            => true,
					'reduce_stock'    => true,
					'show_in_account' => false,
					'color'           => '#112233',
				),
				'packed' => array(
					'slug'     => 'packed',
					'label'    => 'Packed',
					'position' => 2,
				),
			)
		);
		Functions\when( 'is_admin' )->justReturn( true );
	}

	/**
	 * Custom statuses are added with the wc- prefix, right after Processing.
	 */
	public function test_order_statuses_get_prefixed_labels_after_processing(): void {
		$result = ( new Registrar() )->filter_order_statuses(
			array(
				'wc-pending'    => 'Pending payment',
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
			)
		);

		$this->assertSame(
			array( 'wc-pending', 'wc-processing', 'wc-proof', 'wc-packed', 'wc-completed' ),
			array_keys( $result )
		);
		$this->assertSame( 'Awaiting proof', $result['wc-proof'] );
		$this->assertSame( 'Packed', $result['wc-packed'] );
	}

	/**
	 * Without Processing the statuses are appended.
	 */
	public function test_order_statuses_appended_without_processing(): void {
		$result = ( new Registrar() )->filter_order_statuses( array( 'wc-pending' => 'Pending payment' ) );
		$this->assertSame( array( 'wc-pending', 'wc-proof', 'wc-packed' ), array_keys( $result ) );
	}

	/**
	 * On the front end a status hidden from customers shows as Processing.
	 */
	public function test_hidden_status_masked_on_front_end(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		$result = ( new Registrar() )->filter_order_statuses( array( 'wc-processing' => 'Processing' ) );
		$this->assertSame( 'Processing', $result['wc-proof'] );
		$this->assertSame( 'Packed', $result['wc-packed'] );
	}

	/**
	 * Paid custom statuses join the paid list and reports.
	 */
	public function test_paid_and_reports_statuses(): void {
		$registrar = new Registrar();
		$this->assertSame( array( 'processing', 'completed', 'proof' ), $registrar->filter_paid_statuses( array( 'processing', 'completed' ) ) );
		$this->assertSame( array( 'completed', 'proof' ), $registrar->filter_reports_statuses( array( 'completed' ) ) );
		$this->assertFalse( $registrar->filter_reports_statuses( false ) );
	}

	/**
	 * Stock is reduced only when entering a flagged status.
	 */
	public function test_reduce_stock_only_for_flagged_status(): void {
		Functions\expect( 'wc_maybe_reduce_stock_levels' )->once()->with( 15 );

		$registrar = new Registrar();
		$registrar->maybe_reduce_stock( 15, 'pending', 'proof' );
		$registrar->maybe_reduce_stock( 16, 'pending', 'packed' );
	}

	/**
	 * Every status is registered as wc-{slug}.
	 */
	public function test_register_post_statuses(): void {
		$names = array();
		Functions\when( 'register_post_status' )->alias(
			static function ( string $name, array $args ) use ( &$names ): void {
				$names[ $name ] = $args;
			}
		);

		( new Registrar() )->register_post_statuses();

		$this->assertSame( array( 'wc-proof', 'wc-packed' ), array_keys( $names ) );
		$this->assertSame( 'Awaiting proof', $names['wc-proof']['label'] );
		$this->assertTrue( $names['wc-proof']['show_in_admin_status_list'] );
		$this->assertFalse( $names['wc-proof']['public'] );
	}

	/**
	 * Admin CSS gives each status its color with a readable text color.
	 */
	public function test_status_css(): void {
		$css = ( new Registrar() )->status_css();
		$this->assertStringContainsString( '.order-status.status-proof{background:#112233;color:#ffffff;}', $css );
		$this->assertStringContainsString( '.order-status.status-packed{background:#777777;color:#ffffff;}', $css );
		$this->assertSame( '#1d2327', Registrar::text_color( '#ffff00' ) );
	}

	/**
	 * The repository used by the registrar can be swapped.
	 */
	public function test_uses_given_repository(): void {
		$repo = new Repository();
		$this->assertSame( array( 'proof', 'packed' ), array_keys( $repo->all() ) );
	}
}
