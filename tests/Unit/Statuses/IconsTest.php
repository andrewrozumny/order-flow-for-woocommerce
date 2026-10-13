<?php
/**
 * Icons tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Statuses;

use OrderFlowWC\Statuses\Icons;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * The plain list of names and the list with labels never drift apart.
 *
 * @covers \OrderFlowWC\Statuses\Icons
 */
final class IconsTest extends TestCase {

	/**
	 * Every icon with a label is a known name, and unknown names are refused.
	 */
	public function test_names_and_labels_agree(): void {
		$all = Icons::all();

		$this->assertGreaterThan( 30, count( $all ) );
		foreach ( array_keys( $all ) as $icon ) {
			$this->assertTrue( Icons::exists( $icon ), $icon );
		}
		$this->assertFalse( Icons::exists( 'dashicons-nothing' ) );
		$this->assertFalse( Icons::exists( '' ) );
	}

	/**
	 * Every known name has a label.
	 */
	public function test_every_name_has_a_label(): void {
		$reflection = new \ReflectionClassConstant( Icons::class, 'NAMES' );

		$this->assertSame( $reflection->getValue(), array_keys( Icons::all() ) );
	}
}
