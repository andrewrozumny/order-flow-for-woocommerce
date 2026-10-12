<?php
/**
 * Contract for tabs on the Order Flow admin page.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A tab of WooCommerce > Order Flow. Register it through the `ofwc_admin_tabs` filter.
 */
interface TabInterface {

	/**
	 * Tab slug used in the URL (`tab=` query argument).
	 */
	public function id(): string;

	/**
	 * Tab title.
	 */
	public function label(): string;

	/**
	 * Echo the tab content.
	 */
	public function render(): void;
}
