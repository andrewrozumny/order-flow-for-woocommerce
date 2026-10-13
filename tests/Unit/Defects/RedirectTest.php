<?php
/**
 * Defect found by the destructive run: an unknown, very long slug was copied into the error redirect.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Defects;

use Brain\Monkey\Functions;
use OrderFlowWC\Admin\DocumentsTab;
use OrderFlowWC\Admin\StatusesTab;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * The status form and the delete form never put a value of the request into the redirect URL.
 *
 * @covers \OrderFlowWC\Admin\StatusesTab
 */
final class RedirectTest extends TestCase {

	/**
	 * URL of the redirect the handler issued.
	 *
	 * @var string
	 */
	private string $redirect = '';

	/**
	 * Stub the request plumbing: a user who may configure, a redirect that stops the handler.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->redirect = '';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) )
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( array() );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ): void {
				$this->redirect = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);
	}

	/**
	 * Run a handler until it redirects.
	 *
	 * @param callable $handler Handler.
	 */
	private function run_until_redirect( callable $handler ): void {
		try {
			$handler();
			$this->fail( 'The handler did not redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
	}

	/**
	 * A 100 KB `editing` value is not copied into the redirect.
	 */
	public function test_save_with_huge_editing_value_does_not_echo_it(): void {
		$_POST = array(
			'editing' => str_repeat( 'a', 102400 ),
			'label'   => 'Whatever',
		);
		$this->run_until_redirect( array( new StatusesTab(), 'handle_save' ) );

		$this->assertLessThan( 300, strlen( $this->redirect ) );
		$this->assertStringNotContainsString( 'aaaaaaaa', $this->redirect );
		$_POST = array();
	}

	/**
	 * A 100 KB `status` value is not copied into the redirect of the delete form.
	 */
	public function test_delete_with_huge_status_value_does_not_echo_it(): void {
		$_POST = array(
			'status' => str_repeat( 'a', 102400 ),
			'target' => 'processing',
		);
		$this->run_until_redirect( array( new StatusesTab(), 'handle_delete' ) );

		$this->assertLessThan( 300, strlen( $this->redirect ) );
		$this->assertStringNotContainsString( 'aaaaaaaa', $this->redirect );
		$_POST = array();
	}

	/**
	 * The store details text above the limit is refused with a message and nothing is stored.
	 */
	public function test_huge_store_details_are_refused(): void {
		$writes = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $name ) use ( &$writes ): bool {
				$writes[] = $name;
				return true;
			}
		);
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );
		Functions\when( 'absint' )->alias( 'abs' );
		$_POST = array( 'store_details' => str_repeat( 'D', 100001 ) );

		$this->run_until_redirect( array( new DocumentsTab(), 'handle_save' ) );

		$this->assertSame( array(), $writes );
		$this->assertStringContainsString( 'tab=documents', $this->redirect );
		$_POST = array();
	}
}
