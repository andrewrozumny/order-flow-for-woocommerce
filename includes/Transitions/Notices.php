<?php
/**
 * Admin notices carried across a redirect.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small per-user queue of notices for the order screens.
 */
final class Notices {

	/**
	 * Queue a notice for the current user's next admin page load.
	 *
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Plain text message.
	 */
	public static function add( string $type, string $message ): void {
		$key   = self::key();
		$items = get_transient( $key );
		$items = is_array( $items ) ? $items : array();

		$items[] = array(
			'type'    => in_array( $type, array( 'success', 'warning', 'error' ), true ) ? $type : 'success',
			'message' => $message,
		);
		set_transient( $key, $items, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Print and clear the queue.
	 */
	public static function render(): void {
		$key   = self::key();
		$items = get_transient( $key );
		if ( ! is_array( $items ) || array() === $items ) {
			return;
		}
		delete_transient( $key );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'], $item['message'] ) ) {
				continue;
			}
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( (string) $item['type'] ),
				esc_html( (string) $item['message'] )
			);
		}
	}

	/**
	 * Transient key of the current user.
	 */
	private static function key(): string {
		return 'ofwc_tn_' . get_current_user_id();
	}
}
