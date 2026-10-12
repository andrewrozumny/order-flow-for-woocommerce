<?php
/**
 * Transition rules: storage, sanitizing and permission checks.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Statuses\Slug;
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
 *
 * The normalized rules, the index of rules by target status and the list of status slugs are built once
 * per request and dropped whenever the rules or the statuses are saved (see watch()).
 *
 * @phpstan-type Rule array{from: string, to: string, roles: string[], require_comment: bool}
 */
final class Rules {

	public const ANY = '*';

	/**
	 * Normalized rules of this request, null until first used.
	 *
	 * @var array<int, Rule>|null
	 */
	private static ?array $cached_rules = null;

	/**
	 * The same rules grouped by target status.
	 *
	 * @var array<string, array<int, Rule>>|null
	 */
	private static ?array $by_target = null;

	/**
	 * Registered status slugs of this request.
	 *
	 * @var string[]|null
	 */
	private static ?array $cached_slugs = null;

	/**
	 * Role keys by user id.
	 *
	 * @var array<int, string[]>
	 */
	private static array $user_roles = array();

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
	 * Drop the per-request caches. Called when rules or statuses change.
	 */
	public static function flush_cache(): void {
		self::$cached_rules = null;
		self::$by_target    = null;
		self::$cached_slugs = null;
		self::$user_roles   = array();
	}

	/**
	 * Flush the caches whenever the rules or the statuses are written, wherever the write comes from.
	 */
	public static function watch(): void {
		foreach ( array( Repository::TRANSITIONS_OPTION, Repository::OPTION ) as $option ) {
			foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
				add_action( $prefix . $option, array( self::class, 'flush_cache' ) );
			}
		}
	}

	/**
	 * Stored rules in a normalized shape.
	 *
	 * @return array<int, Rule>
	 */
	public function all(): array {
		if ( null === self::$cached_rules ) {
			self::$cached_rules = $this->read();
		}
		return self::$cached_rules;
	}

	/**
	 * Read and normalize the stored rules.
	 *
	 * @return array<int, Rule>
	 */
	private function read(): array {
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
				'require_comment' => (bool) ( $item['require_comment'] ?? false ),
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
		self::flush_cache();
	}

	/**
	 * Clean submitted rules: statuses must exist, roles must exist, duplicates are merged.
	 *
	 * @param array<mixed> $raw Submitted rules.
	 * @return array<int, Rule>
	 */
	public function sanitize( array $raw ): array {
		return ( new RuleSanitizer() )->sanitize( $raw, $this->status_slugs(), array_keys( wp_roles()->get_names() ) );
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
	 * @return array<int, Rule>
	 */
	private function matching( string $from, string $to ): array {
		if ( null === self::$by_target ) {
			$index = array();
			foreach ( $this->all() as $rule ) {
				$index[ $rule['to'] ][] = $rule;
			}
			self::$by_target = $index;
		}
		$found = array();
		foreach ( self::$by_target[ $to ] ?? array() as $rule ) {
			if ( self::ANY === $rule['from'] || $rule['from'] === $from ) {
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
		$id = is_int( $user ) ? $user : $user->ID;
		if ( $id > 0 && isset( self::$user_roles[ $id ] ) ) {
			return self::$user_roles[ $id ];
		}
		$roles = array_values( array_filter( $object->roles, 'is_string' ) );
		if ( $id > 0 ) {
			self::$user_roles[ $id ] = $roles;
		}
		return $roles;
	}

	/**
	 * Registered order status slugs without the `wc-` prefix.
	 *
	 * @return string[]
	 */
	private function status_slugs(): array {
		if ( null === self::$cached_slugs ) {
			$slugs = array();
			foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
				$slugs[] = Slug::strip( $key );
			}
			self::$cached_slugs = $slugs;
		}
		return self::$cached_slugs;
	}
}
