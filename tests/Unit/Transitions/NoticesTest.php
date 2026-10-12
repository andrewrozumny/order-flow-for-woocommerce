<?php
/**
 * Notices tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Functions;
use OrderFlowWC\Admin\Page;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Notices;

/**
 * The one notice queue, kept in user meta.
 *
 * @covers \OrderFlowWC\Transitions\Notices
 * @covers \OrderFlowWC\Admin\Page::flash
 */
final class NoticesTest extends TestCase {

	/**
	 * User meta store, by user id.
	 *
	 * @var array<int, mixed>
	 */
	private array $meta = array();

	/**
	 * In-memory user meta for user 7.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->meta = array();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->alias( fn ( int $user ) => $this->meta[ $user ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user, string $key, $value ): bool {
				unset( $key );
				$this->meta[ $user ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user ): bool {
				unset( $this->meta[ $user ] );
				return true;
			}
		);
	}

	/**
	 * Queued notices are shown once and removed.
	 */
	public function test_notices_render_once(): void {
		Notices::add( 'success', 'Saved.' );
		Notices::add( 'error', 'Not <b>saved</b>.' );

		ob_start();
		Notices::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Not &lt;b&gt;saved&lt;/b&gt;.', $html );

		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The page flash goes through the same queue.
	 */
	public function test_page_flash_uses_the_same_queue(): void {
		Page::flash( 'success', 'Status saved.' );
		Notices::add( 'info', 'Deleting.' );

		ob_start();
		Notices::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Status saved.', $html );
		$this->assertStringContainsString( 'notice-info', $html );
	}

	/**
	 * Nobody logged in, nothing queued.
	 */
	public function test_no_user_no_queue(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Notices::add( 'success', 'x' );
		$this->assertSame( array(), $this->meta );

		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Unknown types fall back to success; damaged meta is ignored.
	 */
	public function test_unknown_type_and_damaged_meta(): void {
		$this->meta[7] = array( 'junk', array( 'type' => 'error' ) );
		Notices::add( 'weird', 'Hello' );

		ob_start();
		Notices::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringNotContainsString( 'junk', $html );
	}
}
