<?php
/**
 * The label a customer sees for a status that is not shown to customers.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A status with "Show to the customer" off is an internal step. Customers see "Processing" instead of its label,
 * on the screen and in the emails they receive.
 *
 * @phpstan-import-type Status from Repository
 */
final class Masking {

	/**
	 * The WooCommerce status whose label stands in for a hidden one.
	 */
	public const STAND_IN = 'processing';

	/**
	 * Whether the status is an internal step.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 */
	public static function is_hidden( array $status ): bool {
		return ! $status['show_in_account'];
	}

	/**
	 * The label to show a customer.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 */
	public static function customer_label( array $status ): string {
		return self::is_hidden( $status ) ? wc_get_order_status_name( self::STAND_IN ) : $status['label'];
	}
}
