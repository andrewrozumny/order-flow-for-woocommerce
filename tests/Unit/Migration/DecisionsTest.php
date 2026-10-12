<?php
/**
 * Deactivation decision tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Migration;

use OrderFlowWC\Migration\Decisions;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * Pure logic behind the deactivation screen and the restore.
 *
 * @covers \OrderFlowWC\Migration\Decisions
 */
final class DecisionsTest extends TestCase {

	/**
	 * The screen appears only when orders sit in custom statuses.
	 */
	public function test_needs_screen(): void {
		$this->assertFalse( Decisions::needs_screen( array() ) );
		$this->assertFalse( Decisions::needs_screen( array( 'proof' => 0 ) ) );
		$this->assertTrue(
			Decisions::needs_screen(
				array(
					'proof'    => 0,
					'printing' => 2,
				)
			)
		);
		$this->assertSame(
			5,
			Decisions::total(
				array(
					'a' => 2,
					'b' => 3,
				)
			)
		);
	}

	/**
	 * Unknown choices fall back to the non-destructive option.
	 */
	public function test_choice(): void {
		$this->assertSame( 'move', Decisions::choice( 'move' ) );
		$this->assertSame( 'keep', Decisions::choice( 'keep' ) );
		$this->assertSame( 'keep', Decisions::choice( 'delete-everything' ) );
		$this->assertSame( 'keep', Decisions::choice( null ) );
	}

	/**
	 * Restore only into statuses that still exist.
	 */
	public function test_restore_target(): void {
		$known = array( 'proof', 'printing' );
		$this->assertSame( 'proof', Decisions::restore_target( 'proof', $known ) );
		$this->assertSame( 'proof', Decisions::restore_target( ' wc-Proof ', $known ) );
		$this->assertNull( Decisions::restore_target( 'gone', $known ) );
		$this->assertNull( Decisions::restore_target( '', $known ) );
		$this->assertNull( Decisions::restore_target( array( 'proof' ), $known ) );
		$this->assertNull( Decisions::restore_target( 'proof', array() ) );
	}
}
