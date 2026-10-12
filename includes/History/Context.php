<?php
/**
 * Request-scoped context of the status change in progress.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\History;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the comment and the acting user from whoever triggers a transition to the code that
 * records it and sends emails. Set it before changing a status and clear it afterwards.
 */
final class Context {

	/**
	 * Comment entered for the current change.
	 *
	 * @var string
	 */
	private static string $comment = '';

	/**
	 * Acting user id, null when not set explicitly.
	 *
	 * @var int|null
	 */
	private static ?int $user_id = null;

	/**
	 * Whether the person chose to send the comment to the customer.
	 *
	 * @var bool
	 */
	private static bool $notify_customer = false;

	/**
	 * Set the context.
	 *
	 * @param string   $comment         Comment text.
	 * @param int|null $user_id         Acting user; null means the current user.
	 * @param bool     $notify_customer Whether the comment may appear in the email to the customer.
	 */
	public static function set( string $comment, ?int $user_id = null, bool $notify_customer = false ): void {
		self::$comment         = \OrderFlowWC\Transitions\Comment::clean( $comment );
		self::$user_id         = $user_id;
		self::$notify_customer = $notify_customer;
	}

	/**
	 * Forget the context.
	 */
	public static function clear(): void {
		self::$comment         = '';
		self::$user_id         = null;
		self::$notify_customer = false;
	}

	/**
	 * Whether the comment of the change in progress may be shown to the customer.
	 */
	public static function notify_customer(): bool {
		return self::$notify_customer && '' !== self::$comment;
	}

	/**
	 * The comment of the change in progress, empty when none.
	 */
	public static function comment(): string {
		return self::$comment;
	}

	/**
	 * The acting user: the explicit one, else the current user, 0 when nobody is logged in.
	 */
	public static function user_id(): int {
		if ( null !== self::$user_id ) {
			return self::$user_id;
		}
		return function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
	}
}
