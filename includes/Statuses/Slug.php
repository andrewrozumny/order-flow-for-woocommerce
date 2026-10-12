<?php
/**
 * Order status slug helpers.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce keys statuses as `wc-slug` in lists and post statuses, and as `slug` in hooks and
 * `WC_Order::get_status()`. Order Flow stores slugs without the prefix.
 */
final class Slug {

	public const PREFIX = 'wc-';

	/**
	 * Slug without the `wc-` prefix.
	 *
	 * @param string $status Status key with or without the prefix.
	 */
	public static function strip( string $status ): string {
		return str_starts_with( $status, self::PREFIX ) ? substr( $status, strlen( self::PREFIX ) ) : $status;
	}

	/**
	 * Status key with the `wc-` prefix.
	 *
	 * @param string $status Status key with or without the prefix.
	 */
	public static function prefixed( string $status ): string {
		return self::PREFIX . self::strip( $status );
	}
}
