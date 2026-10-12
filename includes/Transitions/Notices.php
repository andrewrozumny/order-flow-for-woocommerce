<?php
/**
 * Admin notices carried across a redirect.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one notice queue of the plugin: a small per-user list kept in user meta, shown on the next admin page
 * and then removed. User meta is read together with the other meta of the logged-in user, so an admin page
 * without a notice costs no extra database query.
 */
final class Notices {

	public const META_KEY = 'ofwc_notices';

	/**
	 * Queue a notice for the current user's next admin page load.
	 *
	 * @param string $type    `success`, `info`, `warning` or `error`.
	 * @param string $message Plain text message.
	 */
	public static function add( string $type, string $message ): void {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return;
		}
		$items   = self::read( $user );
		$items[] = array(
			'type'    => in_array( $type, array( 'success', 'info', 'warning', 'error' ), true ) ? $type : 'success',
			'message' => $message,
		);
		update_user_meta( $user, self::META_KEY, $items );
	}

	/**
	 * Print and clear the queue.
	 */
	public static function render(): void {
		$user  = get_current_user_id();
		$items = $user > 0 ? self::read( $user ) : array();
		if ( array() === $items ) {
			return;
		}
		delete_user_meta( $user, self::META_KEY );
		foreach ( $items as $item ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $item['type'] ),
				esc_html( $item['message'] )
			);
		}
	}

	/**
	 * The queued notices of a user.
	 *
	 * @param int $user User id.
	 * @return array<int, array{type: string, message: string}>
	 */
	private static function read( int $user ): array {
		$raw   = get_user_meta( $user, self::META_KEY, true );
		$items = array();
		if ( ! is_array( $raw ) ) {
			return $items;
		}
		foreach ( $raw as $item ) {
			if ( is_array( $item ) && isset( $item['type'], $item['message'] ) ) {
				$items[] = array(
					'type'    => Value::string( $item['type'] ),
					'message' => Value::string( $item['message'] ),
				);
			}
		}
		return $items;
	}
}
