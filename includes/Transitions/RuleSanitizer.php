<?php
/**
 * Cleans submitted transition rules.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\Statuses\Slug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns whatever a form or an import file holds into rules that are safe to store: known statuses and roles only,
 * duplicates merged.
 *
 * @phpstan-import-type Rule from Rules
 */
final class RuleSanitizer {

	/**
	 * Clean submitted rules.
	 *
	 * @param array<mixed> $raw      Submitted rules.
	 * @param string[]     $statuses Slugs of the existing statuses, without the `wc-` prefix.
	 * @param string[]     $roles    Keys of the existing roles.
	 * @return array<int, Rule>
	 */
	public function sanitize( array $raw, array $statuses, array $roles ): array {
		$merged = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$from = isset( $item['from'] ) && is_string( $item['from'] ) ? $this->clean_slug( $item['from'] ) : '';
			$to   = isset( $item['to'] ) && is_string( $item['to'] ) ? $this->clean_slug( $item['to'] ) : '';
			if ( ( Rules::ANY !== $from && ! in_array( $from, $statuses, true ) ) || ! in_array( $to, $statuses, true ) || $from === $to ) {
				continue;
			}
			$given = isset( $item['roles'] ) && is_array( $item['roles'] ) ? $item['roles'] : array();
			$clean = array();
			foreach ( $given as $role ) {
				if ( is_string( $role ) && in_array( $role, $roles, true ) ) {
					$clean[] = $role;
				}
			}
			$comment = (bool) ( $item['require_comment'] ?? false );
			$key     = $from . '>' . $to;
			if ( isset( $merged[ $key ] ) ) {
				$merged[ $key ]['roles']           = array_values( array_unique( array_merge( $merged[ $key ]['roles'], $clean ) ) );
				$merged[ $key ]['require_comment'] = $merged[ $key ]['require_comment'] || $comment;
				continue;
			}
			$merged[ $key ] = array(
				'from'            => $from,
				'to'              => $to,
				'roles'           => array_values( array_unique( $clean ) ),
				'require_comment' => $comment,
			);
		}
		return array_values( $merged );
	}

	/**
	 * Lowercase slug without the `wc-` prefix.
	 *
	 * @param string $slug Raw slug.
	 */
	private function clean_slug( string $slug ): string {
		if ( Rules::ANY === trim( $slug ) ) {
			return Rules::ANY;
		}
		return Slug::strip( sanitize_key( $slug ) );
	}
}
