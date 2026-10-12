<?php
/**
 * Placeholders tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\Placeholders;

/**
 * Placeholder replacement.
 *
 * @covers \OrderFlowWC\Placeholders
 */
final class PlaceholdersTest extends TestCase {

	/**
	 * Build an order double.
	 *
	 * @param string $first First name.
	 */
	private function order( string $first = 'Ann' ): \WC_Order {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '1042' );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( $first );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( 'Lee' );
		$order->shouldReceive( 'get_date_created' )->andReturn( new \DateTimeImmutable( '2026-10-12' ) );
		$order->shouldReceive( 'get_formatted_order_total' )->andReturn( '<span>&#36;25.00</span>' );

		Functions\when( 'wc_format_datetime' )->justReturn( 'October 12, 2026' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Shop &amp; Co' );
		Functions\when( 'wp_specialchars_decode' )->alias( 'htmlspecialchars_decode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		return $order;
	}

	/**
	 * Every placeholder is replaced.
	 */
	public function test_replaces_all_placeholders(): void {
		$text = 'Hi {customer_name}, order {order_number} is {status} ({order_date}, {order_total}) at {site_title}. {note}';

		$this->assertSame(
			'Hi Ann Lee, order 1042 is Packed (October 12, 2026, $25.00) at Shop & Co. See you.',
			Placeholders::replace( $text, $this->order(), 'Packed', 'See you.' )
		);
	}

	/**
	 * Unknown tags are left alone and the note defaults to empty.
	 */
	public function test_unknown_tag_untouched(): void {
		$this->assertSame( 'x {unknown} y', Placeholders::replace( 'x {unknown} y{note}', $this->order(), 'S' ) );
	}

	/**
	 * In HTML mode values are escaped.
	 */
	public function test_html_mode_escapes_values(): void {
		$result = Placeholders::replace( '<p>{customer_name}</p>', $this->order( '<b>Ann</b>' ), 'S', '', true );
		$this->assertSame( '<p>&lt;b&gt;Ann&lt;/b&gt; Lee</p>', $result );
	}

	/**
	 * The tag list covers the documented placeholders.
	 */
	public function test_tags_list(): void {
		$this->assertSame(
			array( '{order_number}', '{status}', '{customer_name}', '{note}', '{order_date}', '{site_title}', '{order_total}' ),
			array_keys( Placeholders::tags() )
		);
	}
}
