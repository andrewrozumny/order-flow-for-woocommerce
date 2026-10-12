<?php
/**
 * Transition rules tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Functions;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Rules;

/**
 * Permission matrix.
 *
 * @covers \OrderFlowWC\Transitions\Rules
 */
final class RulesTest extends TestCase {

	/**
	 * Custom statuses "proof" and "packed"; user 1 is an administrator, 2 a shop manager, 3 a customer.
	 *
	 * @param array<int, array<string, mixed>> $rules Stored rules.
	 */
	private function with_rules( array $rules ): Rules {
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) use ( $rules ) {
				if ( 'ofwc_statuses' === $name ) {
					return array(
						'proof'  => array(
							'slug'  => 'proof',
							'label' => 'Awaiting proof',
						),
						'packed' => array(
							'slug'  => 'packed',
							'label' => 'Packed',
						),
					);
				}
				return $rules;
			}
		);
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-pending'    => 'Pending payment',
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
				'wc-proof'      => 'Awaiting proof',
				'wc-packed'     => 'Packed',
			)
		);
		Functions\when( 'user_can' )->alias(
			static function ( $user, string $cap ): bool {
				return 1 === $user && 'manage_options' === $cap;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ): object {
				$roles = array(
					1 => array( 'administrator' ),
					2 => array( 'shop_manager' ),
					3 => array( 'customer' ),
				);
				return (object) array( 'roles' => $roles[ $id ] ?? array() );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return strtolower( (string) preg_replace( '/[^a-z0-9_\-*]/i', '', $key ) );
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
					return array(
						'administrator' => 'Administrator',
						'shop_manager'  => 'Shop manager',
						'customer'      => 'Customer',
					);
				}
			}
		);
		return new Rules();
	}

	/**
	 * Shop manager may make the one listed transition only; the reverse is denied.
	 */
	public function test_shop_manager_is_allowed_only_the_listed_transition(): void {
		$rules = $this->with_rules(
			array(
				array(
					'from'            => 'processing',
					'to'              => 'proof',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => false,
				),
			)
		);

		$this->assertTrue( $rules->is_allowed( 'processing', 'proof', 2 ) );
		$this->assertFalse( $rules->is_allowed( 'proof', 'processing', 2 ) );
		$this->assertFalse( $rules->is_allowed( 'processing', 'packed', 2 ) );
		$this->assertFalse( $rules->is_allowed( 'processing', 'proof', 3 ), 'a role not on the rule is denied' );
	}

	/**
	 * Administrators may do everything.
	 */
	public function test_administrator_is_always_allowed(): void {
		$rules = $this->with_rules( array() );

		$this->assertTrue( $rules->is_allowed( 'processing', 'proof', 1 ) );
		$this->assertTrue( $rules->is_allowed( 'proof', 'packed', 1 ) );
	}

	/**
	 * Core to core is not restricted, even without any rule.
	 */
	public function test_core_to_core_is_unrestricted(): void {
		$rules = $this->with_rules( array() );

		$this->assertTrue( $rules->is_allowed( 'pending', 'completed', 3 ) );
		$this->assertFalse( $rules->is_allowed( 'pending', 'pending', 3 ), 'same status is no transition' );
	}

	/**
	 * Wildcard "from" matches every source status.
	 */
	public function test_wildcard_from_matches_any_status(): void {
		$rules = $this->with_rules(
			array(
				array(
					'from'            => '*',
					'to'              => 'packed',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => false,
				),
			)
		);

		$this->assertTrue( $rules->is_allowed( 'processing', 'packed', 2 ) );
		$this->assertTrue( $rules->is_allowed( 'proof', 'packed', 2 ) );
		$this->assertFalse( $rules->is_allowed( 'packed', 'proof', 2 ) );
	}

	/**
	 * Require comment follows the matching rule.
	 */
	public function test_requires_comment_follows_the_rule(): void {
		$rules = $this->with_rules(
			array(
				array(
					'from'            => 'proof',
					'to'              => 'processing',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => true,
				),
				array(
					'from'            => '*',
					'to'              => 'packed',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => false,
				),
			)
		);

		$this->assertTrue( $rules->requires_comment( 'proof', 'processing' ) );
		$this->assertFalse( $rules->requires_comment( 'proof', 'packed' ) );
		$this->assertFalse( $rules->requires_comment( 'processing', 'proof' ) );
	}

	/**
	 * Allowed targets list only what the user may reach.
	 */
	public function test_allowed_targets_for_user(): void {
		$rules = $this->with_rules(
			array(
				array(
					'from'            => 'processing',
					'to'              => 'proof',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => false,
				),
			)
		);

		$this->assertSame( array( 'pending', 'completed', 'proof' ), $rules->allowed_targets( 'processing', 2 ) );
		$this->assertSame( array(), $rules->allowed_targets( 'proof', 2 ), 'leaving a custom status needs a rule too' );
		$this->assertTrue( $rules->can_reach( 'proof', 2 ) );
		$this->assertFalse( $rules->can_reach( 'packed', 2 ) );
	}

	/**
	 * Sanitizing drops unknown statuses and roles and merges duplicates.
	 */
	public function test_sanitize_drops_invalid_and_merges_duplicates(): void {
		$rules = $this->with_rules( array() );

		$clean = $rules->sanitize(
			array(
				array(
					'from'  => 'wc-processing',
					'to'    => 'proof',
					'roles' => array( 'shop_manager', 'ghost' ),
				),
				array(
					'from'            => 'processing',
					'to'              => 'proof',
					'roles'           => array( 'customer' ),
					'require_comment' => '1',
				),
				array(
					'from'  => 'nope',
					'to'    => 'proof',
					'roles' => array( 'shop_manager' ),
				),
				array(
					'from' => '*',
					'to'   => '*',
				),
				array(
					'from' => 'proof',
					'to'   => 'proof',
				),
				'garbage',
			)
		);

		$this->assertSame(
			array(
				array(
					'from'            => 'processing',
					'to'              => 'proof',
					'roles'           => array( 'shop_manager', 'customer' ),
					'require_comment' => true,
				),
			),
			$clean
		);
	}
}
