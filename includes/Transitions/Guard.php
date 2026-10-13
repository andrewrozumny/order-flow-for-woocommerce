<?php
/**
 * Enforces the transition rules on every status change a person makes.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Slug;
use OrderFlowWC\Value;
use ReflectionProperty;
use WC_Order;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One checkpoint for all paths: the order is checked when WooCommerce is about to save it
 * (`woocommerce_before_order_object_save`), which every route passes through: the Order Flow box, the status
 * dropdown, bulk actions (ours and the standard mark_* ones), the order list buttons (admin-ajax), the REST API
 * with the login cookie, and a new order created in a status.
 *
 * When the person may not make the change, the status goes back to what it was and the person is told. The
 * order's other changes are saved as usual.
 *
 * Not checked, on purpose:
 *
 * - the system: gateways, webhooks, cron, Action Scheduler, WP-CLI, REST keys and application passwords (see Actor);
 * - a refund that sets the order to Refunded. The money is already back with the customer;
 * - Order Flow's own bulk work (parking and restoring orders, deleting a status), which runs through system().
 *
 * Administrators (`manage_options`) pass the role limits of the rules, but a rule that asks for a comment asks it of
 * every person, administrators included.
 */
final class Guard {

	/**
	 * Depth of nested system() calls.
	 *
	 * @var int
	 */
	private static int $suspended = 0;

	/**
	 * Orders refused in this request, by order id.
	 *
	 * @var array<int, true>
	 */
	private static array $reported = array();

	/**
	 * Orders being fully refunded right now, by order id. Their move to Refunded is not checked.
	 *
	 * @var array<int, true>
	 */
	private static array $refunding = array();

	/**
	 * Notices shown in full before the rest is summed up in one line.
	 */
	private const MAX_NOTICES = 3;

	/**
	 * Transition service, which holds the single rules check.
	 *
	 * @var Service
	 */
	private Service $service;

	/**
	 * Constructor.
	 *
	 * @param Service $service Transition service.
	 */
	public function __construct( Service $service ) {
		$this->service = $service;
	}

	/**
	 * Run work as the system: status changes inside the callback are not checked against the rules.
	 *
	 * @param callable $callback Work to do.
	 */
	public static function system( callable $callback ): void {
		++self::$suspended;
		try {
			$callback();
		} finally {
			--self::$suspended;
		}
	}

	/**
	 * Forget the per-request state (tests).
	 */
	public static function reset(): void {
		self::$suspended = 0;
		self::$reported  = array();
		self::$refunding = array();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_order_object_save', array( $this, 'inspect' ), 5 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'check_rest_request' ), 10, 3 );
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'allow_refund' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'end_refund' ) );
	}

	/**
	 * A full refund is about to move the order to Refunded. The money is already back with the customer, so the
	 * move is not refused.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function allow_refund( $order_id ): void {
		if ( is_numeric( $order_id ) ) {
			self::$refunding[ (int) $order_id ] = true;
		}
	}

	/**
	 * The refund is done.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function end_refund( $order_id ): void {
		if ( is_numeric( $order_id ) ) {
			unset( self::$refunding[ (int) $order_id ] );
		}
	}

	/**
	 * Before WooCommerce saves an order: put the old status back when the person may not change it.
	 *
	 * @param mixed $order Order about to be saved.
	 */
	public function inspect( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$denied = $this->denial( $order );
		if ( null === $denied ) {
			return;
		}

		$this->revert( $order, $denied['from'] );
		$this->report( $order, $denied['to'], $denied['error'] );
	}

	/**
	 * REST requests from the browser: answer 403 before anything is changed, instead of saving the order with
	 * its old status and a normal-looking reply.
	 *
	 * @param mixed $response Response or error so far.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public function check_rest_request( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request || $response instanceof WP_Error || self::$suspended > 0 || 1 !== preg_match( '#^/wc/v\d+/orders(?:/(\d+))?$#', $request->get_route(), $match ) ) {
			return $response;
		}
		$status = $request->get_param( 'status' );
		if ( ! is_string( $status ) || '' === $status || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) || ! $this->enforced() ) {
			return $response;
		}

		$order = isset( $match[1] ) ? wc_get_order( (int) $match[1] ) : false;
		$from  = $order instanceof WC_Order ? Module::origin( Slug::strip( $order->get_status() ) ) : Module::NEW_ORDER_STATUS;
		$to    = Slug::strip( $status );
		if ( $from === $to || in_array( $to, Module::INTERNAL_STATUSES, true ) ) {
			return $response;
		}
		$error = $this->service->check( $from, $to, Context::comment(), get_current_user_id() );
		if ( null === $error ) {
			return $response;
		}
		return new WP_Error( 'ofwc_transition_not_allowed', $error->get_error_message(), array( 'status' => 403 ) );
	}

	/**
	 * The refused change of an order, or null when it is fine.
	 *
	 * @param WC_Order $order Order about to be saved.
	 * @return array{from: string, to: string, error: WP_Error}|null
	 */
	private function denial( WC_Order $order ): ?array {
		if ( self::$suspended > 0 || isset( self::$refunding[ $order->get_id() ] ) || 'shop_order' !== $order->get_type() || ! array_key_exists( 'status', $order->get_changes() ) || ! $this->enforced( $order ) ) {
			return null;
		}

		$stored = Slug::strip( Value::string( $order->get_data()['status'] ?? '' ) );
		$to     = Slug::strip( $order->get_status() );
		if ( $stored === $to || in_array( $to, Module::INTERNAL_STATUSES, true ) ) {
			return null;
		}
		$error = $this->service->check( Module::origin( $stored ), $to, Context::comment(), get_current_user_id() );
		return null === $error ? null : array(
			'from'  => $stored,
			'to'    => $to,
			'error' => $error,
		);
	}

	/**
	 * Whether the rules apply to the current request: a person at the keyboard. Administrators are let through the role
	 * limits by Rules::is_allowed(); the required comment still reaches them.
	 *
	 * @param WC_Order|null $order Order being saved, when known.
	 */
	private function enforced( ?WC_Order $order = null ): bool {
		$apply = Actor::is_person();

		/**
		 * Filters whether the transition rules are enforced for the current change.
		 *
		 * @param bool          $apply Whether the request is a person at the keyboard.
		 * @param WC_Order|null $order The order being saved, null for a REST pre-check.
		 */
		return Value::bool( apply_filters( 'ofwc_enforce_transition_rules', $apply, $order ) );
	}

	/**
	 * Put the stored status back, together with the dates WooCommerce sets for the refused status.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $from  Stored status slug; empty for an order that was never saved.
	 */
	private function revert( WC_Order $order, string $from ): void {
		$stored = $order->get_data();
		$target = '' === $from ? Value::string( apply_filters( 'woocommerce_default_order_status', 'pending' ), 'pending' ) : $from; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.

		$order->set_status( $target, false );
		if ( array_key_exists( 'date_paid', $order->get_changes() ) ) {
			$order->set_date_paid( self::timestamp( $stored['date_paid'] ?? null ) );
		}
		if ( array_key_exists( 'date_completed', $order->get_changes() ) ) {
			$order->set_date_completed( self::timestamp( $stored['date_completed'] ?? null ) );
		}

		// WooCommerce remembers the refused change and would announce it after the save: emails, stock, history.
		try {
			( new ReflectionProperty( $order, 'status_transition' ) )->setValue( $order, false );
		} catch ( \ReflectionException $e ) {
			unset( $e ); // A WooCommerce version without that property has nothing to clear.
		}
	}

	/**
	 * A stored date as a timestamp, null for none.
	 *
	 * @param mixed $date WC_DateTime, timestamp or empty.
	 */
	private static function timestamp( $date ): ?int {
		if ( $date instanceof \DateTimeInterface ) {
			return $date->getTimestamp();
		}
		return is_int( $date ) ? $date : null;
	}

	/**
	 * Tell the person why the status did not change.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $to    Refused status slug.
	 * @param WP_Error $error Reason.
	 */
	private function report( WC_Order $order, string $to, WP_Error $error ): void {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		$id = $order->get_id();
		if ( isset( self::$reported[ $id ] ) ) {
			return;
		}
		self::$reported[ $id ] = true;
		$count                 = count( self::$reported );

		if ( $count <= self::MAX_NOTICES ) {
			Notices::add(
				'error',
				sprintf(
					/* translators: 1: order number, 2: target status name, 3: reason. */
					__( 'Order %1$s was not moved to %2$s. %3$s', 'order-flow-for-woocommerce' ),
					$order->get_order_number(),
					wc_get_order_status_name( $to ),
					$error->get_error_message()
				)
			);
		} elseif ( self::MAX_NOTICES + 1 === $count ) {
			add_action( 'shutdown', array( self::class, 'report_overflow' ) );
		}
	}

	/**
	 * At the end of the request: one line for the refused orders that got no notice of their own.
	 */
	public static function report_overflow(): void {
		$more = count( self::$reported ) - self::MAX_NOTICES;
		if ( $more > 0 ) {
			Notices::add(
				'error',
				sprintf(
					/* translators: %s: number of orders. */
					_n( '%s more order was not moved either.', '%s more orders were not moved either.', $more, 'order-flow-for-woocommerce' ),
					number_format_i18n( $more )
				)
			);
		}
	}
}
