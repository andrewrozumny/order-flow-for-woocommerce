<?php
/**
 * Plugin boot rules.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit;

use Brain\Monkey\Functions;
use OrderFlowWC\Plugin;

/**
 * Boot happens on plugins_loaded and only when WooCommerce is really loaded.
 *
 * @covers \OrderFlowWC\Plugin
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PluginBootTest extends TestCase {

	/**
	 * The main file boots on plugins_loaded, never while it is being included.
	 */
	public function test_main_file_boots_on_plugins_loaded(): void {
		$code = (string) file_get_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a source file in a test.
			dirname( __DIR__, 2 ) . '/order-flow-for-woocommerce.php'
		);

		$this->assertMatchesRegularExpression( "/add_action\\(\\s*'plugins_loaded',\\s*array\\(\\s*\\\\OrderFlowWC\\\\Plugin::instance\\(\\),\\s*'boot'/", $code );
		$this->assertDoesNotMatchRegularExpression( '/^\\\\OrderFlowWC\\\\Plugin::instance\\(\\)->boot\\(\\);/m', $code );
		$this->assertStringContainsString( 'register_deactivation_hook', $code );
	}

	/**
	 * Without a loaded WooCommerce nothing is hooked and a notice appears.
	 */
	public function test_no_woocommerce_class_means_notice_only(): void {
		Functions\expect( 'add_action' )->once()->with( 'admin_notices', \Mockery::type( 'array' ) );

		Plugin::instance()->boot();
		Plugin::instance()->boot();

		$this->assertFalse( class_exists( 'WooCommerce', false ) );
	}

	/**
	 * Active in the options but not loaded (recovery mode): the notice says so.
	 */
	public function test_paused_woocommerce_gets_its_own_notice(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_site_option' )->justReturn( array( 'woocommerce/woocommerce.php' => 1 ) );

		ob_start();
		Plugin::instance()->missing_woocommerce_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'did not load', $html );
	}

	/**
	 * Not installed at all: the usual notice.
	 */
	public function test_missing_woocommerce_notice(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );

		ob_start();
		Plugin::instance()->missing_woocommerce_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'needs WooCommerce', $html );
	}
}
