<?php
/**
 * Runs bulk status changes without their usual side effects.
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
 * When Order Flow itself moves hundreds of orders (parking on deactivation, restoring, deleting a status),
 * the customer did nothing and the shop did not sell or ship anything. For the duration of the callback:
 *
 * - no email is sent (WooCommerce, Order Flow or any other plugin that calls wp_mail),
 * - the Order Flow status hooks do not run (no history, no PDF, no email),
 * - the WooCommerce handlers attached to the statuses involved that change stock, release reserved stock,
 *   count sales or count coupon usage do not run.
 *
 * Everything is put back afterwards, also when the callback throws.
 */
final class Isolation {

	/**
	 * WooCommerce handlers with side effects on stock, sales counters and coupon usage.
	 *
	 * @var string[]
	 */
	public const HANDLERS = array(
		'wc_maybe_reduce_stock_levels',
		'wc_maybe_increase_stock_levels',
		'wc_release_stock_for_order',
		'wc_update_total_sales_counts',
		'wc_update_coupon_usage_counts',
	);

	/**
	 * Run a callback in isolation.
	 *
	 * @param callable $callback    Work to do.
	 * @param string[] $statuses    Statuses (no wc- prefix) whose WooCommerce handlers are detached; On hold is always included.
	 * @param bool     $mute_emails Whether emails and Order Flow hooks are switched off too.
	 */
	public static function run( callable $callback, array $statuses = array(), bool $mute_emails = true ): void {
		/**
		 * WordPress hook registry, a global the stubs do not type.
		 *
		 * @var array<string, \WP_Hook> $wp_filter
		 */
		global $wp_filter;
		$saved = null;
		$muted = array();
		if ( $mute_emails ) {
			$saved = isset( $wp_filter['ofwc_status_entered'] ) ? $wp_filter['ofwc_status_entered'] : null;
			unset( $wp_filter['ofwc_status_entered'] );
			add_filter( 'pre_wp_mail', '__return_false', 999 );
			$muted = self::mute_emails();
		}
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', 999 );
		$detached = self::detach_handlers( array_values( array_unique( array_merge( array( 'on-hold' ), $statuses ) ) ) );

		try {
			$callback();
		} finally {
			foreach ( $detached as $handler ) {
				if ( is_callable( $handler['callback'] ) ) {
					add_action( $handler['tag'], $handler['callback'], $handler['priority'], 1 );
				}
			}
			remove_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', 999 );
			if ( $mute_emails ) {
				foreach ( $muted as $hook ) {
					remove_filter( $hook, '__return_false', 999 );
				}
				remove_filter( 'pre_wp_mail', '__return_false', 999 );
				if ( null !== $saved ) {
					$wp_filter['ofwc_status_entered'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the hooks removed above.
				}
			}
		}
	}

	/**
	 * Switch off every WooCommerce email.
	 *
	 * @return string[] Filter names added.
	 */
	private static function mute_emails(): array {
		$hooks = array( 'woocommerce_email_enabled_customer_on_hold_order', 'woocommerce_email_enabled_new_order' );
		if ( function_exists( 'WC' ) ) {
			foreach ( WC()->mailer()->get_emails() as $email ) {
				$hooks[] = 'woocommerce_email_enabled_' . Value::string( $email->id );
			}
		}
		$hooks = array_values( array_unique( $hooks ) );
		foreach ( $hooks as $hook ) {
			add_filter( $hook, '__return_false', 999 );
		}
		return $hooks;
	}

	/**
	 * Take the WooCommerce handlers with side effects off the given statuses.
	 *
	 * @param string[] $statuses Statuses without the wc- prefix.
	 * @return array<int, array{tag: string, callback: string, priority: int}>
	 */
	private static function detach_handlers( array $statuses ): array {
		$tags = array();
		foreach ( $statuses as $status ) {
			$tags[] = 'woocommerce_order_status_' . $status;
		}
		if ( in_array( 'cancelled', $statuses, true ) ) {
			foreach ( array( 'completed', 'processing', 'on-hold' ) as $from ) {
				$tags[] = 'woocommerce_order_status_' . $from . '_to_cancelled';
			}
		}
		$detached = array();
		foreach ( $tags as $tag ) {
			foreach ( self::HANDLERS as $callback ) {
				$priority = has_action( $tag, $callback );
				if ( false === $priority ) {
					continue;
				}
				remove_action( $tag, $callback, $priority );
				$detached[] = array(
					'tag'      => $tag,
					'callback' => $callback,
					'priority' => $priority,
				);
			}
		}
		return $detached;
	}
}
