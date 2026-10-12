<?php
/**
 * Activation and deactivation entry points (single site and network).
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Pdf\Attachments;
use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Neither hook does any work on orders. Activation only queues the restore (a flag per site on a network),
 * deactivation only tells the person what stays behind.
 */
final class Lifecycle {

	public const NETWORK_HOOK = 'ofwc_network_flag_sites';
	public const SITES_BATCH  = 100;
	public const TIME_LIMIT   = 15.0;

	/**
	 * Hooks that must exist on every request.
	 */
	public static function register(): void {
		add_action( self::NETWORK_HOOK, array( self::class, 'flag_sites' ) );
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			self::flag_sites( 0 );
			return;
		}
		( new Orders() )->restore_or_defer();
	}

	/**
	 * Give every site of the network a deferred restore, 100 sites at a time. When the time runs out the rest
	 * is queued as a single event.
	 *
	 * @param mixed $offset Position in the list of sites.
	 */
	public static function flag_sites( $offset = 0 ): void {
		$offset  = Value::int( $offset );
		$started = microtime( true );
		do {
			$ids = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => self::SITES_BATCH,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);
			foreach ( $ids as $site_id ) {
				switch_to_blog( Value::int( $site_id ) );
				update_option( Orders::PENDING_FLAG, '1', false );
				restore_current_blog();
			}
			$found   = count( $ids );
			$offset += $found;
			$more    = self::SITES_BATCH === $found;
			if ( $more && microtime( true ) - $started > self::TIME_LIMIT ) {
				wp_schedule_single_event( time() + 10, self::NETWORK_HOOK, array( $offset ) );
				return;
			}
		} while ( $more );
	}

	/**
	 * Deactivation hook. Never touches orders: the screen with the choice is the only path that moves them.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		wp_clear_scheduled_hook( Attachments::CRON_HOOK );
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		$message = self::cli_message( $network_wide );
		if ( '' !== $message ) {
			\WP_CLI::warning( $message );
		}
	}

	/**
	 * Warning for deactivation without the screen.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function cli_message( bool $network_wide ): string {
		if ( $network_wide ) {
			return __( 'Order Flow was deactivated for the whole network. Orders in its custom statuses were not moved and stay in their statuses; WooCommerce does not list them while the plugin is inactive. Activate the plugin again to see them.', 'order-flow-for-woocommerce' );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return '';
		}
		$total = Decisions::total( ( new Orders() )->counts() );
		if ( $total < 1 ) {
			return '';
		}
		return sprintf(
			/* translators: %s: number of orders. */
			_n(
				'Order Flow was deactivated. %s order is in a custom status; it was not moved and WooCommerce does not list it while the plugin is inactive. Activate the plugin again to see it, or use the Deactivate screen in the admin to move such orders to On hold.',
				'Order Flow was deactivated. %s orders are in custom statuses; they were not moved and WooCommerce does not list them while the plugin is inactive. Activate the plugin again to see them, or use the Deactivate screen in the admin to move such orders to On hold.',
				$total,
				'order-flow-for-woocommerce'
			),
			number_format_i18n( $total )
		);
	}
}
