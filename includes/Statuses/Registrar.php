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
 * - Paid statuses join the "is paid" list and the reports statuses.
 * - Statuses flagged "reduce stock" call wc_maybe_reduce_stock_levels() when an order enters them.
 * - Front end only (not wp-admin, cron, WP-CLI or REST): a status with "show to customer" off
 *   is displayed as "Processing", so customers never see internal steps. The filter
 *   `ofwc_mask_status_labels` can switch that off for a given request.
 */
final class Registrar {

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
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'filter_order_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'filter_reports_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'filter_paid_statuses' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_reduce_stock' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_status_colors' ), 20 );
	}

	/**
	 * Register the post statuses.
	 */
	public function register_post_statuses(): void {
		foreach ( $this->repository->all() as $slug => $status ) {
			$label = $status['label'];
			// Same shape as _n_noop(); the label is user-defined data, not a translatable string.
			$count = str_replace( '%', '%%', $label ) . ' <span class="count">(%s)</span>';

			register_post_status(
				'wc-' . $slug,
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
		foreach ( $custom as $slug => $status ) {
			$hide                   = $mask && ! $status['show_in_account'];
			$fallback               = isset( $statuses['wc-processing'] ) ? $statuses['wc-processing'] : $status['label'];
			$added[ 'wc-' . $slug ] = $hide ? $fallback : $status['label'];
		}

		if ( ! array_key_exists( 'wc-processing', $statuses ) ) {
			return array_merge( $statuses, $added );
		}

		$result = array();
		foreach ( $statuses as $key => $label ) {
			if ( isset( $added[ $key ] ) ) {
				continue;
			}
			$result[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
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
	 * Color the custom statuses in the admin order list.
	 */
	public function enqueue_status_colors(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}
		$css = $this->status_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'woocommerce_admin_styles', $css );
		}
	}

	/**
	 * CSS for the admin order status pills.
	 */
	public function status_css(): string {
		$css = '';
		foreach ( $this->repository->all() as $slug => $status ) {
			$color = $status['color'];
			$css  .= sprintf(
				'.order-status.status-%1$s{background:%2$s;color:%3$s;}',
				$slug,
				$color,
				self::text_color( $color )
			);
		}
		return $css;
	}

	/**
	 * Readable text color (dark or white) for a background.
	 *
	 * @param string $hex Background as #rrggbb.
	 */
	public static function text_color( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 1 !== preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return '#1d2327';
		}
		$red       = (int) hexdec( substr( $hex, 0, 2 ) );
		$green     = (int) hexdec( substr( $hex, 2, 2 ) );
		$blue      = (int) hexdec( substr( $hex, 4, 2 ) );
		$luminance = ( 0.299 * $red + 0.587 * $green + 0.114 * $blue ) / 255;
		return $luminance > 0.6 ? '#1d2327' : '#ffffff';
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
	 * Whether the current request is a customer-facing one where hidden statuses are masked.
	 */
	private function mask_labels(): bool {
		$front = ! is_admin()
			&& ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		return (bool) apply_filters( 'ofwc_mask_status_labels', $front );
	}
}
