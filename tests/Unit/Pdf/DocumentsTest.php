<?php
/**
 * Documents tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Pdf;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\Pdf\Documents;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * Document availability and the download permission rule.
 *
 * @covers \OrderFlowWC\Pdf\Documents
 */
final class DocumentsTest extends TestCase {

	/**
	 * Store statuses: proof and sheet have PDFs, plain does not.
	 */
	private function stored(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'proof' => array(
					'slug'        => 'proof',
					'label'       => 'Proof',
					'pdf_enabled' => true,
					'position'    => 1,
				),
				'sheet' => array(
					'slug'        => 'sheet',
					'label'       => 'Sheet',
					'pdf_enabled' => true,
					'position'    => 2,
				),
				'plain' => array(
					'slug'     => 'plain',
					'label'    => 'Plain',
					'position' => 3,
				),
			)
		);
	}

	/**
	 * Order double.
	 *
	 * @param string $status  Current status.
	 * @param mixed  $history Value of the _ofwc_history meta.
	 */
	private function order( string $status, $history = '' ): \WC_Order {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_meta' )->with( '_ofwc_history' )->andReturn( $history );
		return $order;
	}

	/**
	 * The current status counts.
	 */
	public function test_current_status_is_available(): void {
		$this->stored();
		$this->assertSame( array( 'proof' ), ( new Documents() )->slugs( $this->order( 'proof' ) ) );
	}

	/**
	 * Statuses from the history count, with or without wc- prefix, in status order.
	 */
	public function test_history_adds_documents(): void {
		$this->stored();
		$history = array(
			array(
				'from' => 'wc-sheet',
				'to'   => 'processing',
			),
			array(
				'from' => 'processing',
				'to'   => 'proof',
			),
			'garbage',
		);
		$this->assertSame( array( 'proof', 'sheet' ), ( new Documents() )->slugs( $this->order( 'completed', $history ) ) );
	}

	/**
	 * Statuses without PDF are never offered, and neither are unrelated ones.
	 */
	public function test_pdf_disabled_and_unrelated_excluded(): void {
		$this->stored();
		$docs = new Documents();
		$this->assertSame( array(), $docs->slugs( $this->order( 'plain' ) ) );
		$this->assertSame( array(), $docs->slugs( $this->order( 'processing', 'not-an-array' ) ) );
	}

	/**
	 * Permission matrix.
	 */
	public function test_may_download(): void {
		$this->assertTrue( Documents::may_download( true, 5, 1, false ), 'staff' );
		$this->assertTrue( Documents::may_download( false, 5, 5, true ), 'owner, visible status' );
		$this->assertFalse( Documents::may_download( false, 5, 5, false ), 'owner, hidden status' );
		$this->assertFalse( Documents::may_download( false, 5, 6, true ), 'other customer' );
		$this->assertFalse( Documents::may_download( false, 0, 0, true ), 'guest order, logged out' );
	}

	/**
	 * Nonce action is bound to order and status.
	 */
	public function test_nonce_action(): void {
		$this->assertSame( 'ofwc_pdf_12_proof', Documents::nonce_action( 12, 'proof' ) );
	}
}
