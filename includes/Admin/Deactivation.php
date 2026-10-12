<?php
/**
 * Deactivation screen for sites that have orders in custom statuses.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Migration\Decisions;
use OrderFlowWC\Migration\Orders;
use OrderFlowWC\Statuses\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * While the plugin is inactive WooCommerce does not know the custom statuses, so orders in them
 * disappear from the order list (they are not lost). When at least one order sits in a custom status,
 * the "Deactivate" link leads to a page that explains this and offers to park those orders in
 * On hold until the plugin is activated again.
 */
final class Deactivation {

	public const PAGE       = 'ofwc-deactivate';
	public const ACTION     = 'ofwc_deactivate';
	public const CAPABILITY = 'activate_plugins';

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Order migration.
	 *
	 * @var Orders
	 */
	private Orders $orders;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 * @param Orders|null     $orders     Order migration.
	 */
	public function __construct( ?Repository $repository = null, ?Orders $orders = null ) {
		$this->repository = $repository ?? new Repository();
		$this->orders     = $orders ?? new Orders( $this->repository );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( OFWC_FILE ), array( $this, 'filter_action_links' ), 20 );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_init', array( $this->orders, 'maybe_run_pending' ) );
	}

	/**
	 * URL of the deactivation screen.
	 */
	public static function url(): string {
		return add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
	}

	/**
	 * Point the Deactivate link to our screen when it matters.
	 *
	 * @param mixed $links Action links.
	 * @return mixed
	 */
	public function filter_action_links( $links ) {
		if ( ! is_array( $links ) || ! isset( $links['deactivate'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return $links;
		}
		if ( ! Decisions::needs_screen( $this->orders->counts() ) ) {
			return $links;
		}
		$links['deactivate'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::url() ),
			esc_attr__( 'Deactivate Order Flow for WooCommerce', 'order-flow-for-woocommerce' ),
			esc_html__( 'Deactivate', 'order-flow-for-woocommerce' )
		);
		return $links;
	}

	/**
	 * Register the hidden page.
	 */
	public function add_page(): void {
		add_submenu_page(
			'options.php',
			__( 'Deactivate Order Flow', 'order-flow-for-woocommerce' ),
			'',
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$counts   = $this->orders->counts();
		$statuses = $this->repository->all();

		echo '<div class="wrap ofwc-wrap">';
		echo '<h1>' . esc_html__( 'Deactivate Order Flow for WooCommerce', 'order-flow-for-woocommerce' ) . '</h1>';

		if ( ! Decisions::needs_screen( $counts ) ) {
			echo '<p>' . esc_html__( 'No orders are in a custom status, so the plugin can be deactivated right away.', 'order-flow-for-woocommerce' ) . '</p>';
			printf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a></p></div>',
				esc_url( wp_nonce_url( admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( plugin_basename( OFWC_FILE ) ) ), 'deactivate-plugin_' . plugin_basename( OFWC_FILE ) ) ),
				esc_html__( 'Deactivate', 'order-flow-for-woocommerce' )
			);
			return;
		}

		echo '<p>' . esc_html__( 'These orders are in a status created by this plugin:', 'order-flow-for-woocommerce' ) . '</p>';
		echo '<table class="wp-list-table widefat striped" style="max-width:480px"><thead><tr><th scope="col">' . esc_html__( 'Status', 'order-flow-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Orders', 'order-flow-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $counts as $slug => $count ) {
			$label = isset( $statuses[ $slug ] ) ? $statuses[ $slug ]['label'] : $slug;
			printf( '<tr><td>%1$s</td><td>%2$s</td></tr>', esc_html( $label ), esc_html( (string) $count ) );
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION, '_ofwc_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<fieldset style="margin:1.5em 0"><legend class="screen-reader-text">' . esc_html__( 'What should happen to these orders?', 'order-flow-for-woocommerce' ) . '</legend>';
		echo '<p><label><input type="radio" name="choice" value="' . esc_attr( Decisions::CHOICE_KEEP ) . '" checked> <strong>' . esc_html__( 'Keep orders in their statuses', 'order-flow-for-woocommerce' ) . '</strong></label><br>';
		echo '<span class="description" style="margin-left:24px;display:inline-block">' . esc_html__( 'While the plugin is inactive WooCommerce does not know these statuses, so the orders will be missing from the usual order lists. The orders are not lost: they come back as soon as you activate the plugin again.', 'order-flow-for-woocommerce' ) . '</span></p>';
		echo '<p><label><input type="radio" name="choice" value="' . esc_attr( Decisions::CHOICE_MOVE ) . '"> <strong>' . esc_html__( 'Move them to On hold now and restore them when I reactivate', 'order-flow-for-woocommerce' ) . '</strong></label><br>';
		echo '<span class="description" style="margin-left:24px;display:inline-block">' . esc_html__( 'Every order gets a note, is moved to On hold and stays visible in WooCommerce. When you activate the plugin again each one returns to its previous status. No emails are sent for these moves.', 'order-flow-for-woocommerce' ) . '</span></p>';
		echo '</fieldset>';
		submit_button( __( 'Deactivate plugin', 'order-flow-for-woocommerce' ), 'primary', 'submit', false );
		printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( admin_url( 'plugins.php' ) ), esc_html__( 'Cancel', 'order-flow-for-woocommerce' ) );
		echo '</form></div>';
	}

	/**
	 * Apply the choice and deactivate the plugin.
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, '_ofwc_nonce' );

		$choice = Decisions::choice( isset( $_POST['choice'] ) && is_string( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '' );
		if ( Decisions::CHOICE_MOVE === $choice ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large shops may need more time.
			}
			$this->orders->move_to_on_hold();
		}

		deactivate_plugins( plugin_basename( OFWC_FILE ) );
		wp_safe_redirect( add_query_arg( 'deactivate', 'true', admin_url( 'plugins.php' ) ) );
		exit;
	}
}
