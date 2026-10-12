<?php
/**
 * Transition rules: storage, sanitizing and permission checks.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\Statuses\Repository;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules live in the `ofwc_transitions` option as a list of
 * { from, to, roles[], require_comment }. Slugs have no `wc-` prefix; `from` may be "*".
 *
 * Policy:
 * - A transition where neither side is a custom Order Flow status is never restricted.
 * - Users with `manage_options` may always make any transition.
 * - Otherwise a rule matching (from or "*", to) must list one of the user's roles.
 * - `require_comment` on a matching rule demands a non-empty comment, for everybody.
 */
final class Rules {

	public const ANY = '*';

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Stored rules in a normalized shape.
	 *
	 * @return array<int, array{from: string, to: string, roles: string[], require_comment: bool}>
	 */
	public function all(): array {
		$stored = get_option( Repository::TRANSITIONS_OPTION, array() );
		$rules  = array();
		if ( ! is_array( $stored ) ) {
			return $rules;
		}
		foreach ( $stored as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['from'], $item['to'] ) || ! is_string( $item['from'] ) || ! is_string( $item['to'] ) ) {
				continue;
			}
			$roles   = isset( $item['roles'] ) && is_array( $item['roles'] ) ? array_values( array_filter( $item['roles'], 'is_string' ) ) : array();
			$rules[] = array(
				'from'            => $item['from'],
				'to'              => $item['to'],
				'roles'           => $roles,
				'require_comment' => ! empty( $item['require_comment'] ),
			);
		}
		return $rules;
	}

	/**
	 * Replace all rules after sanitizing them.
	 *
	 * @param array<mixed> $raw Submitted rules.
	 */
	public function save_all( array $raw ): void {
		update_option( Repository::TRANSITIONS_OPTION, $this->sanitize( $raw ), true );
	}

	/**
	 * Clean submitted rules: statuses must exist, roles must exist, duplicates are merged.
	 *
	 * @param array<mixed> $raw Submitted rules.
	 * @return array<int, array{from: string, to: string, roles: string[], require_comment: bool}>
	 */
	public function sanitize( array $raw ): array {
		$statuses = $this->status_slugs();
		$roles    = array_keys( wp_roles()->get_names() );
		$merged   = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$from = isset( $item['from'] ) && is_string( $item['from'] ) ? $this->clean_slug( $item['from'] ) : '';
			$to   = isset( $item['to'] ) && is_string( $item['to'] ) ? $this->clean_slug( $item['to'] ) : '';
			if ( ( self::ANY !== $from && ! in_array( $from, $statuses, true ) ) || ! in_array( $to, $statuses, true ) || $from === $to ) {
				continue;
			}
			$given = isset( $item['roles'] ) && is_array( $item['roles'] ) ? $item['roles'] : array();
			$clean = array();
			foreach ( $given as $role ) {
				if ( is_string( $role ) && in_array( $role, $roles, true ) ) {
					$clean[] = $role;
				}
			}
			$comment = ! empty( $item['require_comment'] );
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
	 * Whether the user may move an order from one status to another.
	 *
	 * @param string      $from Current status slug without prefix.
	 * @param string      $to   Target status slug without prefix.
	 * @param WP_User|int $user User object or id.
	 */
	public function is_allowed( string $from, string $to, $user ): bool {
		if ( $from === $to ) {
			return false;
		}
		if ( ! $this->involves_custom( $from, $to ) ) {
			return true;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		$user_roles = $this->roles_of( $user );
		if ( array() === $user_roles ) {
			return false;
		}
		foreach ( $this->matching( $from, $to ) as $rule ) {
			if ( array() !== array_intersect( $rule['roles'], $user_roles ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the transition needs a comment.
	 *
	 * @param string $from Current status slug without prefix.
	 * @param string $to   Target status slug without prefix.
	 */
	public function requires_comment( string $from, string $to ): bool {
		foreach ( $this->matching( $from, $to ) as $rule ) {
			if ( $rule['require_comment'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Status slugs the user may move an order to from the given status.
	 *
	 * @param string      $from Current status slug without prefix.
	 * @param WP_User|int $user User object or id.
	 * @return string[]
	 */
	public function allowed_targets( string $from, $user ): array {
		$targets = array();
		foreach ( $this->status_slugs() as $slug ) {
			if ( $this->is_allowed( $from, $slug, $user ) ) {
				$targets[] = $slug;
			}
		}
		return $targets;
	}

	/**
	 * Whether the user could use at least one transition into the target from any other status.
	 *
	 * @param string      $to   Target status slug without prefix.
	 * @param WP_User|int $user User object or id.
	 */
	public function can_reach( string $to, $user ): bool {
		foreach ( $this->status_slugs() as $slug ) {
			if ( $this->is_allowed( $slug, $to, $user ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rules that apply to a pair of statuses.
	 *
	 * @param string $from Current status slug.
	 * @param string $to   Target status slug.
	 * @return array<int, array{from: string, to: string, roles: string[], require_comment: bool}>
	 */
	private function matching( string $from, string $to ): array {
		$found = array();
		foreach ( $this->all() as $rule ) {
			if ( $rule['to'] === $to && ( self::ANY === $rule['from'] || $rule['from'] === $from ) ) {
				$found[] = $rule;
			}
		}
		return $found;
	}

	/**
	 * Whether either side of the transition is a custom status.
	 *
	 * @param string $from Current status slug.
	 * @param string $to   Target status slug.
	 */
	private function involves_custom( string $from, string $to ): bool {
		return null !== $this->repository->get( $from ) || null !== $this->repository->get( $to );
	}

	/**
	 * Role keys of a user.
	 *
	 * @param WP_User|int $user User object or id.
	 * @return string[]
	 */
	private function roles_of( $user ): array {
		$object = is_int( $user ) ? get_userdata( $user ) : $user;
		if ( ! is_object( $object ) ) {
			return array();
		}
		return array_values( array_filter( (array) $object->roles, 'is_string' ) );
	}

	/**
	 * Registered order status slugs without the `wc-` prefix.
	 *
	 * @return string[]
	 */
	private function status_slugs(): array {
		$slugs = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
			$slugs[] = 0 === strpos( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key;
		}
		return $slugs;
	}

	/**
	 * Lowercase slug without the `wc-` prefix.
	 *
	 * @param string $slug Raw slug.
	 */
	private function clean_slug( string $slug ): string {
		if ( self::ANY === trim( $slug ) ) {
			return self::ANY;
		}
		$slug = sanitize_key( $slug );
		return 0 === strpos( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;
	}
}
