<?php
/**
 * Order Flow admin page under the WooCommerce menu.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab shell. Other classes add tabs through the `ofwc_admin_tabs` filter by appending an object
 * that implements TabInterface to the array, keyed by its id.
 *
 * Each tab implements TabInterface. Known tabs (statuses, transitions, documents, import-export) come first in that order; any other
 * tab follows in registration order. Missing known tabs get a placeholder.
 */
final class Page {

	public const SLUG       = 'ofwc';
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Known tabs in display order, with the label used for the placeholder.
	 *
	 * @return array<string, string>
	 */
	private static function known_tabs(): array {
		return array(
			'statuses'      => __( 'Statuses', 'order-flow-for-woocommerce' ),
			'transitions'   => __( 'Transitions', 'order-flow-for-woocommerce' ),
			'documents'     => __( 'Documents', 'order-flow-for-woocommerce' ),
			'import-export' => __( 'Import / Export', 'order-flow-for-woocommerce' ),
		);
	}

	/**
	 * Page hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * URL of the page or one of its tabs.
	 *
	 * @param string                    $tab  Tab slug; empty for the default tab.
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( string $tab = '', array $args = array() ): string {
		$query = array( 'page' => self::SLUG );
		if ( '' !== $tab ) {
			$query['tab'] = $tab;
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Remember a notice for the next page load of the current user.
	 *
	 * @param string $type    `success` or `error`.
	 * @param string $message Message text (plain).
	 */
	public static function flash( string $type, string $message ): void {
		set_transient(
			'ofwc_notice_' . get_current_user_id(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => $message,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Read and clear the pending notice.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public static function take_flash(): ?array {
		$key  = 'ofwc_notice_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! isset( $data['type'], $data['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'type'    => Value::string( $data['type'] ),
			'message' => Value::string( $data['message'] ),
		);
	}

	/**
	 * Redirect to a tab and stop.
	 *
	 * @param string                    $tab  Tab slug.
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function redirect( string $tab, array $args = array() ): void {
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Order Flow', 'order-flow-for-woocommerce' ),
			__( 'Order Flow', 'order-flow-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			$this->hook_suffix = $hook;
		}
	}

	/**
	 * Enqueue assets, only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'ofwc-admin', OFWC_URL . 'resources/admin.css', array( 'dashicons' ), OFWC_VERSION );
		wp_enqueue_script( 'ofwc-admin', OFWC_URL . 'resources/admin.js', array( 'jquery', 'wp-color-picker' ), OFWC_VERSION, true );

		/**
		 * Lets a tab enqueue its own assets on the Order Flow page.
		 *
		 * @param string $tab Active tab slug.
		 */
		do_action( 'ofwc_admin_enqueue', $this->current_tab_id() );
	}

	/**
	 * Registered tabs keyed by slug, in display order.
	 *
	 * @return array<string, TabInterface>
	 */
	public function tabs(): array {
		/**
		 * Filters the tabs of the Order Flow page.
		 *
		 * @param array<string, TabInterface> $tabs Tabs keyed by slug.
		 */
		$given = $this->valid_tabs( apply_filters( 'ofwc_admin_tabs', array() ) );

		$tabs = array();
		foreach ( self::known_tabs() as $id => $label ) {
			$tabs[ $id ] = $given[ $id ] ?? new PlaceholderTab( $id, $label );
		}
		foreach ( $given as $id => $tab ) {
			if ( ! isset( $tabs[ $id ] ) ) {
				$tabs[ $id ] = $tab;
			}
		}
		return $tabs;
	}

	/**
	 * Tab objects from a filter result, keyed by slug. Anything else a filter returns is dropped.
	 *
	 * @param mixed $filtered Filter result.
	 * @return array<string, TabInterface>
	 */
	private function valid_tabs( $filtered ): array {
		$given = array();
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $tab ) {
				if ( $tab instanceof TabInterface ) {
					$given[ $tab->id() ] = $tab;
				}
			}
		}
		return $given;
	}

	/**
	 * Active tab slug from the request, validated against the registered tabs.
	 */
	public function current_tab_id(): string {
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'statuses';
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$tabs    = $this->tabs();
		$current = $this->current_tab_id();

		echo '<div class="wrap ofwc-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Order Flow', 'order-flow-for-woocommerce' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->render_notice();

		echo '<nav class="nav-tab-wrapper ofwc-tabs" aria-label="' . esc_attr__( 'Order Flow sections', 'order-flow-for-woocommerce' ) . '">';
		foreach ( $tabs as $id => $tab ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $id ) ),
				$id === $current ? ' nav-tab-active' : '',
				$id === $current ? ' aria-current="page"' : '',
				esc_html( $tab->label() )
			);
		}
		echo '</nav>';

		echo '<div class="ofwc-tab-content">';
		$tabs[ $current ]->render();
		echo '</div></div>';
	}

	/**
	 * Print the pending flash notice, if any.
	 */
	private function render_notice(): void {
		$notice = self::take_flash();
		if ( null === $notice ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}
}
