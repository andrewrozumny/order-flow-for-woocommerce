<?php
/**
 * Rules cache tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Functions;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Rules;

/**
 * The rules are read and indexed once per request and again after they change.
 *
 * @covers \OrderFlowWC\Transitions\Rules
 */
final class RulesCacheTest extends TestCase {

	use RulesFixture;

	/**
	 * Count the reads of the two options.
	 *
	 * @param int                              $reads Counter, by reference.
	 * @param array<int, array<string, mixed>> $rules Stored rules, by reference.
	 */
	private function count_reads( int &$reads, array &$rules ): void {
		$this->stub_rules( $rules );
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) use ( &$reads, &$rules ) {
				++$reads;
				if ( 'ofwc_statuses' === $name ) {
					return array(
						'proof' => array(
							'slug'  => 'proof',
							'label' => 'Awaiting proof',
						),
					);
				}
				return $rules;
			}
		);
	}

	/**
	 * A bulk-action menu with many statuses reads the options once, not once per pair of statuses.
	 */
	public function test_checking_many_pairs_reads_the_option_once(): void {
		$reads  = 0;
		$stored = array( $this->rule( 'processing', 'proof', array( 'shop_manager' ) ) );
		$this->count_reads( $reads, $stored );
		$rules = new Rules();

		for ( $i = 0; $i < 50; $i++ ) {
			$rules->is_allowed( 'processing', 'proof', 2 );
			$rules->can_reach( 'proof', 2 );
			$rules->requires_comment( 'processing', 'proof' );
		}

		$this->assertLessThanOrEqual( 2, $reads, 'one read of the rules, one of the statuses' );
	}

	/**
	 * Saving through the class drops the cache.
	 */
	public function test_save_all_flushes_the_cache(): void {
		$stored = array();
		$reads  = 0;
		$this->count_reads( $reads, $stored );
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$stored ): bool {
				unset( $name );
				$stored = $value;
				return true;
			}
		);
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				/**
				 * Role names.
				 *
				 * @return array<string, string>
				 */
				public function get_names(): array {
					return array( 'shop_manager' => 'Shop manager' );
				}
			}
		);
		$rules = new Rules();
		$this->assertFalse( $rules->is_allowed( 'processing', 'proof', 2 ) );

		$rules->save_all( array( $this->rule( 'processing', 'proof', array( 'shop_manager' ) ) ) );

		$this->assertTrue( $rules->is_allowed( 'processing', 'proof', 2 ), 'the new rule is seen at once' );
	}

	/**
	 * A write from anywhere (import, deleting a status) fires the option hooks, and those drop the cache.
	 */
	public function test_option_hooks_flush_the_cache(): void {
		$stored = array();
		$reads  = 0;
		$this->count_reads( $reads, $stored );
		$rules = new Rules();
		$this->assertFalse( $rules->is_allowed( 'processing', 'proof', 2 ) );

		Rules::watch();
		$stored = array( $this->rule( 'processing', 'proof', array( 'shop_manager' ) ) );
		$this->assertNotFalse( has_action( 'update_option_ofwc_transitions', array( Rules::class, 'flush_cache' ) ) );
		Rules::flush_cache(); // What the hook calls.

		$this->assertTrue( $rules->is_allowed( 'processing', 'proof', 2 ) );
		$this->assertNotFalse( has_action( 'add_option_ofwc_statuses', array( Rules::class, 'flush_cache' ) ) );
		$this->assertNotFalse( has_action( 'delete_option_ofwc_transitions', array( Rules::class, 'flush_cache' ) ) );
	}
}
