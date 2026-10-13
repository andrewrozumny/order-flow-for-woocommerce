<?php
/**
 * Pure decisions around deactivation and reactivation.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Statuses\Slug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No WordPress calls here, so the rules are easy to test.
 */
final class Decisions {

	public const CHOICE_KEEP = 'keep';
	public const CHOICE_MOVE = 'move';

	/**
	 * Whether the deactivation screen is needed.
	 *
	 * @param array<string, int> $counts Order count per custom status slug.
	 */
	public static function needs_screen( array $counts ): bool {
		return self::total( $counts ) > 0;
	}

	/**
	 * Total number of orders in custom statuses.
	 *
	 * @param array<string, int> $counts Order count per custom status slug.
	 */
	public static function total( array $counts ): int {
		return array_sum( $counts );
	}

	/**
	 * Validate the submitted choice; anything unexpected means "keep" (the non-destructive option).
	 *
	 * @param mixed $raw Submitted value.
	 */
	public static function choice( $raw ): string {
		return self::CHOICE_MOVE === $raw ? self::CHOICE_MOVE : self::CHOICE_KEEP;
	}

	/**
	 * Status an order should return to on reactivation.
	 *
	 * @param mixed    $stored Value of the pre-deactivation meta.
	 * @param string[] $known  Slugs of the statuses that exist in the configuration.
	 * @return string|null Slug to restore, or null when the status no longer exists.
	 */
	public static function restore_target( $stored, array $known ): ?string {
		if ( ! is_string( $stored ) ) {
			return null;
		}
		$slug = strtolower( trim( $stored ) );
		$slug = Slug::strip( $slug );
		return '' !== $slug && in_array( $slug, $known, true ) ? $slug : null;
	}

	public const RESTORE   = 'restore';
	public const RELEASE   = 'release';
	public const KEEP_HOLD = 'keep';

	/**
	 * What to do on reactivation with an order that carries the pre-deactivation meta.
	 *
	 * Only orders that are still in On hold (parked by the plugin) go back to their status. An order whose
	 * status changed while the plugin was inactive is left alone and only loses the meta. A parked order
	 * whose status no longer exists stays in On hold with its meta.
	 *
	 * @param string   $current Current order status without the wc- prefix.
	 * @param mixed    $stored  Value of the pre-deactivation meta.
	 * @param string[] $known   Slugs of the statuses that exist in the configuration.
	 * @return 'restore'|'release'|'keep'
	 */
	public static function restore_action( string $current, $stored, array $known ): string {
		if ( 'on-hold' !== $current ) {
			return self::RELEASE;
		}
		return null === self::restore_target( $stored, $known ) ? self::KEEP_HOLD : self::RESTORE;
	}

	/**
	 * Whether a time budget is used up.
	 *
	 * @param float $now      Current time in seconds.
	 * @param float $deadline Deadline in seconds.
	 */
	public static function out_of_time( float $now, float $deadline ): bool {
		return $now >= $deadline;
	}
}
