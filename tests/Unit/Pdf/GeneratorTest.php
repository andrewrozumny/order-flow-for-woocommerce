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
