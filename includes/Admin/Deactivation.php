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
 *
 * Moving runs in batches on a progress page, about twenty seconds per request, and the plugin is
 * deactivated after the last batch. The bulk action "Deactivate" on the plugins list leads here too.
 * Every other way to deactivate (WP-CLI, deployment scripts, a network) leaves the orders alone.
 */
final class Deactivation {

	public const PAGE       = 'ofwc-deactivate';
	public const ACTION     = 'ofwc_deactivate';
	public const RUN_ACTION = 'ofwc_deactivate_run';
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
		add_action( 'admin_post_' . self::RUN_ACTION, array( $this, 'handle_run' ) );
		add_action( 'load-plugins.php', array( $this, 'intercept_bulk_deactivate' ) );
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

		if ( isset( $_GET['progress'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, the batch itself is protected by a nonce.
			$this->render_progress();
			return;
		}

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
		echo '<span class="description" style="margin-left:24px;display:inline-block">' . esc_html__( 'While the plugin is inactive WooCommerce does not know these statuses, so the orders will be missing from the usual order lists. The orders are not lost: they come back as soon as you activate the plugin again. If you delete the plugin instead, they are moved to On hold.', 'order-flow-for-woocommerce' ) . '</span></p>';
		echo '<p><label><input type="radio" name="choice" value="' . esc_attr( Decisions::CHOICE_MOVE ) . '"> <strong>' . esc_html__( 'Move them to On hold now and restore them when I reactivate', 'order-flow-for-woocommerce' ) . '</strong></label><br>';
		echo '<span class="description" style="margin-left:24px;display:inline-block">' . esc_html__( 'Every order gets a note, is moved to On hold and stays visible in WooCommerce. Nothing else changes: no emails are sent and stock levels, sales counts and coupon usage stay as they are. Large shops see a progress page; keep it open until it finishes. When you activate the plugin again, each order that is still in On hold returns to its previous status in the background. An order whose status you changed in the meantime keeps its new status.', 'order-flow-for-woocommerce' ) . '</span></p>';
		echo '</fieldset>';
		submit_button( __( 'Deactivate plugin', 'order-flow-for-woocommerce' ), 'primary', 'submit', false );
		printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( admin_url( 'plugins.php' ) ), esc_html__( 'Cancel', 'order-flow-for-woocommerce' ) );
		echo '</form></div>';
	}

	/**
	 * Bulk "Deactivate" on the plugins list: when this plugin is in the selection and orders sit in custom statuses,
	 * deactivate the other selected plugins as usual and continue on the screen with the choice.
	 */
	public function intercept_bulk_deactivate(): void {
		if ( is_network_admin() || ! current_user_can( 'deactivate_plugins' ) ) {
			return;
		}
		$action = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			$value = isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below once the action is known.
			if ( '' !== $value && '-1' !== $value ) {
				$action = $value;
				break;
			}
		}
		if ( 'deactivate-selected' !== $action ) {
			return;
		}
		check_admin_referer( 'bulk-plugins' );

		$checked = array();
		if ( isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) {
			$posted = map_deep( wp_unslash( $_POST['checked'] ), 'sanitize_text_field' );
			foreach ( is_array( $posted ) ? $posted : array() as $plugin ) {
				if ( is_string( $plugin ) ) {
					$checked[] = $plugin;
				}
			}
		}
		$own = plugin_basename( OFWC_FILE );
		if ( ! in_array( $own, $checked, true ) || ! Decisions::needs_screen( $this->orders->counts() ) ) {
			return;
		}
		$others = array_values( array_diff( $checked, array( $own ) ) );
		if ( array() !== $others ) {
			deactivate_plugins( $others );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Apply the choice: keep deactivates at once, move starts the batches.
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, '_ofwc_nonce' );

		$choice = Decisions::choice( isset( $_POST['choice'] ) && is_string( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '' );
		if ( Decisions::CHOICE_MOVE === $choice && Decisions::needs_screen( $this->orders->counts() ) ) {
			$this->orders->begin_parking();
			wp_safe_redirect( $this->run_url() );
			exit;
		}

		$this->finish();
	}

	/**
	 * One batch of the move, then back to the progress page, or deactivate after the last batch.
	 */
	public function handle_run(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::RUN_ACTION );

		$result = $this->orders->park_batch();
		if ( 0 === $result['remaining'] && 0 === $result['failed'] ) {
			$this->finish();
		}
		wp_safe_redirect( add_query_arg( 'progress', '1', self::url() ) );
		exit;
	}

	/**
	 * URL that runs the next batch.
	 */
	private function run_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RUN_ACTION ), self::RUN_ACTION );
	}

	/**
	 * Progress page. It continues by itself and also offers a link, so it works without JavaScript.
	 */
	private function render_progress(): void {
		$progress  = $this->orders->parking_progress();
		$remaining = Decisions::total( $this->orders->counts() ) - count( $progress['failed'] );
		$failed    = count( $progress['failed'] );

		echo '<div class="wrap ofwc-wrap">';
		echo '<h1>' . esc_html__( 'Deactivate Order Flow for WooCommerce', 'order-flow-for-woocommerce' ) . '</h1>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: orders moved so far, 2: orders in custom statuses when the move started. */
					__( 'Moved %1$s of %2$s orders to On hold.', 'order-flow-for-woocommerce' ),
					number_format_i18n( $progress['moved'] ),
					number_format_i18n( max( $progress['total'], $progress['moved'] ) )
				)
			)
		);
		if ( $remaining > 0 ) {
			echo '<p>' . esc_html__( 'Keep this page open. The plugin is deactivated after the last order.', 'order-flow-for-woocommerce' ) . '</p>';
			printf( '<p><a class="button button-primary" id="ofwc-continue" href="%1$s">%2$s</a></p>', esc_url( $this->run_url() ), esc_html__( 'Continue', 'order-flow-for-woocommerce' ) );
			wp_print_inline_script_tag( 'window.setTimeout(function(){var a=document.getElementById("ofwc-continue");if(a){window.location.href=a.href;}},800);' );
			echo '<noscript><meta http-equiv="refresh" content="3;url=' . esc_url( $this->run_url() ) . '"></noscript>';
		} elseif ( $failed > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of orders. */
					_n( '%s order could not be moved and stays in its status.', '%s orders could not be moved and stay in their statuses.', $failed, 'order-flow-for-woocommerce' ),
					number_format_i18n( $failed )
				)
			) . '</p>';
			printf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( plugin_basename( OFWC_FILE ) ) ), 'deactivate-plugin_' . plugin_basename( OFWC_FILE ) ) ),
				esc_html__( 'Deactivate anyway', 'order-flow-for-woocommerce' ),
				esc_url( admin_url( 'plugins.php' ) ),
				esc_html__( 'Cancel', 'order-flow-for-woocommerce' )
			);
		}
		echo '</div>';
	}

	/**
	 * Deactivate the plugin and go back to the plugins list.
	 */
	private function finish(): void {
		delete_transient( Orders::PROGRESS );
		deactivate_plugins( plugin_basename( OFWC_FILE ) );
		wp_safe_redirect( add_query_arg( 'deactivate', 'true', admin_url( 'plugins.php' ) ) );
		exit;
	}
}
