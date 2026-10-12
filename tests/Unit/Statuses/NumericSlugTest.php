<?php
/**
 * Statuses whose slug is only digits.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Statuses;

use Brain\Monkey\Functions;
use OrderFlowWC\Config\Importer;
use OrderFlowWC\Migration\Orders;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * PHP turns the array key "2024" into the integer 2024. Code that read the key instead of the slug
 * field passed an int to string parameters and failed with a TypeError under strict_types.
 *
 * @covers \OrderFlowWC\Migration\Orders
 * @covers \OrderFlowWC\Config\Importer
 * @covers \OrderFlowWC\Pdf\Documents
 *
 * Separate processes: the test defines wc_get_order_statuses(), which would otherwise leak into
 * Repository tests that rely on the function being absent.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NumericSlugTest extends TestCase {

	/**
	 * In-memory options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Stub options and order lookups. Four orders sit in every status.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->options = array(
			'ofwc_statuses' => array(
				'2024' => array(
					'slug'  => '2024',
					'label' => 'Season 2024',
				),
			),
		);

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 4 ) );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array( 'wc-processing' => 'Processing' ) );
		Functions\when( 'wp_roles' )->alias(
			static function (): object {
				return new class() {
					/**
					 * Role names.
					 *
					 * @return array<string, string>
					 */
					public function get_names(): array {
						return array( 'administrator' => 'Administrator' );
					}
				};
			}
		);
	}

	/**
	 * The deactivation screen counts orders per status.
	 */
	public function test_counts_orders_of_a_numeric_slug(): void {
		$counts = ( new Orders() )->counts();

		$this->assertSame( array( '2024' => 4 ), $counts );
	}

	/**
	 * Replace-mode import must keep a numeric-slug status that holds orders, not crash.
	 */
	public function test_replace_import_keeps_a_numeric_slug_with_orders(): void {
		$doc    = array(
			'format'   => 'order-flow-for-woocommerce',
			'version'  => 1,
			'statuses' => array(
				array(
					'slug'  => 'brand-new',
					'label' => 'Brand new',
				),
			),
		);
		$result = ( new Importer() )->import( $doc, Importer::MODE_REPLACE );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( '2024' => 4 ), $result->kept );
		$this->assertNotNull( ( new Repository() )->get( '2024' ) );
	}
}
