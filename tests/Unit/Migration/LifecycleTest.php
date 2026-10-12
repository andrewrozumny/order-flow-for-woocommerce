<?php
/**
 * Activation and deactivation hooks.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use OrderFlowWC\Migration\Lifecycle;
use OrderFlowWC\Migration\Orders;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * No order work in either hook; a network gets one deferred restore per site.
 *
 * @covers \OrderFlowWC\Migration\Lifecycle
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LifecycleTest extends TestCase {

	/**
	 * Options by blog.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $site_options = array();

	/**
	 * Current blog.
	 *
	 * @var int
	 */
	private int $blog = 1;

	/**
	 * Stub the network functions: 250 sites.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->blog         = 1;
		$this->site_options = array();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias(
			static function ( array $args ): array {
				$all = range( 1, 250 );
				return array_slice( $all, (int) $args['offset'], (int) $args['number'] );
			}
		);
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $id ): bool {
				$this->blog = $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->blog = 1;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->site_options[ $this->blog ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
	}

	/**
	 * Network activation flags every site, in pages of 100, and touches no order.
	 */
	public function test_network_activation_flags_every_site(): void {
		Functions\expect( 'wc_get_orders' )->never();

		Lifecycle::activate( true );

		$this->assertCount( 250, $this->site_options );
		$this->assertSame( '1', $this->site_options[250][ Orders::PENDING_FLAG ] );
		$this->assertSame( '1', $this->site_options[1][ Orders::PENDING_FLAG ] );
	}

	/**
	 * Deactivation never reads or changes orders, on a site or on a network.
	 */
	public function test_deactivation_does_no_order_work(): void {
		Functions\expect( 'wc_get_orders' )->never();
		Functions\expect( 'wc_get_order' )->never();

		Lifecycle::deactivate( false );
		Lifecycle::deactivate( true );

		$this->assertSame( array(), $this->site_options );
	}

	/**
	 * The WP-CLI warning says how many orders are affected and what happens to them.
	 */
	public function test_cli_message_counts_orders(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) {
				return 'ofwc_statuses' === $name ? array(
					'rv-await' => array(
						'slug'  => 'rv-await',
						'label' => 'A',
					),
				) : $fallback;
			}
		);
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 7 ) );
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( '_n' )->alias(
			static function ( string $single, string $plural, int $number ): string {
				return 1 === $number ? $single : $plural;
			}
		);

		$message = Lifecycle::cli_message( false );

		$this->assertStringContainsString( '7 orders', $message );
		$this->assertStringContainsString( 'not moved', $message );
		$this->assertStringContainsString( 'network', Lifecycle::cli_message( true ) );
	}

	/**
	 * Nothing to warn about when no order sits in a custom status.
	 */
	public function test_cli_message_is_empty_without_orders(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 0 ) );

		$this->assertSame( '', Lifecycle::cli_message( false ) );
	}
}
