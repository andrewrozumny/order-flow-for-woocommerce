<?php
/**
 * Memory budget of bulk order work.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moving thousands of orders in one request fills the memory with order objects. Bulk work stops at 60 percent of
 * the memory limit, so the batch ends cleanly and the next one carries on, and drops the runtime caches after every
 * order. Unit tests replace the measurement.
 */
final class Memory {

	public const SHARE = 0.6;

	/**
	 * Replacement for the measurement in tests: returns array( used bytes, limit bytes ).
	 *
	 * @var callable|null
	 */
	public static $probe = null;

	/**
	 * Whether the work has used up its share of the memory limit. Unlimited memory never runs out.
	 *
	 * @phpstan-impure
	 */
	public static function low(): bool {
		if ( null !== self::$probe ) {
			$measured = call_user_func( self::$probe );
			$used     = is_array( $measured ) ? Value::int( $measured[0] ?? 0 ) : 0;
			$limit    = is_array( $measured ) ? Value::int( $measured[1] ?? 0 ) : 0;
		} else {
			$used  = memory_get_usage();
			$limit = \OrderFlowWC\Pdf\Generator::memory_limit();
		}
		return $limit > 0 && $used >= (int) ( $limit * self::SHARE );
	}

	/**
	 * Drop the runtime object cache, which holds every order read so far.
	 */
	public static function relax(): void {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
