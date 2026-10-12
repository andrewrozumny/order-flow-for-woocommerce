<?php
/**
 * Access to the Order Flow configuration.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use OrderFlowWC\Admin\DocumentsTab;
use OrderFlowWC\Admin\ImportExport;
use OrderFlowWC\Admin\Page;
use OrderFlowWC\Admin\StatusesTab;
use OrderFlowWC\Admin\TransitionsTab;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * A shop manager holds manage_woocommerce but not manage_options: every write of the configuration answers 403
 * and writes nothing.
 *
 * @covers \OrderFlowWC\Admin\Page
 * @covers \OrderFlowWC\Admin\TransitionsTab
 * @covers \OrderFlowWC\Admin\DocumentsTab
 * @covers \OrderFlowWC\Admin\StatusesTab
 * @covers \OrderFlowWC\Admin\ImportExport
 */
final class ConfigurationAccessTest extends TestCase {

	/**
	 * Capabilities of the current user.
	 *
	 * @var string[]
	 */
	private array $caps = array();

	/**
	 * Every write to the database and every handler outcome is recorded.
	 *
	 * @var string[]
	 */
	private array $writes = array();

	/**
	 * A shop manager.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->caps   = array( 'manage_woocommerce', 'edit_shop_orders' );
		$this->writes = array();
		Functions\when( 'current_user_can' )->alias(
			fn ( string $cap ): bool => in_array( $cap, $this->caps, true )
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $message, $title = '', $args = array() ): void {
				unset( $message, $title );
				$response = is_array( $args ) && isset( $args['response'] ) && is_int( $args['response'] ) ? $args['response'] : 0;
				throw new \RuntimeException( 'wp_die ' . $response ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
			}
		);
		foreach ( array( 'update_option', 'add_option', 'delete_option', 'set_transient', 'update_user_meta' ) as $function ) {
			Functions\when( $function )->alias(
				function () use ( $function ): bool {
					$this->writes[] = $function;
					return true;
				}
			);
		}
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Every admin-post handler of the configuration.
	 *
	 * @return array<string, array{callable-string}>
	 */
	public static function handlers(): array {
		return array(
			'save transitions' => array( 'transitions' ),
			'save documents'   => array( 'documents' ),
			'save status'      => array( 'save_status' ),
			'delete status'    => array( 'delete_status' ),
			'export'           => array( 'export' ),
			'import'           => array( 'import' ),
		);
	}

	/**
	 * Run one handler.
	 *
	 * @param string $name Handler key.
	 */
	private function run_handler( string $name ): void {
		switch ( $name ) {
			case 'transitions':
				( new TransitionsTab() )->handle_save();
				break;
			case 'documents':
				( new DocumentsTab() )->handle_save();
				break;
			case 'save_status':
				( new StatusesTab() )->handle_save();
				break;
			case 'delete_status':
				( new StatusesTab() )->handle_delete();
				break;
			case 'export':
				( new ImportExport() )->handle_export();
				break;
			default:
				( new ImportExport() )->handle_import();
		}
	}

	/**
	 * A shop manager gets 403 on every handler and nothing changes.
	 *
	 * @dataProvider handlers
	 *
	 * @param string $name Handler key.
	 */
	public function test_shop_manager_gets_403_on_every_handler( string $name ): void {
		try {
			$this->run_handler( $name );
			$this->fail( 'The handler ran for a shop manager.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die 403', $e->getMessage() );
		}
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * The default capability is manage_options.
	 */
	public function test_default_capability_is_manage_options(): void {
		$this->assertSame( 'manage_options', Page::capability() );
	}

	/**
	 * A site can hand the configuration to another capability.
	 */
	public function test_capability_filter(): void {
		Filters\expectApplied( 'ofwc_manage_capability' )->once()->with( 'manage_options' )->andReturn( 'manage_woocommerce' );
		$this->assertSame( 'manage_woocommerce', Page::capability() );

		Filters\expectApplied( 'ofwc_manage_capability' )->once()->andReturn( '' );
		$this->assertSame( 'manage_options', Page::capability(), 'an empty value falls back' );
	}

	/**
	 * The menu entry needs the same capability, so a shop manager does not see it.
	 */
	public function test_menu_item_needs_the_configuration_capability(): void {
		$seen = '';
		Functions\when( 'add_submenu_page' )->alias(
			static function ( $parent_slug, $title, $menu, $cap ) use ( &$seen ): string {
				unset( $parent_slug, $title, $menu );
				$seen = $cap;
				return 'woocommerce_page_ofwc';
			}
		);

		( new Page() )->add_menu();

		$this->assertSame( 'manage_options', $seen );
	}

	/**
	 * An administrator passes.
	 */
	public function test_administrator_passes_the_gate(): void {
		$this->caps = array( 'manage_options' );
		Page::authorize();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * The page itself is closed to a shop manager.
	 */
	public function test_page_render_is_closed_to_a_shop_manager(): void {
		$this->expectExceptionMessage( 'wp_die 403' );
		( new Page() )->render();
	}
}
