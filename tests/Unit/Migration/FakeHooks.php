<?php
/**
 * A working hook registry for tests: Brain Monkey records hooks but never runs them.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Migration;

use Brain\Monkey\Functions;

/**
 * Backs add_action/add_filter/remove_*, has_*, do_action and apply_filters with a real registry.
 */
trait FakeHooks {

	/**
	 * Registered callbacks: tag => priority => key => callable.
	 *
	 * @var array<string, array<int, array<string, callable>>>
	 */
	private array $hooks = array();

	/**
	 * Identity of a callback.
	 *
	 * @param mixed $callback Callback.
	 */
	private static function hook_key( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_object( $callback ) ) {
			return 'o' . spl_object_id( $callback );
		}
		$parts = array();
		foreach ( (array) $callback as $part ) {
			$parts[] = is_object( $part ) ? 'o' . spl_object_id( $part ) : (string) $part;
		}
		return implode( '::', $parts );
	}

	/**
	 * Install the registry.
	 */
	private function install_hooks(): void {
		$add    = function ( string $tag, $callback, int $priority = 10 ): bool {
			$this->hooks[ $tag ][ $priority ][ self::hook_key( $callback ) ] = $callback;
			return true;
		};
		$remove = function ( string $tag, $callback, int $priority = 10 ): bool {
			$key = self::hook_key( $callback );
			if ( isset( $this->hooks[ $tag ][ $priority ][ $key ] ) ) {
				unset( $this->hooks[ $tag ][ $priority ][ $key ] );
				return true;
			}
			return false;
		};
		$has    = function ( string $tag, $callback = false ) {
			if ( false === $callback ) {
				return array() !== array_filter( $this->hooks[ $tag ] ?? array() );
			}
			$key = self::hook_key( $callback );
			foreach ( $this->hooks[ $tag ] ?? array() as $priority => $callbacks ) {
				if ( isset( $callbacks[ $key ] ) ) {
					return $priority;
				}
			}
			return false;
		};
		Functions\when( 'add_action' )->alias( $add );
		Functions\when( 'add_filter' )->alias( $add );
		Functions\when( 'remove_action' )->alias( $remove );
		Functions\when( 'remove_filter' )->alias( $remove );
		Functions\when( 'has_action' )->alias( $has );
		Functions\when( 'has_filter' )->alias( $has );
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value, ...$args ) {
				$by_priority = $this->hooks[ $tag ] ?? array();
				ksort( $by_priority );
				foreach ( $by_priority as $callbacks ) {
					foreach ( $callbacks as $callback ) {
						$value = '__return_false' === $callback ? false : call_user_func( $callback, $value, ...$args );
					}
				}
				return $value;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ): void {
				$by_priority = $this->hooks[ $tag ] ?? array();
				ksort( $by_priority );
				foreach ( $by_priority as $callbacks ) {
					foreach ( $callbacks as $callback ) {
						call_user_func( $callback, ...$args );
					}
				}
			}
		);
		Functions\when( '__return_false' )->justReturn( false );
	}
}
