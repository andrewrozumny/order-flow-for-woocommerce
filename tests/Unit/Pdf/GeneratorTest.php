<?php
/**
 * Generator tests.
 *
 * @package OrderFlowWC
 */

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
	 * HTML becomes a PDF whose text contains the order number.
	 */
	public function test_pdf_from_html_contains_text(): void {
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.test/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->alias( 'is_dir' );
		Functions\when( 'trailingslashit' )->alias(
			static function ( string $value ): string {
				return rtrim( $value, '/' ) . '/';
			}
		);

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
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'https://example.test/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->alias( 'is_dir' );
		Functions\when( 'trailingslashit' )->alias(
			static function ( string $value ): string {
				return rtrim( $value, '/' ) . '/';
			}
		);

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
