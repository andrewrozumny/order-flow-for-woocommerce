<?php
/**
 * Generator tests.
 *
 * @package OrderFlowWC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- The tests work on a scratch folder with plain PHP file functions.

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Pdf;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\Pdf\Generator;
use OrderFlowWC\Tests\Unit\TestCase;
use Smalot\PdfParser\Parser;

/**
 * Real dompdf output.
 *
 * @covers \OrderFlowWC\Pdf\Generator
 */
final class GeneratorTest extends TestCase {

	/**
	 * Stub the folder helpers: where the system temporary folder and uploads are.
	 *
	 * @param string $temp    System temporary folder.
	 * @param string $uploads Uploads base folder.
	 */
	private function stub_paths( string $temp, string $uploads ): void {
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => $uploads,
				'baseurl' => 'https://example.test/uploads',
			)
		);
		Functions\when( 'get_temp_dir' )->justReturn( $temp . '/' );
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( string $dir ): bool {
				return is_dir( $dir ) || @mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unwritable parent is the point of the test.
			}
		);
		Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $value ): string {
				return rtrim( $value, '/' );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static function ( string $value ): string {
				return rtrim( $value, '/' ) . '/';
			}
		);
	}

	/**
	 * Without any usable temporary folder there is no fatal error: no PDF, and the reason is "temp".
	 */
	public function test_no_usable_temp_folder_is_a_clean_failure(): void {
		$this->stub_paths( '/nonexistent-tmp-a', '/nonexistent-tmp-b' );
		$generator = new Generator();

		$this->assertSame( '', $generator->pdf_from_html( '<html><body><p>x</p></body></html>' ) );
		$this->assertSame( Generator::FAILURE_TEMP, $generator->failure() );
	}

	/**
	 * The temporary folder falls back to uploads when the system one is unusable, and the PDF is made.
	 */
	public function test_temp_folder_falls_back_to_uploads(): void {
		$uploads = sys_get_temp_dir() . '/ofwc-gen-' . bin2hex( random_bytes( 4 ) );
		mkdir( $uploads, 0777, true );
		$this->stub_paths( '/nonexistent-tmp-a', $uploads );

		$pdf = ( new Generator() )->pdf_from_html( '<html><body><p>Fallback OF-9</p></body></html>' );

		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertDirectoryExists( $uploads . '/ofwc-tmp' );
		foreach ( array( 'index.php', '.htaccess' ) as $file ) {
			@unlink( $uploads . '/ofwc-tmp/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Scratch folder.
		}
		@rmdir( $uploads . '/ofwc-tmp' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rmdir_rmdir -- Scratch folder.
		@rmdir( $uploads ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rmdir_rmdir -- Scratch folder.
	}

	/**
	 * The estimate grows with lines and text, and a huge document does not fit a small memory limit.
	 */
	public function test_memory_estimate_refuses_what_cannot_fit(): void {
		$this->assertGreaterThan( Generator::estimate_memory( 10, 0 ), Generator::estimate_memory( 700, 0 ) );
		$this->assertGreaterThan( Generator::estimate_memory( 10, 0 ), Generator::estimate_memory( 10, 400000 ) );

		$old = (string) ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test of the memory estimate.
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 1073741824 );
		try {
			$generator = new Generator();
			$this->assertTrue( $generator->fits_in_memory( 10, 1000 ) );
			$this->assertFalse( $generator->fits_in_memory( 5000, 0 ) );
		} finally {
			ini_set( 'memory_limit', $old ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restore.
		}
	}

	/**
	 * The time limit is only ever raised.
	 */
	public function test_time_limit_is_never_lowered(): void {
		$before = (string) ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '300' ); // phpcs:ignore WordPress.PHP.IniSet.max_execution_time_Disallowed -- Test of the time limit.
		try {
			Generator::raise_limits();
			$this->assertSame( '300', (string) ini_get( 'max_execution_time' ) );
		} finally {
			ini_set( 'max_execution_time', $before ); // phpcs:ignore WordPress.PHP.IniSet.max_execution_time_Disallowed -- Restore.
		}
	}

	/**
	 * HTML becomes a PDF whose text contains the order number.
	 */
	public function test_pdf_from_html_contains_text(): void {
		$this->stub_paths( sys_get_temp_dir(), sys_get_temp_dir() );

		$pdf = ( new Generator() )->pdf_from_html( '<html><body><h1>Approval sheet</h1><p>Order #OF-1042 for Zoe Ünal</p></body></html>' );

		$this->assertStringStartsWith( '%PDF', $pdf );
		$text = ( new Parser() )->parseContent( $pdf )->getText();
		$this->assertStringContainsString( 'OF-1042', $text );
		$this->assertStringContainsString( 'Approval sheet', $text );
	}

	/**
	 * A different copy of dompdf already loaded in the process (another plugin, an old version) is ignored:
	 * the document is built by the bundled, prefixed copy.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_foreign_dompdf_in_process_is_not_used(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a stand-in for another plugin's older dompdf.
		eval( 'namespace Dompdf; class Dompdf { const VERSION = "0.8.0"; public function __construct() { throw new \\RuntimeException( "foreign dompdf used" ); } } class Options { public function __construct() { throw new \\RuntimeException( "foreign options used" ); } }' );
		$this->stub_paths( sys_get_temp_dir(), sys_get_temp_dir() );

		$pdf = ( new Generator() )->pdf_from_html( '<html><body><p>Bundled copy OF-7</p></body></html>' );

		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertStringContainsString( 'OF-7', ( new Parser() )->parseContent( $pdf )->getText() );
		$this->assertSame( '0.8.0', \Dompdf\Dompdf::VERSION );
		$this->assertTrue( class_exists( 'OrderFlowWC\\Vendor\\Dompdf\\Dompdf', false ) );
	}

	/**
	 * Library classes load only for the prefixed namespace and only from the bundled folder.
	 */
	public function test_libraries_loader_answers_only_for_prefixed_namespace(): void {
		$this->assertTrue( \OrderFlowWC\Pdf\Libraries::load() );
		$this->assertFalse( class_exists( 'OrderFlowWC\\Vendor\\Nope\\Missing' ) );
		$loaders = array_filter(
			spl_autoload_functions(),
			static function ( $loader ): bool {
				return is_array( $loader ) && \OrderFlowWC\Pdf\Libraries::class === $loader[0];
			}
		);
		$this->assertCount( 1, $loaders );
	}

	/**
	 * The download name is "{title-slug}-{order number}.pdf".
	 */
	public function test_filename(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Shop' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'sanitize_title' )->alias(
			static function ( string $value ): string {
				return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' );
			}
		);
		Functions\when( 'sanitize_file_name' )->returnArg();

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '1042' );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'Ann' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( 'Lee' );
		$order->shouldReceive( 'get_date_created' )->andReturn( null );
		$order->shouldReceive( 'get_formatted_order_total' )->andReturn( '1' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$status = array(
			'slug'      => 'proof',
			'label'     => 'Proof',
			'pdf_title' => 'Approval sheet',
		);
		$this->assertSame( 'approval-sheet-1042.pdf', ( new Generator() )->filename( $order, $status ) );
	}
}
