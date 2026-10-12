<?php
/**
 * Comment rules.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use OrderFlowWC\History\Context;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Comment;

/**
 * What counts as a comment.
 *
 * @covers \OrderFlowWC\Transitions\Comment
 * @covers \OrderFlowWC\History\Context
 */
final class CommentTest extends TestCase {

	/**
	 * Blanks of every kind are not a comment.
	 */
	public function test_blank_comments(): void {
		foreach ( array( '', ' ', "\t\n", "\u{00A0}", "\u{200B}", "\u{FEFF}", "\u{3000}", " \u{200D} " ) as $text ) {
			$this->assertTrue( Comment::is_blank( $text ) );
			$this->assertSame( '', Comment::clean( $text ) );
		}
		$this->assertFalse( Comment::is_blank( 'ok' ) );
		$this->assertFalse( Comment::is_blank( "\u{00A0}ok\u{00A0}" ) );
	}

	/**
	 * Long comments are cut, text keeps its characters.
	 */
	public function test_comment_is_cut_to_the_limit(): void {
		$this->assertSame( Comment::MAX_LENGTH, mb_strlen( Comment::clean( str_repeat( 'Ж', 20000 ) ) ) );
		$this->assertSame( 'Looks good', Comment::clean( '  Looks good  ' ) );
	}

	/**
	 * The context never holds more than the limit, and an invisible comment is no reason to tell the customer.
	 */
	public function test_context_keeps_a_clean_comment(): void {
		Context::set( str_repeat( 'A', 70000 ), 1, true );
		$this->assertSame( Comment::MAX_LENGTH, strlen( Context::comment() ) );
		$this->assertTrue( Context::notify_customer() );

		Context::set( "\u{200B}", 1, true );
		$this->assertFalse( Context::notify_customer() );
		Context::clear();
	}
}
