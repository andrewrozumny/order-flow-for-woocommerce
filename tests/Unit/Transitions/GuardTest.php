<?php
/**
 * Guard tests: the one checkpoint for the transition rules.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use OrderFlowWC\History\Context;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Guard;
use OrderFlowWC\Transitions\Notices;
use OrderFlowWC\Transitions\Service;
use WC_Order;

/**
 * Each test is one of the ways a status can change, as a shop manager in wp-admin.
 *
 * @covers \OrderFlowWC\Transitions\Guard
 * @covers \OrderFlowWC\Transitions\Module::origin
 */
final class GuardTest extends TestCase {

	use RulesFixture;

	/**
	 * Queued notice messages.
	 *
	 * @var string[]
	 */
	private array $notices = array();

	/**
	 * A shop manager (user 2) who may only move proof to packed.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_rules( array( $this->rule( 'proof', 'packed', array( 'shop_manager' ) ) ) );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $slug ): string {
				return ucfirst( $slug );
			}
		);
		Functions\when( 'number_format_i18n' )->returnArg();
		$this->notices = array();
		Functions\when( 'get_user_meta' )->justReturn( array() );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user, string $key, array $items ): bool {
				unset( $user, $key );
				foreach ( $items as $item ) {
					$this->notices[] = $item['message'];
				}
				return true;
			}
		);
		Context::clear();
	}

	/**
	 * An order the Guard is about to see saved.
	 *
	 * @param string               $stored  Status in the database; empty for a new order.
	 * @param string               $incoming Status being saved.
	 * @param array<string, mixed> $changes Extra changed props.
	 * @return \Mockery\MockInterface&WC_Order
	 */
	private function order( string $stored, string $incoming, array $changes = array() ) {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_type' )->andReturn( 'shop_order' );
		$order->shouldReceive( 'get_id' )->andReturn( 41 );
		$order->shouldReceive( 'get_order_number' )->andReturn( '41' );
		$order->shouldReceive( 'get_status' )->andReturn( $incoming );
		$order->shouldReceive( 'get_changes' )->andReturn( array_merge( array( 'status' => $incoming ), $changes ) );
		$order->shouldReceive( 'get_data' )->andReturn(
			array(
				'status'         => $stored,
				'date_paid'      => null,
				'date_completed' => null,
			)
		);
		return $order;
	}

	/**
	 * The guard under test.
	 */
	private function guard(): Guard {
		return new Guard( new Service() );
	}

	/**
	 * The four ways round the rules in wp-admin all arrive here as a save with a new status: a shop manager
	 * moving an order out of "proof" to Completed (the standard bulk action and the order list button) is refused.
	 */
	public function test_refused_change_puts_the_old_status_back_and_tells_the_person(): void {
		$order = $this->order( 'proof', 'completed' );
		$order->shouldReceive( 'set_status' )->once()->with( 'proof', false );

		$this->guard()->inspect( $order );

		$this->assertCount( 1, $this->notices );
		$this->assertStringContainsString( 'Order 41 was not moved to Completed.', $this->notices[0] );
	}

	/**
	 * The listed move is allowed.
	 */
	public function test_allowed_change_is_left_alone(): void {
		$order = $this->order( 'proof', 'packed' );
		$order->shouldNotReceive( 'set_status' );

		$this->guard()->inspect( $order );

		$this->assertSame( array(), $this->notices );
	}

	/**
	 * A new order created straight into a status the manager has no rule for (the fourth bypass).
	 */
	public function test_new_order_in_a_restricted_status_is_checked_as_a_move_out_of_new(): void {
		$order = $this->order( '', 'packed' );
		$order->shouldReceive( 'set_status' )->once()->with( 'pending', false );
		Filters\expectApplied( 'woocommerce_default_order_status' )->andReturn( 'pending' );

		$this->guard()->inspect( $order );

		$this->assertCount( 1, $this->notices );
	}

	/**
	 * An order that is a draft in the database is a new order too.
	 */
	public function test_auto_draft_is_checked_as_new(): void {
		$order = $this->order( 'auto-draft', 'proof' );
		$order->shouldReceive( 'set_status' )->once()->with( 'auto-draft', false );

		$this->guard()->inspect( $order );

		$this->assertCount( 1, $this->notices );
	}

	/**
	 * A rule from "*" lets the manager create orders in that status.
	 */
	public function test_wildcard_rule_lets_a_new_order_through(): void {
		$this->stub_rules( array( $this->rule( '*', 'proof', array( 'shop_manager' ) ) ) );
		$order = $this->order( 'auto-draft', 'proof' );
		$order->shouldNotReceive( 'set_status' );

		$this->guard()->inspect( $order );
	}

	/**
	 * WooCommerce's own draft and trash statuses are bookkeeping, not business moves: "Add order" and trashing keep working.
	 */
	public function test_internal_statuses_are_never_refused(): void {
		foreach ( array( 'auto-draft', 'draft', 'checkout-draft', 'trash' ) as $internal ) {
			$order = $this->order( '', $internal );
			$order->shouldNotReceive( 'set_status' );
			$this->guard()->inspect( $order );

			$trashed = $this->order( 'proof', $internal );
			$trashed->shouldNotReceive( 'set_status' );
			$this->guard()->inspect( $trashed );
		}
		$this->assertSame( array(), $this->notices );
	}

	/**
	 * Dates that WooCommerce sets for the refused status go back too.
	 */
	public function test_paid_and_completed_dates_are_restored(): void {
		$order = $this->order(
			'proof',
			'completed',
			array(
				'date_paid'      => 'now',
				'date_completed' => 'now',
			)
		);
		$order->shouldReceive( 'set_status' )->once()->with( 'proof', false );
		$order->shouldReceive( 'set_date_paid' )->once()->with( null );
		$order->shouldReceive( 'set_date_completed' )->once()->with( null );

		$this->guard()->inspect( $order );
	}

	/**
	 * Administrators are never restricted.
	 */
	public function test_administrator_is_not_checked(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$order = $this->order( 'proof', 'completed' );
		$order->shouldNotReceive( 'set_status' );

		$this->guard()->inspect( $order );
	}

	/**
	 * The system is not a person: WP-CLI, cron, a gateway, an API key. Here: nobody logged in.
	 */
	public function test_the_system_is_not_checked(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$order = $this->order( 'proof', 'completed' );
		$order->shouldNotReceive( 'set_status' );

		$this->guard()->inspect( $order );
	}

	/**
	 * Cron is the system, even with a user cookie on the request.
	 */
	public function test_cron_is_not_checked(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$order = $this->order( 'proof', 'completed' );
		$order->shouldNotReceive( 'set_status' );

		$this->guard()->inspect( $order );
	}

	/**
	 * A shop front request of a logged-in customer is not wp-admin.
	 */
	public function test_front_end_request_is_not_checked(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$order = $this->order( 'proof', 'completed' );
		$order->shouldNotReceive( 'set_status' );

		$this->guard()->inspect( $order );
	}

	/**
	 * Order Flow's own bulk work runs as the system.
	 */
	public function test_system_wrapper_suspends_the_check(): void {
		$order = $this->order( 'proof', 'completed' );
		$order->shouldNotReceive( 'set_status' );

		Guard::system(
			function () use ( $order ): void {
				$this->guard()->inspect( $order );
			}
		);
		$this->assertSame( array(), $this->notices );

		// And it is back on afterwards, also when the work throws.
		try {
			Guard::system(
				static function (): void {
					throw new \RuntimeException( 'x' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$refused = $this->order( 'proof', 'completed' );
		$refused->shouldReceive( 'set_status' )->once();
		$this->guard()->inspect( $refused );
	}

	/**
	 * A full refund moves the order to Refunded: the money is back, so that is not refused.
	 */
	public function test_full_refund_is_not_refused(): void {
		$order = $this->order( 'proof', 'refunded' );
		$order->shouldNotReceive( 'set_status' );

		Guard::allow_refund( 41 );
		$this->guard()->inspect( $order );
		Guard::end_refund( 41 );

		$again = $this->order( 'proof', 'refunded' );
		$again->shouldReceive( 'set_status' )->once();
		$this->guard()->inspect( $again );
	}

	/**
	 * A change that needs a comment is refused without one, and passes with the comment of the order form.
	 */
	public function test_required_comment_is_part_of_the_check(): void {
		$this->stub_rules( array( $this->rule( 'proof', 'packed', array( 'shop_manager' ), true ) ) );
		$order = $this->order( 'proof', 'packed' );
		$order->shouldReceive( 'set_status' )->once()->with( 'proof', false );
		$this->guard()->inspect( $order );

		Context::set( 'Checked with the customer', 2 );
		$allowed = $this->order( 'proof', 'packed' );
		$allowed->shouldNotReceive( 'set_status' );
		$this->guard()->inspect( $allowed );
	}

	/**
	 * Saves that do not touch the status, or put the same status back, are ignored.
	 */
	public function test_saves_without_a_status_change_are_ignored(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_type' )->andReturn( 'shop_order' );
		$order->shouldReceive( 'get_id' )->andReturn( 41 );
		$order->shouldReceive( 'get_changes' )->andReturn( array( 'billing_first_name' => 'A' ) );
		$order->shouldNotReceive( 'set_status' );
		$this->guard()->inspect( $order );

		$same = $this->order( 'proof', 'proof' );
		$same->shouldNotReceive( 'set_status' );
		$this->guard()->inspect( $same );

		$this->assertSame( array(), $this->notices );
	}

	/**
	 * Many refused orders in one request give a few notices and one summary line.
	 */
	public function test_many_refused_orders_are_summed_up(): void {
		Functions\when( '_n' )->alias(
			static function ( string $single, string $plural, int $count ): string {
				return 1 === $count ? $single : $plural;
			}
		);
		$added = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[ $hook ] = $callback;
			}
		);
		for ( $i = 1; $i <= 5; $i++ ) {
			$order = Mockery::mock( WC_Order::class );
			$order->shouldReceive( 'get_type' )->andReturn( 'shop_order' );
			$order->shouldReceive( 'get_id' )->andReturn( 100 + $i );
			$order->shouldReceive( 'get_order_number' )->andReturn( (string) ( 100 + $i ) );
			$order->shouldReceive( 'get_status' )->andReturn( 'completed' );
			$order->shouldReceive( 'get_changes' )->andReturn( array( 'status' => 'completed' ) );
			$order->shouldReceive( 'get_data' )->andReturn( array( 'status' => 'proof' ) );
			$order->shouldReceive( 'set_status' )->once();
			$this->guard()->inspect( $order );
		}

		$this->assertCount( 3, $this->notices, 'three in full' );
		$this->assertArrayHasKey( 'shutdown', $added );
		call_user_func( $added['shutdown'] );
		$this->assertStringContainsString( '2 more orders were not moved either.', $this->notices[ count( $this->notices ) - 1 ] );
	}

	/**
	 * The notice queue is the one in Notices.
	 */
	public function test_notices_use_the_user_meta_queue(): void {
		$this->assertSame( 'ofwc_notices', Notices::META_KEY );
	}

	/**
	 * A REST request.
	 *
	 * @param string $route  Route.
	 * @param string $method HTTP method.
	 * @param mixed  $status Requested status.
	 * @return \Mockery\MockInterface&\WP_REST_Request
	 */
	private function request( string $route, string $method, $status ) {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( $route );
		$request->shouldReceive( 'get_method' )->andReturn( $method );
		$request->shouldReceive( 'get_param' )->with( 'status' )->andReturn( $status );
		return $request;
	}

	/**
	 * REST from the browser: a refused status change answers 403 before anything is saved.
	 */
	public function test_rest_update_to_a_refused_status_answers_403(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'proof', 'proof' ) );

		$result = $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/orders/41', 'PUT', 'completed' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ofwc_transition_not_allowed', $result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
	}

	/**
	 * REST create in a restricted status is refused, in an unrestricted one it passes.
	 */
	public function test_rest_create_is_checked_as_a_move_out_of_new(): void {
		$denied = $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/orders', 'POST', 'packed' ) );
		$this->assertInstanceOf( \WP_Error::class, $denied );

		$fine = $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/orders', 'POST', 'processing' ) );
		$this->assertNull( $fine );
	}

	/**
	 * Reads, other routes, the allowed move and the system pass untouched.
	 */
	public function test_rest_other_requests_pass(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'proof', 'proof' ) );

		$this->assertNull( $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/orders/41', 'GET', 'completed' ) ) );
		$this->assertNull( $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/products/41', 'PUT', 'completed' ) ) );
		$this->assertNull( $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/orders/41', 'PUT', 'packed' ) ) );
		$this->assertNull( $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/orders/41', 'PUT', null ) ) );

		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->assertNull( $this->guard()->check_rest_request( null, null, $this->request( '/wc/v3/orders/41', 'PUT', 'completed' ) ) );
	}
}
