<?php
/**
 * Transitions module: order screen box, save guard, bulk actions.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use Automattic\WooCommerce\Utilities\OrderUtil;
use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Repository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the transition matrix into wp-admin. Programmatic status changes are never blocked.
 */
final class Module {

	public const ACTION        = 'ofwc_transition';
	public const BULK_PREFIX   = 'ofwc_to_';
	public const SCRIPT_HANDLE = 'ofwc-order-flow-box';

	/**
	 * Statuses an order can have before it is really created; the matrix does not apply to them.
	 *
	 * @var string[]
	 */
	private const NEW_ORDER_STATUSES = array( 'auto-draft', 'draft', 'new', 'checkout-draft' );

	/**
	 * Transition rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Transition service.
	 *
	 * @var Service
	 */
	private Service $service;

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
		$this->rules      = new Rules( $this->repository );
		$this->service    = new Service( $this->rules );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		( new \OrderFlowWC\Admin\TransitionsTab( $this->rules ) )->register();

		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_transition' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'ofwc_admin_enqueue', array( $this, 'enqueue_rules_editor' ) );
		add_action( 'admin_notices', array( Notices::class, 'render' ) );

		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'guard_order_save' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'finish_order_save' ), 99 );

		foreach ( array( 'bulk_actions-woocommerce_page_wc-orders', 'bulk_actions-edit-shop_order' ) as $filter ) {
			add_filter( $filter, array( $this, 'add_bulk_actions' ) );
		}
		foreach ( array( 'handle_bulk_actions-woocommerce_page_wc-orders', 'handle_bulk_actions-edit-shop_order' ) as $filter ) {
			add_filter( $filter, array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * Admin-post handler for the Order Flow buttons.
	 */
	public function handle_transition(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $order_id, 'ofwc_nonce' );

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die( esc_html__( 'You do not have permission to change this order.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$to      = isset( $_POST['ofwc_to'] ) ? sanitize_key( wp_unslash( $_POST['ofwc_to'] ) ) : '';
		$comment = isset( $_POST['ofwc_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ofwc_comment'] ) ) : '';

		$result = $this->service->transition( $order, $to, $comment );
		if ( is_wp_error( $result ) ) {
			Notices::add( 'error', $result->get_error_message() );
		} else {
			Notices::add(
				'success',
				sprintf(
					/* translators: %s: status name. */
					__( 'Order status changed to %s.', 'order-flow-for-woocommerce' ),
					wc_get_order_status_name( $to )
				)
			);
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Register the Order Flow box on the order screen (HPOS and legacy).
	 *
	 * @param mixed $post_type Screen id or post type.
	 * @param mixed $order_or_post Order or post being edited.
	 */
	public function add_meta_box( $post_type, $order_or_post = null ): void {
		unset( $order_or_post );
		$screen = OrderUtil::get_order_admin_screen();
		if ( ! is_string( $post_type ) || $post_type !== $screen ) {
			return;
		}
		add_meta_box(
			'ofwc-order-flow',
			__( 'Order Flow', 'order-flow-for-woocommerce' ),
			array( new MetaBox( $this->rules ), 'render' ),
			$screen,
			'normal',
			'default'
		);
	}

	/**
	 * Load the box assets and the data for the status dropdown filter.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || OrderUtil::get_order_admin_screen() !== $screen->id ) {
			return;
		}
		wp_enqueue_style( 'ofwc-order-flow-box', OFWC_URL . 'resources/order-flow-box.css', array( 'dashicons' ), OFWC_VERSION );
		wp_enqueue_script( self::SCRIPT_HANDLE, OFWC_URL . 'resources/order-flow-box.js', array( 'jquery' ), OFWC_VERSION, true );

		$data = array(
			'adminPost' => admin_url( 'admin-post.php' ),
			'action'    => self::ACTION,
			'i18n'      => array(
				'commentNeeded' => __( 'Please enter a comment for this status change.', 'order-flow-for-woocommerce' ),
			),
		);

		$order = $this->request_order();
		if ( $order instanceof WC_Order && ! in_array( $order->get_status(), self::NEW_ORDER_STATUSES, true ) ) {
			$from     = $order->get_status();
			$user     = get_current_user_id();
			$allowed  = array( 'wc-' . $from );
			$required = array();
			foreach ( $this->rules->allowed_targets( $from, $user ) as $slug ) {
				$allowed[] = 'wc-' . $slug;
				if ( $this->rules->requires_comment( $from, $slug ) ) {
					$required[] = 'wc-' . $slug;
				}
			}
			$data['allowedStatuses'] = $allowed;
			$data['commentRequired'] = $required;
		}
		wp_localize_script( self::SCRIPT_HANDLE, 'ofwcFlow', $data );
	}

	/**
	 * Load the rules editor script on the Transitions tab.
	 *
	 * @param mixed $tab Active tab slug.
	 */
	public function enqueue_rules_editor( $tab ): void {
		if ( 'transitions' !== $tab ) {
			return;
		}
		wp_enqueue_style( 'ofwc-order-flow-box', OFWC_URL . 'resources/order-flow-box.css', array( 'dashicons' ), OFWC_VERSION );
		wp_enqueue_script( self::SCRIPT_HANDLE, OFWC_URL . 'resources/order-flow-box.js', array( 'jquery' ), OFWC_VERSION, true );
	}

	/**
	 * Before WooCommerce saves the order data: refuse a status change the user may not make.
	 *
	 * Runs on `woocommerce_process_shop_order_meta` before WooCommerce applies the posted status
	 * (priority 40). WooCommerce has already verified the nonce and the capability.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function guard_order_save( $order_id ): void {
		if ( ! is_numeric( $order_id ) || ! isset( $_POST['order_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$posted = sanitize_key( wp_unslash( $_POST['order_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
		$to     = 0 === strpos( $posted, 'wc-' ) ? substr( $posted, 3 ) : $posted;
		$from   = $order->get_status();
		if ( $to === $from || in_array( $from, self::NEW_ORDER_STATUSES, true ) ) {
			return;
		}

		$comment = isset( $_POST['ofwc_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ofwc_comment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
		$error   = $this->service->check( $from, $to, $comment, get_current_user_id() );
		if ( null === $error ) {
			Context::set( trim( $comment ), get_current_user_id() );
			return;
		}

		$_POST['order_status'] = 'wc-' . $from; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Keep the old status.
		Notices::add(
			'error',
			sprintf(
				/* translators: 1: target status name, 2: reason. */
				__( 'The order was not moved to %1$s. %2$s', 'order-flow-for-woocommerce' ),
				wc_get_order_status_name( $to ),
				$error->get_error_message()
			)
		);
	}

	/**
	 * After WooCommerce saved the order: forget the comment.
	 */
	public function finish_order_save(): void {
		Context::clear();
	}

	/**
	 * Add "Change status to X" for each custom status the user can reach from some status.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return mixed
	 */
	public function add_bulk_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		$user = get_current_user_id();
		foreach ( $this->repository->all() as $slug => $status ) {
			if ( $this->rules->can_reach( (string) $slug, $user ) ) {
				$actions[ self::BULK_PREFIX . $slug ] = sprintf(
					/* translators: %s: status name. */
					__( 'Change status to %s', 'order-flow-for-woocommerce' ),
					(string) $status['label']
				);
			}
		}
		return $actions;
	}

	/**
	 * Run one of our bulk actions.
	 *
	 * @param mixed $redirect Redirect URL.
	 * @param mixed $action   Chosen bulk action.
	 * @param mixed $ids      Selected order ids.
	 * @return mixed
	 */
	public function handle_bulk_action( $redirect, $action, $ids ) {
		if ( ! is_string( $action ) || 0 !== strpos( $action, self::BULK_PREFIX ) || ! is_array( $ids ) ) {
			return $redirect;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return $redirect;
		}
		$to = substr( $action, strlen( self::BULK_PREFIX ) );
		if ( null === $this->repository->get( $to ) ) {
			return $redirect;
		}

		$changed = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( ! $order instanceof WC_Order || true !== $this->service->transition( $order, $to ) ) {
				++$skipped;
				continue;
			}
			++$changed;
		}

		Notices::add(
			$skipped > 0 ? 'warning' : 'success',
			sprintf(
				/* translators: 1: status name, 2: changed orders, 3: skipped orders. */
				__( 'Change status to %1$s: %2$d changed, %3$d skipped (not allowed, already in this status or a comment is required).', 'order-flow-for-woocommerce' ),
				wc_get_order_status_name( $to ),
				$changed,
				$skipped
			)
		);
		return $redirect;
	}

	/**
	 * The order shown on the current order edit screen, from the request.
	 */
	private function request_order(): ?WC_Order {
		$id = 0;
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen lookup.
			$id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$order = $id > 0 ? wc_get_order( $id ) : false;
		return $order instanceof WC_Order ? $order : null;
	}
}
