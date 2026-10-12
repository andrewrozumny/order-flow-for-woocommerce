<?php
/**
 * Outcome of an import.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain value object collecting what an import did.
 */
final class ImportResult {

	/**
	 * Slugs created.
	 *
	 * @var string[]
	 */
	public array $added = array();

	/**
	 * Slugs changed.
	 *
	 * @var string[]
	 */
	public array $updated = array();

	/**
	 * Slugs already identical.
	 *
	 * @var string[]
	 */
	public array $unchanged = array();

	/**
	 * Slugs removed (replace mode).
	 *
	 * @var string[]
	 */
	public array $removed = array();

	/**
	 * Slugs kept although the file lacks them, with their order count (replace mode).
	 *
	 * @var array<string, int>
	 */
	public array $kept = array();

	/**
	 * Skipped entries: slug (or position) => reason.
	 *
	 * @var array<string, string>
	 */
	public array $skipped = array();

	/**
	 * Role keys from the file that do not exist on this site.
	 *
	 * @var string[]
	 */
	public array $dropped_roles = array();

	/**
	 * Number of transition rules stored.
	 *
	 * @var int
	 */
	public int $rules_saved = 0;

	/**
	 * Number of transition rules in the file that were ignored.
	 *
	 * @var int
	 */
	public int $rules_skipped = 0;

	/**
	 * Whether the store details text was written.
	 *
	 * @var bool
	 */
	public bool $settings_updated = false;

	/**
	 * Whether anything changed on the site.
	 */
	public function changed(): bool {
		return array() !== $this->added || array() !== $this->updated || array() !== $this->removed || $this->settings_updated;
	}

	/**
	 * Human readable summary, one sentence per fact.
	 */
	public function summary(): string {
		$parts = array();

		if ( array() !== $this->added ) {
			/* translators: 1: number of statuses, 2: list of slugs. */
			$parts[] = self::list_text( __( 'Added %1$d: %2$s.', 'order-flow-for-woocommerce' ), $this->added );
		}
		if ( array() !== $this->updated ) {
			/* translators: 1: number of statuses, 2: list of slugs. */
			$parts[] = self::list_text( __( 'Updated %1$d: %2$s.', 'order-flow-for-woocommerce' ), $this->updated );
		}
		if ( array() !== $this->unchanged ) {
			/* translators: %d: number of statuses. */
			$parts[] = sprintf( __( '%d already up to date.', 'order-flow-for-woocommerce' ), count( $this->unchanged ) );
		}
		if ( array() !== $this->removed ) {
			/* translators: 1: number of statuses, 2: list of slugs. */
			$parts[] = self::list_text( __( 'Removed %1$d: %2$s.', 'order-flow-for-woocommerce' ), $this->removed );
		}
		if ( array() !== $this->kept ) {
			$kept = array();
			foreach ( $this->kept as $slug => $count ) {
				$kept[] = $slug . ' (' . $count . ')';
			}
			/* translators: 1: number of statuses, 2: list of slugs with order counts. */
			$parts[] = self::list_text( __( 'Kept %1$d because orders are still in them: %2$s.', 'order-flow-for-woocommerce' ), $kept );
		}
		if ( array() !== $this->skipped ) {
			$skipped = array();
			foreach ( $this->skipped as $slug => $reason ) {
				$skipped[] = $slug . ' (' . $reason . ')';
			}
			/* translators: 1: number of skipped entries, 2: list of entries with reasons. */
			$parts[] = self::list_text( __( 'Skipped %1$d: %2$s.', 'order-flow-for-woocommerce' ), $skipped );
		}
		/* translators: %d: number of transition rules. */
		$parts[] = sprintf( __( 'Transition rules stored: %d.', 'order-flow-for-woocommerce' ), $this->rules_saved );
		if ( $this->rules_skipped > 0 ) {
			/* translators: %d: number of transition rules. */
			$parts[] = sprintf( __( 'Transition rules ignored (unknown statuses or duplicates): %d.', 'order-flow-for-woocommerce' ), $this->rules_skipped );
		}
		if ( array() !== $this->dropped_roles ) {
			/* translators: %s: list of role keys. */
			$parts[] = sprintf( __( 'Roles that do not exist on this site were dropped: %s.', 'order-flow-for-woocommerce' ), implode( ', ', $this->dropped_roles ) );
		}
		if ( $this->settings_updated ) {
			$parts[] = __( 'Store details updated.', 'order-flow-for-woocommerce' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * One summary sentence: the format gets the number of entries and the joined list.
	 *
	 * @param string   $format Translated format with %1$d and %2$s.
	 * @param string[] $items  Entries to list.
	 */
	private static function list_text( string $format, array $items ): string {
		return sprintf( $format, count( $items ), implode( ', ', $items ) );
	}
}
