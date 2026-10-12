<?php
/**
 * Registers the custom statuses with WordPress and WooCommerce.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration policy:
 *
 * - Each status becomes the post status `wc-{slug}` and shows up in wc_get_order_statuses(),
 *   right after "Processing", ordered by position.
 * - Paid statuses join the "is paid" list and the legacy reports statuses. Statuses that are not paid are added
 *   to the statuses WooCommerce Analytics excludes, so unpaid orders stay out of the revenue figures.
 * - Statuses flagged "reduce stock" call wc_maybe_reduce_stock_levels() when an order enters them.
 * - A status with "show to customer" off is displayed as "Processing" to anyone who cannot work on orders
 *   (customers and visitors, on the shop front, in the REST and Store APIs, and in emails to the customer). People
 *   who can edit orders, cron and WP-CLI see the real label. The filter `ofwc_mask_status_labels` can switch the
 *   masking on or off for a given request.
 */
final class Registrar {

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Whether a user works on orders, by user id.
	 *
	 * @var array<int, bool>
	 */
	private array $staff = array();

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'filter_order_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'filter_reports_statuses' ) );
		add_filter( 'woocommerce_analytics_excluded_order_statuses', array( $this, 'filter_analytics_excluded_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'filter_paid_statuses' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_reduce_stock' ), 10, 3 );
		( new Colors( $this->repository ) )->register();
	}

	/**
	 * Register the post statuses.
	 */
	public function register_post_statuses(): void {
		foreach ( $this->repository->all() as $status ) {
			$slug  = $status['slug'];
			$label = $status['label'];
			// Same shape as _n_noop(); the label is user-defined data, not a translatable string.
			$count = str_replace( '%', '%%', $label ) . ' <span class="count">(%s)</span>';

			register_post_status(
				Slug::prefixed( $slug ),
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => array(
						0          => $count,
						1          => $count,
						'singular' => $count,
						'plural'   => $count,
						'context'  => null,
						'domain'   => null,
					),
				)
			);
		}
	}

	/**
	 * Add custom statuses to the WooCommerce status list, after "Processing".
	 *
	 * @param mixed $statuses Status label list keyed by wc-slug.
	 * @return mixed
	 */
	public function filter_order_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		$custom = $this->repository->all();
		if ( array() === $custom ) {
			return $statuses;
		}

		$mask  = $this->mask_labels();
		$added = array();
		foreach ( $custom as $status ) {
			$slug                             = $status['slug'];
			$hide                             = $mask && Masking::is_hidden( $status );
			$fallback                         = $statuses[ Slug::prefixed( Masking::STAND_IN ) ] ?? $status['label'];
			$added[ Slug::prefixed( $slug ) ] = $hide && is_string( $fallback ) ? $fallback : $status['label'];
		}

		if ( ! array_key_exists( Slug::prefixed( Masking::STAND_IN ), $statuses ) ) {
			return array_merge( $statuses, $added );
		}

		$result = array();
		foreach ( $statuses as $key => $label ) {
			if ( isset( $added[ $key ] ) ) {
				continue;
			}
			$result[ $key ] = $label;
			if ( Slug::prefixed( Masking::STAND_IN ) === $key ) {
				$result = array_merge( $result, $added );
			}
		}
		return $result;
	}

	/**
	 * Include paid custom statuses in reports.
	 *
	 * @param mixed $statuses Status slugs without prefix, or false.
	 * @return mixed
	 */
	public function filter_reports_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		return $this->with_paid_slugs( $statuses );
	}

	/**
	 * Keep orders in unpaid custom statuses out of WooCommerce Analytics.
	 *
	 * Analytics counts every status except the excluded ones (WooCommerce > Settings > Analytics), so a custom
	 * status that is not paid would otherwise add its orders to the revenue. The setting of the shop owner is read,
	 * never changed: this only adds to the list for the report query. The filter `ofwc_exclude_unpaid_from_analytics`
	 * switches it off.
	 *
	 * @param mixed $statuses Excluded status slugs without prefix.
	 * @return mixed
	 */
	public function filter_analytics_excluded_statuses( $statuses ) {
		if ( ! is_array( $statuses ) || ! (bool) apply_filters( 'ofwc_exclude_unpaid_from_analytics', true ) ) {
			return $statuses;
		}
		$unpaid = array();
		foreach ( $this->repository->all() as $status ) {
			if ( ! $status['paid'] ) {
				$unpaid[] = $status['slug'];
			}
		}
		return array_values( array_unique( array_merge( array_filter( $statuses, 'is_scalar' ), $unpaid ) ) );
	}

	/**
	 * Count paid custom statuses as paid.
	 *
	 * @param mixed $statuses Status slugs without prefix.
	 * @return mixed
	 */
	public function filter_paid_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		return $this->with_paid_slugs( $statuses );
	}

	/**
	 * Reduce stock when an order enters a status flagged for it.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status, no prefix.
	 * @param string $to       New status, no prefix.
	 */
	public function maybe_reduce_stock( int $order_id, string $from, string $to ): void {
		unset( $from );
		if ( ! in_array( $to, $this->slugs_where( 'reduce_stock' ), true ) ) {
			return;
		}
		wc_maybe_reduce_stock_levels( $order_id );
	}

	/**
	 * Slugs of the statuses where a boolean flag is on.
	 *
	 * @param 'paid'|'reduce_stock' $flag Field key.
	 * @return string[]
	 */
	private function slugs_where( string $flag ): array {
		$slugs = array();
		foreach ( $this->repository->all() as $status ) {
			if ( $status[ $flag ] ) {
				$slugs[] = $status['slug'];
			}
		}
		return $slugs;
	}

	/**
	 * The given status list plus the slugs of the paid custom statuses, without duplicates.
	 *
	 * @param array<mixed, mixed> $statuses Status slugs from WooCommerce.
	 * @return array<int, bool|float|int|string>
	 */
	private function with_paid_slugs( array $statuses ): array {
		return array_values( array_unique( array_merge( array_filter( $statuses, 'is_scalar' ), $this->slugs_where( 'paid' ) ) ) );
	}

	/**
	 * Whether the labels of hidden statuses are masked for the person looking at them.
	 *
	 * Masked for everyone who cannot work on orders: customers and visitors, whichever door they use (shop pages,
	 * admin-ajax, REST, Store API). Not masked for cron and WP-CLI, which show nothing to a customer.
	 */
	private function mask_labels(): bool {
		$system = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI );
		return (bool) apply_filters( 'ofwc_mask_status_labels', ! $system && ! $this->can_see_real_labels() );
	}

	/**
	 * Whether the current user works on orders. Looked up once per user and request.
	 */
	private function can_see_real_labels(): bool {
		if ( ! function_exists( 'current_user_can' ) || ! function_exists( 'get_current_user_id' ) ) {
			return false;
		}
		$user = get_current_user_id();
		if ( ! isset( $this->staff[ $user ] ) ) {
			$this->staff[ $user ] = $user > 0 && current_user_can( 'edit_shop_orders' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
		}
		return $this->staff[ $user ];
	}
}
