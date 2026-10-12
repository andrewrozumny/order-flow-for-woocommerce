<?php
/**
 * Shared fixture: statuses, roles, users and stored rules.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Functions;
use OrderFlowWC\Transitions\Rules;

/**
 * Custom statuses "proof" and "packed"; user 1 is an administrator, 2 a shop manager, 3 a customer.
 */
trait RulesFixture {

	/**
	 * Stub the WordPress functions the rules need and store the given rules.
	 *
	 * @param array<int, array<string, mixed>> $rules Stored rules.
	 */
	protected function stub_rules( array $rules ): void {
		Rules::flush_cache();
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
				return (object) array(
					'ID'    => $id,
					'roles' => $roles[ $id ] ?? array(),
				);
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return strtolower( (string) preg_replace( '/[^a-z0-9_\-*]/i', '', $key ) );
			}
		);
	}

	/**
	 * A stored rule.
	 *
	 * @param string   $from    From status or "*".
	 * @param string   $to      To status.
	 * @param string[] $roles   Roles.
	 * @param bool     $comment Whether a comment is required.
	 * @return array<string, mixed>
	 */
	protected function rule( string $from, string $to, array $roles, bool $comment = false ): array {
		return array(
			'from'            => $from,
			'to'              => $to,
			'roles'           => $roles,
			'require_comment' => $comment,
		);
	}
}
