<?php
/**
 * Safe conversion of loosely typed WordPress values (options, hook arguments) to scalars.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns `mixed` values into strings and integers without PHP warnings.
 */
final class Value {

	/**
	 * Scalar to string, anything else to the fallback.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fallback Result for arrays, objects and null.
	 */
	public static function string( $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Numeric value to int, anything else to the fallback.
	 *
	 * @param mixed $value    Value.
	 * @param int   $fallback Result for non-numeric values.
	 */
	public static function int( $value, int $fallback = 0 ): int {
		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Order ID from an item wc_get_orders() returned: an order object or, with return=ids, an ID.
	 *
	 * @param mixed $item Result item.
	 */
	public static function order_id( $item ): ?int {
		if ( $item instanceof \WC_Order ) {
			return $item->get_id();
		}
		return is_numeric( $item ) ? (int) $item : null;
	}

	/**
	 * A WordPress option as a string.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Default for missing or non-scalar options.
	 */
	public static function option_string( string $name, string $fallback = '' ): string {
		return self::string( get_option( $name, $fallback ), $fallback );
	}
}
