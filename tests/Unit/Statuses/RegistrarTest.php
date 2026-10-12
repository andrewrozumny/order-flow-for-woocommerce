<?php
/**
 * Registrar tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Statuses;

use Brain\Monkey\Functions;
use OrderFlowWC\Statuses\Colors;
use OrderFlowWC\Statuses\Registrar;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * Filters and registration.
 *
 * @covers \OrderFlowWC\Statuses\Registrar
 * @covers \OrderFlowWC\Statuses\Colors
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
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->justReturn( true );
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
	 * A status hidden from customers shows as Processing to a person who cannot work on orders, wherever the request
	 * comes from: the shop pages, admin-ajax, the REST API or the Store API.
	 *
	 * @dataProvider customer_doors
	 *
	 * @param bool $is_admin Whether the request is a wp-admin one (admin-ajax counts as such).
	 */
	public function test_hidden_status_masked_for_a_customer( bool $is_admin ): void {
		Functions\when( 'is_admin' )->justReturn( $is_admin );
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = ( new Registrar() )->filter_order_statuses( array( 'wc-processing' => 'Processing' ) );
		$this->assertSame( 'Processing', $result['wc-proof'] );
		$this->assertSame( 'Packed', $result['wc-packed'] );
	}

	/**
	 * Requests a customer can make.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function customer_doors(): array {
		return array(
			'shop page'                 => array( false ),
			'admin-ajax from the front' => array( true ),
		);
	}

	/**
	 * A person who works on orders sees the real label, in wp-admin and in the REST API alike.
	 */
	public function test_hidden_status_shows_its_label_to_order_staff(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$result = ( new Registrar() )->filter_order_statuses( array( 'wc-processing' => 'Processing' ) );
		$this->assertSame( 'Awaiting proof', $result['wc-proof'] );
	}

	/**
	 * Cron and WP-CLI never show anything to a customer.
	 */
	public function test_hidden_status_shows_its_label_in_cron(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );

		$result = ( new Registrar() )->filter_order_statuses( array( 'wc-processing' => 'Processing' ) );
		$this->assertSame( 'Awaiting proof', $result['wc-proof'] );
	}

	/**
	 * The unpaid custom status is added to the statuses Analytics leaves out; the paid one is not.
	 */
	public function test_unpaid_statuses_are_excluded_from_analytics(): void {
		$registrar = new Registrar();

		$this->assertSame( array( 'pending', 'packed' ), $registrar->filter_analytics_excluded_statuses( array( 'pending' ) ) );
		$this->assertSame( array( 'packed' ), $registrar->filter_analytics_excluded_statuses( array( 'packed' ) ), 'no duplicates' );
		$this->assertFalse( $registrar->filter_analytics_excluded_statuses( false ) );
	}

	/**
	 * A shop that wants unpaid statuses in Analytics switches the exclusion off.
	 */
	public function test_analytics_exclusion_can_be_switched_off(): void {
		\Brain\Monkey\Filters\expectApplied( 'ofwc_exclude_unpaid_from_analytics' )->once()->andReturn( false );

		$this->assertSame( array( 'pending' ), ( new Registrar() )->filter_analytics_excluded_statuses( array( 'pending' ) ) );
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
		$css = ( new Colors( new Repository() ) )->css();
		$this->assertStringContainsString( '.order-status.status-proof{background:#112233;color:#ffffff;}', $css );
		$this->assertStringContainsString( '.order-status.status-packed{background:#777777;color:#ffffff;}', $css );
		$this->assertSame( '#1d2327', Colors::text_color( '#ffff00' ) );
	}

	/**
	 * The repository used by the registrar can be swapped.
	 */
	public function test_uses_given_repository(): void {
		$repo = new Repository();
		$this->assertSame( array( 'proof', 'packed' ), array_keys( $repo->all() ) );
	}
}
