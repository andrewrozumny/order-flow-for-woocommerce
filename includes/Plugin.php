<?php
/**
 * Plugin bootstrap.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
	}

	/**
	 * Whether the Pro layer is active. Free build always returns false unless filtered.
	 */
	public static function is_pro(): bool {
		return (bool) apply_filters( 'ofwc_is_pro', false );
	}

	/**
	 * Hook everything up.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		( new Statuses\Registrar() )->register();
		( new Admin\Page() )->register();
		( new Admin\StatusesTab() )->register();
		( new Transitions\Module() )->register();
		( new History\Module() )->register();
		( new Emails\Module() )->register();
		( new Pdf\Module() )->register();
		( new Admin\ImportExport() )->register();
		( new Admin\Deactivation() )->register();
		( new Migration\Module() )->register();
	}

	/**
	 * Whether WooCommerce is loaded. Called on plugins_loaded, when every active plugin, network-wide ones
	 * included, has been included, so the class check is reliable on single sites and on a network.
	 */
	private function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether WooCommerce is switched on (site or network) even though it did not load, for example paused by
	 * the recovery mode after a fatal error.
	 */
	private function woocommerce_switched_on(): bool {
		$plugin = 'woocommerce/woocommerce.php';
		if ( in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		return function_exists( 'is_multisite' ) && is_multisite() && isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $plugin ] );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$message = $this->woocommerce_switched_on()
			? __( 'Order Flow for WooCommerce is paused because WooCommerce is active but did not load. It continues as soon as WooCommerce loads again.', 'order-flow-for-woocommerce' )
			: __( 'Order Flow for WooCommerce needs WooCommerce to be installed and active.', 'order-flow-for-woocommerce' );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
