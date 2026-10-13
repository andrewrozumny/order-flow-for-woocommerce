<?php
/**
 * Per-order lock tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\OrderLock;

/**
 * The lock row can be inserted once; a row whose time is up is taken over; only the holder releases it.
 *
 * @covers \OrderFlowWC\Transitions\OrderLock
 */
final class OrderLockTest extends TestCase {

	/**
	 * Fake options table: name => value.
	 *
	 * @var array<string, string>
	 */
	public array $rows = array();

	/**
	 * Install a fake $wpdb that understands the three statements of the lock.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->rows = array();
		$rows       = &$this->rows;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A stand-in for the database object, removed in tear_down().
		$GLOBALS['wpdb'] = new class( $rows ) {
			/**
			 * Options table name.
			 *
			 * @var string
			 */
			public string $options = 'wp_options';

			/**
			 * Rows, shared with the test.
			 *
			 * @var array<string, string>
			 */
			private array $rows;

			/**
			 * Constructor.
			 *
			 * @param array<string, string> $rows Rows.
			 */
			public function __construct( array &$rows ) {
				$this->rows = &$rows;
			}

			/**
			 * Substitute placeholders into the SQL, quoting strings.
			 *
			 * @param string $sql  SQL.
			 * @param mixed  ...$args Values.
			 */
			public function prepare( string $sql, ...$args ): string {
				foreach ( $args as $arg ) {
					$sql = (string) preg_replace_callback(
						'/%([sdi])/',
						static function ( array $m ) use ( $arg ): string {
							return 'i' === $m[1] || 'd' === $m[1] ? (string) $arg : "'" . $arg . "'";
						},
						$sql,
						1
					);
				}
				return $sql;
			}

			/**
			 * Run one of the lock statements.
			 *
			 * @param string $sql SQL.
			 * @return int Affected rows.
			 */
			public function query( string $sql ): int {
				if ( 1 === preg_match( "/^INSERT IGNORE.*VALUES \\('([^']+)', '([^']+)'/", $sql, $m ) ) {
					if ( isset( $this->rows[ $m[1] ] ) ) {
						return 0;
					}
					$this->rows[ $m[1] ] = $m[2];
					return 1;
				}
				if ( 1 === preg_match( "/^DELETE.*option_name = '([^']+)' AND CAST\\(option_value AS UNSIGNED\\) < (\\d+)/", $sql, $m ) ) {
					if ( isset( $this->rows[ $m[1] ] ) && (int) $this->rows[ $m[1] ] < (int) $m[2] ) {
						unset( $this->rows[ $m[1] ] );
						return 1;
					}
					return 0;
				}
				if ( 1 === preg_match( "/^DELETE.*option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
					if ( ( $this->rows[ $m[1] ] ?? null ) === $m[2] ) {
						unset( $this->rows[ $m[1] ] );
						return 1;
					}
				}
				return 0;
			}
		};
	}

	/**
	 * Remove the fake.
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	/**
	 * The second request cannot take a held lock, and gets it after the release.
	 */
	public function test_lock_is_exclusive_until_released(): void {
		$lock  = new OrderLock();
		$token = $lock->acquire( 7 );
		$this->assertNotNull( $token );

		// A second attempt on the same order fails (short wait: the lock does not expire for 30 s).
		$other = new OrderLock( 0.0 );
		$this->assertNull( $other->acquire( 7 ) );
		$this->assertNotNull( $other->acquire( 8 ), 'another order is not affected' );

		$lock->release( 7, (string) $token );
		$this->assertNotNull( $other->acquire( 7 ) );
	}

	/**
	 * Only the holder's own token releases the lock.
	 */
	public function test_wrong_token_does_not_release(): void {
		$lock  = new OrderLock();
		$token = (string) $lock->acquire( 7 );

		$lock->release( 7, $token . '1' );

		$this->assertArrayHasKey( OrderLock::PREFIX . '7', $this->rows );
	}

	/**
	 * A lock whose time is up (a request that died) is taken over.
	 */
	public function test_expired_lock_is_taken_over(): void {
		$this->rows[ OrderLock::PREFIX . '7' ] = '1000';

		$token = ( new OrderLock() )->acquire( 7 );

		$this->assertNotNull( $token );
		$this->assertNotSame( '1000', $this->rows[ OrderLock::PREFIX . '7' ] );
	}
}
