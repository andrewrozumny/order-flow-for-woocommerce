<?php
/**
 * The comment of a status change: what counts as empty, and how long it may be.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A comment is saved in the order history (one meta value), in a private note and in emails, so it has an upper bound.
 * A comment made only of blanks, no-break spaces or zero-width characters looks empty to a person, so it does not
 * satisfy "comment required".
 */
final class Comment {

	public const MAX_LENGTH = 5000;

	/**
	 * Whether nothing visible is left of the comment.
	 *
	 * @param string $comment Comment text.
	 */
	public static function is_blank( string $comment ): bool {
		return '' === preg_replace( '/[\p{Z}\p{C}\s]+/u', '', $comment );
	}

	/**
	 * The comment trimmed, cut to the maximum length, without characters that cannot be shown.
	 *
	 * @param string $comment Comment text.
	 */
	public static function clean( string $comment ): string {
		$comment = trim( $comment );
		return self::is_blank( $comment ) ? '' : mb_substr( $comment, 0, self::MAX_LENGTH );
	}
}
