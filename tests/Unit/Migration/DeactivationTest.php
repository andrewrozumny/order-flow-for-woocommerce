<?php
/**
 * Deactivation screen paths.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use OrderFlowWC\Admin\Deactivation;
use OrderFlowWC\Tests\Unit\TestCase;
use RuntimeException;

/**
 * The bulk action on the plugins list leads to the screen when orders sit in custom statuses.
 *
 * @covers \OrderFlowWC\Admin\Deactivation
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DeactivationTest extends TestCase {

	/**
	 * Plugins deactivated through the stub.
	 *
	 * @var string[]
	 */
	private array $deactivated = array();

	/**
	 * Redirect target captured by the stub.
	 *
	 * @var string
	 */
	private string $redirect = '';

	/**
	 * Stub the admin functions the interception uses.
	 *
	 * @param int $orders Orders in custom statuses.
	 */
	private function arrange( int $orders ): void {
		$this->deactivated = array();
		$this->redirect    = '';
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'map_deep' )->alias(
			static function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'plugin_basename' )->justReturn( 'order-flow-for-woocommerce/order-flow-for-woocommerce.php' );
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( string $key, string $value, string $url ): string {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'deactivate_plugins' )->alias(
			function ( $plugins ): void {
				$this->deactivated = array_merge( $this->deactivated, (array) $plugins );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ): void {
				$this->redirect = $url;
				throw new RuntimeException( 'redirect' );
			}
		);
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
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => $orders ) );
	}

	/**
	 * Bulk deactivate with our plugin and another one selected.
	 */
	private function bulk(): void {
		$_REQUEST['action'] = 'deactivate-selected';
		$_POST['checked']   = array( 'akismet/akismet.php', 'order-flow-for-woocommerce/order-flow-for-woocommerce.php' );
		try {
			( new Deactivation() )->intercept_bulk_deactivate();
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
	}

	/**
	 * With orders in custom statuses the other plugins are deactivated and we go to our screen.
	 */
	public function test_bulk_deactivate_leads_to_the_screen(): void {
		$this->arrange( 3 );

		$this->bulk();

		$this->assertSame( array( 'akismet/akismet.php' ), $this->deactivated );
		$this->assertStringContainsString( 'page=ofwc-deactivate', $this->redirect );
	}

	/**
	 * Without such orders core handles the bulk action untouched.
	 */
	public function test_bulk_deactivate_without_orders_is_left_to_core(): void {
		$this->arrange( 0 );

		$this->bulk();

		$this->assertSame( array(), $this->deactivated );
		$this->assertSame( '', $this->redirect );
	}

	/**
	 * Other bulk actions and selections without our plugin are ignored.
	 */
	public function test_other_actions_are_ignored(): void {
		$this->arrange( 3 );
		$_REQUEST['action'] = 'activate-selected';
		$_POST['checked']   = array( 'order-flow-for-woocommerce/order-flow-for-woocommerce.php' );
		( new Deactivation() )->intercept_bulk_deactivate();

		$_REQUEST['action'] = 'deactivate-selected';
		$_POST['checked']   = array( 'akismet/akismet.php' );
		( new Deactivation() )->intercept_bulk_deactivate();

		$this->assertSame( array(), $this->deactivated );
		$this->assertSame( '', $this->redirect );
	}
}
