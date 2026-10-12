<?php
/**
 * Pure decisions around deactivation and reactivation.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

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
		return (int) array_sum( array_map( 'intval', $counts ) );
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
		if ( str_starts_with( $slug, 'wc-' ) ) {
			$slug = substr( $slug, 3 );
		}
		return '' !== $slug && in_array( $slug, $known, true ) ? $slug : null;
	}
}
