<?php
/**
 * Stand-in for a tab that another module has not registered.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a short "coming soon" notice.
 */
final class PlaceholderTab implements TabInterface {

	/**
	 * Tab slug.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Tab title.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Constructor.
	 *
	 * @param string $id    Tab slug.
	 * @param string $label Tab title.
	 */
	public function __construct( string $id, string $label ) {
		$this->id    = $id;
		$this->label = $label;
	}

	/**
	 * Tab slug.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Tab title.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Render the placeholder.
	 */
	public function render(): void {
		echo '<p class="description">' . esc_html__( 'This section is not available yet.', 'order-flow-for-woocommerce' ) . '</p>';
	}
}
