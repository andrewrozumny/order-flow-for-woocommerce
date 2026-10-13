<?php
/**
 * Defects found by the destructive run: comments and stored text without limits.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Defects;

use Brain\Monkey\Functions;
use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Service;

/**
 * Each test fails on 0.1.0 and passes once the matching fix is in.
 *
 * @covers \OrderFlowWC\Transitions\Service
 * @covers \OrderFlowWC\Statuses\Repository
 *
 * Separate processes: the test defines wc_get_order_statuses(), which would otherwise leak into
 * Repository tests that rely on the function being absent.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CommentLimitsTest extends TestCase {

	/**
	 * Statuses, one rule proof to processing that needs a comment.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				if ( 'ofwc_statuses' === $name ) {
					return array(
						'proof' => array(
							'slug'  => 'proof',
							'label' => 'Awaiting proof',
						),
					);
				}
				return array(
					array(
						'from'            => 'proof',
						'to'              => 'processing',
						'roles'           => array( 'shop_manager' ),
						'require_comment' => true,
					),
				);
			}
		);
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-processing' => 'Processing',
				'wc-proof'      => 'Awaiting proof',
			)
		);
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'roles' => array( 'shop_manager' ) ) );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Context::clear();
	}

	/**
	 * Data provider: comments that look empty to a person.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invisible_comments(): array {
		return array(
			'non-breaking space' => array( "\u{00A0}" ),
			'zero-width space'   => array( "\u{200B}" ),
			'zero-width joiner'  => array( "\u{200D}" ),
			'byte order mark'    => array( "\u{FEFF}" ),
			'ideographic space'  => array( "\u{3000}" ),
			'mixed blanks'       => array( " \t\u{00A0}\u{200B}\n" ),
		);
	}

	/**
	 * A comment made only of invisible characters does not satisfy "comment required".
	 *
	 * @dataProvider invisible_comments
	 *
	 * @param string $comment Comment text.
	 */
	public function test_invisible_comment_does_not_satisfy_a_required_comment( string $comment ): void {
		$result = ( new Service() )->check( 'proof', 'processing', $comment, 2 );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * A very long comment is refused (or cut) before it reaches the history, which lives in one meta value.
	 */
	public function test_huge_comment_is_refused(): void {
		$order = \Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_status' )->andReturn( 'proof' );
		$stored = null;
		$order->shouldReceive( 'update_status' )->andReturnUsing(
			static function () use ( &$stored ): bool {
				$stored = Context::comment();
				return true;
			}
		);

		$result = ( new Service() )->transition( $order, 'processing', str_repeat( 'A', 70000 ), 2 );

		if ( $result instanceof \WP_Error ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			return;
		}
		$this->assertLessThanOrEqual( 5000, strlen( (string) $stored ), 'a comment that is stored must be cut to a sane length' );
	}

	/**
	 * Text fields of a status have an upper bound, so the autoloaded option cannot grow without limit.
	 */
	public function test_status_text_fields_are_bounded(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		$clean = ( new Repository() )->sanitize(
			array(
				'slug'          => 'big',
				'label'         => str_repeat( 'L', 1000000 ),
				'email_subject' => str_repeat( 'S', 1000000 ),
				'email_heading' => str_repeat( 'H', 1000000 ),
				'email_body'    => str_repeat( 'B', 1000000 ),
				'pdf_title'     => str_repeat( 'T', 1000000 ),
				'pdf_text'      => str_repeat( 'P', 1000000 ),
			)
		);

		foreach ( array( 'label', 'email_subject', 'email_heading', 'pdf_title' ) as $field ) {
			$this->assertLessThanOrEqual( 2000, strlen( (string) $clean[ $field ] ), $field );
		}
		foreach ( array( 'email_body', 'pdf_text' ) as $field ) {
			$this->assertLessThanOrEqual( 100000, strlen( (string) $clean[ $field ] ), $field );
		}
	}

	/**
	 * Saving refuses a text above the limit with a message that names the field, and stores nothing.
	 */
	public function test_save_refuses_too_long_text_with_a_clear_error(): void {
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\expect( 'update_option' )->never();

		$result = ( new Repository() )->save(
			array(
				'slug'  => 'fresh',
				'label' => str_repeat( 'L', Repository::MAX_SHORT_TEXT + 1 ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ofwc_text_too_long', $result->get_error_code() );
		$this->assertStringContainsString( 'Label', $result->get_error_message() );

		$result = ( new Repository() )->save(
			array(
				'slug'     => 'fresh',
				'label'    => 'Fresh',
				'pdf_text' => str_repeat( 'P', Repository::MAX_LONG_TEXT + 1 ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Text on the document', $result->get_error_message() );
	}

	/**
	 * A text exactly at the limit is accepted.
	 */
	public function test_text_at_the_limit_is_accepted(): void {
		$stored = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$stored ): bool {
				$stored[ $name ] = $value;
				return true;
			}
		);

		$result = ( new Repository() )->save(
			array(
				'slug'  => 'fresh',
				'label' => str_repeat( 'L', Repository::MAX_SHORT_TEXT ),
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( Repository::MAX_SHORT_TEXT, strlen( $stored['ofwc_statuses']['fresh']['label'] ) );
	}
}
