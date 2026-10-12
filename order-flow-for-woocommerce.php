<?php
/**
 * Plugin Name:       Order Flow for WooCommerce
 * Plugin URI:        https://helpmybiz.org
 * Description:       Custom WooCommerce order statuses with transition rules, status history, status emails and PDF documents.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 * Author:            Andrew Rozumny
 * Author URI:        https://helpmybiz.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-flow-for-woocommerce
 * Domain Path:       /languages
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OFWC_VERSION', '0.1.0' );
define( 'OFWC_FILE', __FILE__ );
define( 'OFWC_PATH', plugin_dir_path( __FILE__ ) );
define( 'OFWC_URL', plugin_dir_url( __FILE__ ) );

require_once OFWC_PATH . 'includes/autoload.php';

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		( new \OrderFlowWC\Migration\Orders() )->restore_or_defer();
	}
);

\OrderFlowWC\Plugin::instance()->boot();
