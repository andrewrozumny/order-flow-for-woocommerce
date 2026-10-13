<?php
/**
 * Transitions module: order screen box, save guard, bulk actions.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use Automattic\WooCommerce\Utilities\OrderUtil;
use OrderFlowWC\Admin\TransitionsTab;
use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Statuses\Slug;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the transition matrix into wp-admin: the Order Flow box, the order form, bulk actions, and the Guard
 * that applies the rules to every other way a person can change a status. The system is never blocked.
 */
final class Module {

	public const ACTION           = 'ofwc_transition';
	public const SCRIPT_HANDLE    = 'ofwc-order-flow-box';
	public const NOTIFY_FIELD     = 'ofwc_notify_customer';
	public const COMMENT_FIELD    = 'ofwc_comment';
	public const NEW_ORDER_STATUS = 'new';

	/**
	 * Statuses an order can have before it is really created. A change out of them is checked as a change
	 * out of "new".
	 *
	 * @var string[]
	 */
	public const NEW_ORDER_STATUSES = array( 'auto-draft', 'draft', self::NEW_ORDER_STATUS, 'checkout-draft' );

	/**
	 * Statuses WooCommerce uses for its own bookkeeping. Moving an order to one of them is not a business decision, so
	 * the rules never apply to it.
	 *
	 * @var string[]
	 */
	public const INTERNAL_STATUSES = array( 'auto-draft', 'draft', 'checkout-draft', 'trash' );

	/**
	 * The status the rules see as the starting point of a change: orders that are not created yet start from "new".
	 *
	 * @param string $status Status slug without prefix; empty for an order that was never saved.
	 */
	public static function origin( string $status ): string {
		return '' === $status || in_array( $status, self::NEW_ORDER_STATUSES, true ) ? self::NEW_ORDER_STATUS : $status;
	}

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
		$this->service    = new Service( $this->rules, new OrderLock() );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		Rules::watch();
		( new TransitionsTab( $this->rules ) )->register();
		( new Guard( $this->service ) )->register();
		( new OrderActions( $this->rules, $this->service ) )->register();

		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_transition' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'ofwc_admin_enqueue', array( $this, 'enqueue_rules_editor' ) );
		add_action( 'admin_notices', array( Notices::class, 'render' ) );

		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'prepare_order_save' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'finish_order_save' ), 99 );

		( new BulkActions( $this->repository, $this->rules, $this->service ) )->register();
	}

	/**
	 * Admin-post handler for the Order Flow buttons.
	 */
	public function handle_transition(): void {
		$order_id = isset( $_POST['order_id'] ) && is_string( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $order_id, 'ofwc_nonce' );

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce meta capability.
			wp_die( esc_html__( 'You do not have permission to change this order.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$to      = isset( $_POST['ofwc_to'] ) && is_string( $_POST['ofwc_to'] ) ? sanitize_key( wp_unslash( $_POST['ofwc_to'] ) ) : '';
		$comment = isset( $_POST[ self::COMMENT_FIELD ] ) && is_string( $_POST[ self::COMMENT_FIELD ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::COMMENT_FIELD ] ) ) : '';
		$notify  = isset( $_POST[ self::NOTIFY_FIELD ] );

		$result = $this->service->transition( $order, $to, $comment, null, $notify );
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
		if ( ! class_exists( OrderUtil::class ) ) {
			return;
		}
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
		if ( null === $screen || ! class_exists( OrderUtil::class ) || OrderUtil::get_order_admin_screen() !== $screen->id ) {
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
		if ( $order instanceof WC_Order ) {
			$from     = self::origin( Slug::strip( $order->get_status() ) );
			$user     = get_current_user_id();
			$allowed  = array( Slug::prefixed( $from ) );
			$required = array();
			foreach ( $this->rules->allowed_targets( $from, $user ) as $slug ) {
				$allowed[] = Slug::prefixed( $slug );
				if ( $this->rules->requires_comment( $from, $slug ) ) {
					$required[] = Slug::prefixed( $slug );
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
	 * Before WooCommerce applies the posted status: hand the comment of the form to the code that checks and
	 * records the change. The rules themselves are applied by the Guard when the order is saved.
	 *
	 * Runs on `woocommerce_process_shop_order_meta` (priority 30), before WooCommerce applies the posted status
	 * (priority 40). WooCommerce has already verified the nonce and the capability.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function prepare_order_save( $order_id ): void {
		$posted = isset( $_POST['order_status'] ) && is_string( $_POST['order_status'] ) ? Slug::strip( sanitize_key( wp_unslash( $_POST['order_status'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
		if ( ! is_numeric( $order_id ) || null === $posted ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! in_array( $posted, self::INTERNAL_STATUSES, true ) && ! array_key_exists( Slug::prefixed( $posted ), wc_get_order_statuses() ) ) {
			$this->refuse_unknown_status( $order, $posted );
			return;
		}
		if ( Slug::strip( $order->get_status() ) === $posted ) {
			return;
		}

		$comment = isset( $_POST[ self::COMMENT_FIELD ] ) && is_string( $_POST[ self::COMMENT_FIELD ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::COMMENT_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
		Context::set( trim( $comment ), get_current_user_id(), isset( $_POST[ self::NOTIFY_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
	}

	/**
	 * A form that names a status nobody registered (a stale tab, a status that was deleted, a forged request). WooCommerce
	 * would quietly save the order as "pending". The form is changed to keep the status the order has, and the person is
	 * told.
	 *
	 * @param WC_Order $order  Order being saved.
	 * @param string   $posted Posted status slug that does not exist.
	 */
	private function refuse_unknown_status( WC_Order $order, string $posted ): void {
		$current = Slug::strip( $order->get_status() );
		$keep    = in_array( $current, self::NEW_ORDER_STATUSES, true ) ? 'pending' : $current;

		$_POST['order_status'] = Slug::prefixed( $keep ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook.
		Notices::add(
			'error',
			sprintf(
				/* translators: %s: the status slug from the form. */
				__( 'The status "%s" does not exist, so the order status was not changed.', 'order-flow-for-woocommerce' ),
				$posted
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
	 * The order shown on the current order edit screen, from the request.
	 */
	private function request_order(): ?WC_Order {
		$id = 0;
		if ( isset( $_GET['id'] ) && is_string( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen lookup.
			$id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen lookup.
		} elseif ( isset( $_GET['post'] ) && is_string( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen lookup.
			$id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen lookup.
		}
		$order = $id > 0 ? wc_get_order( $id ) : false;
		return $order instanceof WC_Order ? $order : null;
	}
}
