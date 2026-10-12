<?php
/**
 * Temporary PDF files for email attachments.
 *
 * @package OrderFlowWC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- The test works on a scratch folder with plain PHP file functions.

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Pdf;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\Pdf\Attachments;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * The file lives outside the web root, is deleted when the mail is done, falls back to a protected
 * uploads folder, and a missing folder never breaks the email.
 *
 * @covers \OrderFlowWC\Pdf\Attachments
 */
final class AttachmentsTest extends TestCase {

	/**
	 * Scratch area for one test.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Notes added to the order.
	 *
	 * @var string[]
	 */
	private array $notes = array();

	/**
	 * Fake system temp dir and uploads dir under one scratch folder.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/ofwc-att-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/sys', 0777, true );
		mkdir( $this->root . '/uploads', 0777, true );
		$this->notes = array();
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $value ): string {
				return rtrim( $value, '/' );
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( string $dir ): bool {
				return is_dir( $dir ) || @mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unwritable parent is the point of some tests.
			}
		);
		Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'wp_generate_password' )->alias(
			static function (): string {
				return bin2hex( random_bytes( 8 ) );
			}
		);
		Functions\when( 'get_temp_dir' )->justReturn( $this->root . '/sys/' );
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $this->root . '/uploads',
				'baseurl' => 'https://example.test/uploads',
			)
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
	}

	/**
	 * Remove the scratch area.
	 */
	protected function tear_down(): void {
		$this->remove_tree( $this->root );
		parent::tear_down();
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $path Path.
	 */
	private function remove_tree( string $path ): void {
		if ( is_dir( $path ) ) {
			@chmod( $path, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup.
			foreach ( (array) scandir( $path ) as $item ) {
				if ( '.' !== $item && '..' !== $item ) {
					$this->remove_tree( $path . '/' . $item );
				}
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Attachments with a fake renderer and a repository that knows one PDF status.
	 *
	 * @param string $pdf PDF body the renderer returns.
	 */
	private function attachments( string $pdf = '%PDF-1.4 test' ): Attachments {
		Repository::flush_cache();
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) {
				return 'ofwc_statuses' === $name ? array(
					'proof' => array(
						'slug'        => 'proof',
						'label'       => 'Proof',
						'pdf_enabled' => true,
					),
				) : $fallback;
			}
		);
		$repo = new Repository();
		return new Attachments(
			$repo,
			static function () use ( $pdf ): array {
				return array( $pdf, '' === $pdf ? '' : 'proof-1001.pdf' );
			}
		);
	}

	/**
	 * Order double that records notes.
	 *
	 * @return \WC_Order
	 */
	private function order() {
		$order = Mockery::mock( 'WC_Order' );
		$order->allows( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;
				return 1;
			}
		);
		return $order;
	}

	/**
	 * The PDF goes to the system temp folder, not into uploads, and carries a clean name.
	 */
	public function test_file_is_written_outside_the_web_root(): void {
		$paths = $this->attachments()->filter( array(), $this->order(), 'proof' );

		$this->assertCount( 1, $paths );
		$this->assertStringStartsWith( $this->root . '/sys/ofwc-tmp/', $paths[0] );
		$this->assertSame( 'proof-1001.pdf', basename( $paths[0] ) );
		$this->assertStringStartsWith( '%PDF', (string) file_get_contents( $paths[0] ) );
		$this->assertDirectoryDoesNotExist( $this->root . '/uploads/ofwc-tmp' );
		$this->assertFileExists( $this->root . '/sys/ofwc-tmp/index.php' );
	}

	/**
	 * The htaccess denies access for Apache 2.4 and 2.2 alike.
	 */
	public function test_deny_rule_has_both_syntaxes(): void {
		$this->attachments()->filter( array(), $this->order(), 'proof' );

		$rules = (string) file_get_contents( $this->root . '/sys/ofwc-tmp/.htaccess' );
		$this->assertStringContainsString( '<IfModule mod_authz_core.c>', $rules );
		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( '<IfModule !mod_authz_core.c>', $rules );
		$this->assertStringContainsString( 'Deny from all', $rules );
	}

	/**
	 * The file is deleted when wp_mail reports success or failure, not before and not at shutdown.
	 */
	public function test_file_is_deleted_when_mail_is_done(): void {
		$attachments = $this->attachments();
		$ok          = $attachments->filter( array(), $this->order(), 'proof' )[0];
		$failed      = $attachments->filter( array(), $this->order(), 'proof' )[0];
		$this->assertFileExists( $ok );
		$this->assertFileExists( $failed );

		$attachments->on_mail_succeeded( array( 'attachments' => array( $ok ) ) );
		$this->assertFileDoesNotExist( $ok );
		$this->assertDirectoryDoesNotExist( dirname( $ok ) );
		$this->assertFileExists( $failed, 'a file of another mail must stay' );

		$error = new \WP_Error( 'wp_mail_failed', 'x', array( 'attachments' => array( $failed ) ) );
		$attachments->on_mail_failed( $error );
		$this->assertFileDoesNotExist( $failed );
	}

	/**
	 * Files that are not ours are never deleted.
	 */
	public function test_foreign_files_are_left_alone(): void {
		$foreign = $this->root . '/other/invoice.pdf';
		mkdir( dirname( $foreign ), 0777, true );
		file_put_contents( $foreign, 'x' );

		$this->attachments()->on_mail_succeeded( array( 'attachments' => array( $foreign, '/etc/hosts' ) ) );

		$this->assertFileExists( $foreign );
	}

	/**
	 * Daily cleanup removes what is older than a day in both folders and keeps fresh files.
	 */
	public function test_cron_cleanup_removes_old_files(): void {
		$old   = $this->root . '/sys/ofwc-tmp/aaaa/old.pdf';
		$older = $this->root . '/uploads/ofwc-tmp/bbbb/older.pdf';
		$fresh = $this->root . '/sys/ofwc-tmp/cccc/fresh.pdf';
		foreach ( array( $old, $older, $fresh ) as $file ) {
			mkdir( dirname( $file ), 0777, true );
			file_put_contents( $file, 'x' );
		}
		touch( $old, time() - 2 * DAY_IN_SECONDS );
		touch( $older, time() - 3 * DAY_IN_SECONDS );

		$this->attachments()->purge_old();

		$this->assertFileDoesNotExist( $old );
		$this->assertFileDoesNotExist( $older );
		$this->assertFileExists( $fresh );
	}

	/**
	 * When the system temp folder is not writable the protected uploads folder is used.
	 */
	public function test_falls_back_to_uploads(): void {
		chmod( $this->root . '/sys', 0555 );
		if ( is_writable( $this->root . '/sys' ) ) {
			$this->markTestSkipped( 'Running as a user that ignores permissions.' );
		}

		$paths = $this->attachments()->filter( array(), $this->order(), 'proof' );

		$this->assertCount( 1, $paths );
		$this->assertStringStartsWith( $this->root . '/uploads/ofwc-tmp/', $paths[0] );
		$this->assertFileExists( $this->root . '/uploads/ofwc-tmp/.htaccess' );
		$this->assertFileExists( $this->root . '/uploads/ofwc-tmp/index.php' );
	}

	/**
	 * No writable folder at all: the mail goes without the PDF and the order gets a note, no PHP error.
	 */
	public function test_no_folder_means_no_attachment_and_a_note(): void {
		chmod( $this->root . '/sys', 0555 );
		chmod( $this->root . '/uploads', 0555 );
		if ( is_writable( $this->root . '/sys' ) ) {
			$this->markTestSkipped( 'Running as a user that ignores permissions.' );
		}

		$paths = $this->attachments()->filter( array( '/keep.pdf' ), $this->order(), 'proof' );

		$this->assertSame( array( '/keep.pdf' ), $paths );
		$this->assertCount( 1, $this->notes );
		$this->assertStringContainsString( 'without the PDF', $this->notes[0] );
		$this->assertStringContainsString( 'No writable temporary folder', $this->notes[0] );
	}

	/**
	 * A renderer that returns nothing also leaves a note.
	 */
	public function test_failed_render_leaves_a_note(): void {
		$paths = $this->attachments( '' )->filter( array(), $this->order(), 'proof' );

		$this->assertSame( array(), $paths );
		$this->assertStringContainsString( 'could not be created', $this->notes[0] );
	}
}
