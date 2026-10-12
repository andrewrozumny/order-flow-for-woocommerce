<?php
/**
 * Actor tests: person or system.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Transitions;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Actor;

/**
 * Who counts as a person at the keyboard.
 *
 * @covers \OrderFlowWC\Transitions\Actor
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ActorTest extends TestCase {

	/**
	 * A logged-in user on a wp-admin page, outside cron.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_REQUEST['action'], $_REQUEST['_wpnonce'], $_REQUEST['consumer_key'] );
	}

	/**
	 * A logged-in user in wp-admin, admin-post.php or admin-ajax.php is a person.
	 */
	public function test_wp_admin_request_is_a_person(): void {
		$this->assertTrue( Actor::is_person() );
	}

	/**
	 * Nobody logged in: a gateway callback, a webhook.
	 */
	public function test_no_user_is_the_system(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * A shop page.
	 */
	public function test_front_end_is_not_a_person_at_the_admin(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * Cron.
	 */
	public function test_cron_is_the_system(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * Action Scheduler's async runner uses admin-ajax.php with the cookies of whoever triggered it.
	 */
	public function test_action_scheduler_runner_is_the_system(): void {
		$_REQUEST['action'] = 'as_async_request_queue_runner';
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * WP-CLI.
	 */
	public function test_wp_cli_is_the_system(): void {
		define( 'WP_CLI', true );
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * REST with the login cookie and the REST nonce: the browser of someone in wp-admin.
	 */
	public function test_rest_with_cookie_and_nonce_is_a_person(): void {
		define( 'REST_REQUEST', true );
		Functions\when( 'is_admin' )->justReturn( false );
		$_SERVER['HTTP_X_WP_NONCE'] = 'abc';
		$this->assertTrue( Actor::is_person() );
	}

	/**
	 * REST with an application password or an API key: an integration.
	 */
	public function test_rest_with_basic_auth_is_the_system(): void {
		define( 'REST_REQUEST', true );
		$_SERVER['PHP_AUTH_USER'] = 'integration';
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * REST with a consumer key in the query.
	 */
	public function test_rest_with_consumer_key_is_the_system(): void {
		define( 'REST_REQUEST', true );
		$_REQUEST['consumer_key'] = 'ck_x';
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * REST without a nonce is not cookie authentication.
	 */
	public function test_rest_without_nonce_is_the_system(): void {
		define( 'REST_REQUEST', true );
		$this->assertFalse( Actor::is_person() );
	}

	/**
	 * A site can move a context to either side.
	 */
	public function test_filter_can_override_the_detection(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Filters\expectApplied( 'ofwc_actor_is_person' )->once()->with( false )->andReturn( true );
		$this->assertTrue( Actor::is_person() );
	}
}
