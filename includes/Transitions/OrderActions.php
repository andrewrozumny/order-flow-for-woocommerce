<?php
/**
 * Status buttons and bulk actions of the order list, cut down to what the person can really do.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\Statuses\Slug;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce offers "mark as ..." buttons and bulk actions without asking whether the rules allow them. This
 * class takes out the ones the current user cannot apply, so the screen does not promise what the Guard refuses.
 * The Guard stays the enforcement; this is only honest buttons.
 */
final class OrderActions {

	private const MARK_PREFIX = 'mark_';

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
	 * Constructor.
	 *
	 * @param Rules   $rules   Transition rules.
	 * @param Service $service Transition service.
	 */
	public function __construct( Rules $rules, Service $service ) {
		$this->rules   = $rules;
		$this->service = $service;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		foreach ( array( 'bulk_actions-woocommerce_page_wc-orders', 'bulk_actions-edit-shop_order' ) as $filter ) {
			add_filter( $filter, array( $this, 'filter_bulk_actions' ), 20 );
		}
		add_filter( 'woocommerce_admin_order_actions', array( $this, 'filter_row_actions' ), 20, 2 );
		add_filter( 'woocommerce_admin_order_preview_actions', array( $this, 'filter_preview_actions' ), 20, 2 );
	}

	/**
	 * Remove the standard "Change status to ..." bulk actions the user cannot use for any order.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return mixed
	 */
	public function filter_bulk_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		$user = get_current_user_id();
		foreach ( array_keys( $actions ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, self::MARK_PREFIX ) && ! $this->rules->can_reach( Slug::strip( substr( $key, strlen( self::MARK_PREFIX ) ) ), $user ) ) {
				unset( $actions[ $key ] );
			}
		}
		return $actions;
	}

	/**
	 * Remove the status buttons of an order row that the user cannot apply to that order.
	 *
	 * @param mixed $actions Row actions keyed by name, each with a `url`.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public function filter_row_actions( $actions, $order ) {
		if ( ! is_array( $actions ) || ! $order instanceof WC_Order ) {
			return $actions;
		}
		return $this->without_refused( $actions, $order );
	}

	/**
	 * Remove the status buttons of the order preview window that the user cannot apply to that order.
	 *
	 * @param mixed $actions Preview actions: groups of `actions`.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public function filter_preview_actions( $actions, $order ) {
		if ( ! is_array( $actions ) || ! $order instanceof WC_Order ) {
			return $actions;
		}
		$result = array();
		foreach ( $actions as $group => $data ) {
			if ( is_array( $data ) && isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
				$data['actions'] = $this->without_refused( $data['actions'], $order );
			}
			$result[ $group ] = $data;
		}
		return $result;
	}

	/**
	 * Entries that change the status are kept only when the change is allowed for the user, with no comment.
	 *
	 * @param array<mixed, mixed> $buttons Buttons, each an array with a `url`.
	 * @param WC_Order            $order   Order.
	 * @return array<mixed, mixed>
	 */
	private function without_refused( array $buttons, WC_Order $order ): array {
		$from = Slug::strip( $order->get_status() );
		$user = get_current_user_id();
		foreach ( $buttons as $key => $button ) {
			$target = is_array( $button ) && isset( $button['url'] ) && is_string( $button['url'] ) ? $this->target_of( $button['url'] ) : '';
			if ( '' !== $target && null !== $this->service->check( $from, $target, '', $user ) ) {
				unset( $buttons[ $key ] );
			}
		}
		return $buttons;
	}

	/**
	 * The status a WooCommerce "mark order status" link leads to, empty for any other link.
	 *
	 * @param string $url Button link.
	 */
	private function target_of( string $url ): string {
		$query = wp_parse_url( wp_specialchars_decode( $url ), PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return '';
		}
		parse_str( $query, $args );
		if ( ( $args['action'] ?? '' ) !== 'woocommerce_mark_order_status' || ! is_string( $args['status'] ?? null ) ) {
			return '';
		}
		return Slug::strip( sanitize_key( $args['status'] ) );
	}
}
