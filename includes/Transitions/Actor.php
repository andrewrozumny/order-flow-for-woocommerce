<?php
/**
 * Who is changing an order: a person at the keyboard or the system.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Transitions;

use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The transition rules limit people. They never get in the way of the shop itself: payment gateways,
 * webhooks, cron, Action Scheduler, WP-CLI, REST clients that authenticate with a key or an application
 * password, and Order Flow's own background work.
 *
 * A request counts as "a person" when someone is logged in and the request is one of:
 *
 * - a wp-admin page, admin-post.php or admin-ajax.php request (the order list buttons, bulk actions, the order screen);
 * - a REST request authenticated with the login cookie and the REST nonce, which is how wp-admin itself talks to the API.
 */
final class Actor {

	/**
	 * Whether the current request is a logged-in person working in the browser.
	 */
	public static function is_person(): bool {
		$person = self::detect();

		/**
		 * Filters whether the current request is treated as a person for the transition rules.
		 *
		 * Return true to enforce the rules in a context Order Flow treats as the system, false to let a
		 * request through. Administrators are never restricted, whatever this returns.
		 *
		 * @param bool $person Whether the request is a logged-in person at the keyboard.
		 */
		return Value::bool( apply_filters( 'ofwc_actor_is_person', $person ) );
	}

	/**
	 * The detection itself, without the filter.
	 */
	private static function detect(): bool {
		if ( get_current_user_id() <= 0 ) {
			return false;
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || self::is_background_runner() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::rest_uses_login_cookie();
		}
		return is_admin();
	}

	/**
	 * Action Scheduler runs its queue through an admin-ajax.php request that carries the cookies of whoever
	 * triggered it. That is the shop working, not the person.
	 */
	private static function is_background_runner(): bool {
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared with a fixed name.
		return 'as_async_request_queue_runner' === $action;
	}

	/**
	 * Whether a REST request was authenticated by the login cookie and the REST nonce, not by an API key or
	 * an application password.
	 */
	private static function rest_uses_login_cookie(): bool {
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) || isset( $_SERVER['HTTP_AUTHORIZATION'] ) || isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return false;
		}
		if ( isset( $_REQUEST['consumer_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only tests whether an API key is present.
			return false;
		}
		// WordPress verifies the REST nonce before the request gets here; this only tells cookie authentication apart.
		return isset( $_SERVER['HTTP_X_WP_NONCE'] ) || isset( $_REQUEST['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only tests whether a nonce is present.
	}
}
