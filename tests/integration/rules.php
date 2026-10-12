<?php
/**
 * Integration checks for the transition rules, the comment privacy and the label masking.
 *
 * Included by run.php. Everything it creates is named "OF D1" or "of-d1-" and removed again.
 * The rules apply to "a person in the browser"; a wp eval-file run is the CLI, so the checks switch
 * that on with the ofwc_actor_is_person filter and act as a shop manager.
 *
 * @package OrderFlowWC
 */

use OrderFlowWC\Admin\Page;
use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Registrar;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Transitions\Rules;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

/**
 * Create a flagged order in a status.
 *
 * @param string $status Status slug.
 * @param float  $total  Order total.
 */
function ofwc_it_d1_order( string $status, float $total = 0.0 ): WC_Order {
	// The test setup is the shop's own work, whoever the test is acting as.
	$system = static fn(): bool => false;
	add_filter( 'ofwc_actor_is_person', $system, 99 );
	$order  = ofwc_it_create_order();
	$order->set_billing_email( 'of-d1@example.test' );
	$order->set_billing_first_name( 'OF D1' );
	if ( $total > 0 ) {
		$order->set_total( $total );
	}
	$order->set_status( $status );
	$order->save();
	remove_filter( 'ofwc_actor_is_person', $system, 99 );
	return $order;
}

/**
 * Current status of an order read fresh from the database.
 *
 * @param int $id Order id.
 */
function ofwc_it_d1_status( int $id ): string {
	wp_cache_flush();
	$order = wc_get_order( $id );
	return $order instanceof WC_Order ? $order->get_status() : '';
}

/**
 * The shop manager attacks and the other ways a status changes.
 */
function ofwc_it_phase5_rules(): void {
	$repo    = new Repository();
	$rules   = new Rules( $repo );
	$user_id = 0;
	$old     = get_option( Repository::TRANSITIONS_OPTION, array() );
	$person  = static fn(): bool => true;

	try {
		foreach ( array(
			'of-d1-a' => 'OF D1 A',
			'of-d1-b' => 'OF D1 B',
		) as $slug => $label ) {
			if ( null !== $repo->get( $slug ) ) {
				$repo->delete( $slug, 'on-hold' );
			}
			$repo->save(
				array(
					'slug'  => $slug,
					'label' => $label,
				)
			);
		}
		( new Registrar( $repo ) )->register_post_statuses();

		$existing = get_user_by( 'login', 'of-d1-manager' );
		if ( $existing instanceof WP_User ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $existing->ID );
		}
		$created = wp_insert_user(
			array(
				'user_login' => 'of-d1-manager',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'of-d1-manager@example.invalid',
				'role'       => 'shop_manager',
			)
		);
		$user_id = is_int( $created ) ? $created : 0;
		ofwc_it_check( $user_id > 0, 'rules: temporary shop manager created' );
		$rules->save_all(
			array(
				array(
					'from'  => 'of-d1-a',
					'to'    => 'of-d1-b',
					'roles' => array( 'shop_manager' ),
				),
			)
		);

		// O1: the configuration capability.
		wp_set_current_user( $user_id );
		ofwc_it_check( ! current_user_can( Page::capability() ), 'O1: a shop manager does not hold the configuration capability' );
		ofwc_it_check( current_user_can( 'edit_shop_orders' ), 'O1: a shop manager can still work on orders' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admins[0]->ID );
		ofwc_it_check( current_user_can( Page::capability() ), 'O1: an administrator holds it' );
		wp_set_current_user( $user_id );

		// O22: the specific order capability works with the order storage in use.
		$probe = ofwc_it_d1_order( 'of-d1-a' );
		ofwc_it_check( current_user_can( 'edit_shop_order', $probe->get_id() ), 'O22: edit_shop_order on a concrete order works for a shop manager' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce meta capability.

		add_filter( 'ofwc_actor_is_person', $person );

		// O2, way 1 and 2: the bulk action and the order list button both end in update_status() by a person.
		$order    = ofwc_it_d1_order( 'of-d1-a' );
		$fired    = 0;
		$listener = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'woocommerce_order_status_changed', $listener );
		add_action( 'woocommerce_order_status_completed', $listener );
		$order->update_status( 'completed', 'Order status changed by bulk edit.', true );
		remove_action( 'woocommerce_order_status_changed', $listener );
		remove_action( 'woocommerce_order_status_completed', $listener );
		ofwc_it_check( 'of-d1-a' === ofwc_it_d1_status( $order->get_id() ), 'O2: the standard bulk action "mark completed" cannot take an order out of a restricted status' );
		ofwc_it_check( 0 === $fired, 'O2: a refused change fires no status hooks (emails, stock, history stay quiet) (' . $fired . ')' );
		$stored = wc_get_order( $order->get_id() );
		ofwc_it_check( $stored instanceof WC_Order && null === $stored->get_date_completed(), 'O2: a refused change leaves no completed date behind' );

		$other = ofwc_it_d1_order( 'pending' );
		$other->update_status( 'of-d1-b', '', true );
		ofwc_it_check( 'pending' === ofwc_it_d1_status( $other->get_id() ), 'O2: the order list button cannot move an order into a restricted status' );

		// O2, way 3: REST with the browser cookie.
		$rest = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$rest->set_body_params( array( 'status' => 'completed' ) );
		$reply = rest_do_request( $rest );
		ofwc_it_check( 403 === $reply->get_status(), 'O2: REST from the browser answers 403 for a refused status (' . $reply->get_status() . ')' );
		ofwc_it_check( 'of-d1-a' === ofwc_it_d1_status( $order->get_id() ), 'O2: the REST request changed nothing' );

		// O2, way 4: a new order created straight into a restricted status.
		$fresh = wc_create_order( array( 'status' => 'of-d1-b' ) );
		$id    = $fresh instanceof WC_Order ? $fresh->get_id() : 0;
		ofwc_it_check( $id > 0 && 'of-d1-b' !== ofwc_it_d1_status( $id ), 'O2: a new order cannot start in a restricted status' );
		if ( $id > 0 ) {
			wc_get_order( $id )->delete( true );
		}

		// The listed move is fine.
		$ok = ofwc_it_d1_order( 'of-d1-a' );
		$ok->update_status( 'of-d1-b', '', true );
		ofwc_it_check( 'of-d1-b' === ofwc_it_d1_status( $ok->get_id() ), 'O2: the move the rule allows still works' );

		// A full refund is not refused.
		$paid = ofwc_it_d1_order( 'of-d1-a', 10.0 );
		$ref  = wc_create_refund(
			array(
				'order_id' => $paid->get_id(),
				'amount'   => 10.0,
			)
		);
		ofwc_it_check( ! is_wp_error( $ref ) && 'refunded' === ofwc_it_d1_status( $paid->get_id() ), 'O2: a full refund moves the order to Refunded' );

		// The system is not a person.
		remove_filter( 'ofwc_actor_is_person', $person );
		$sys = ofwc_it_d1_order( 'of-d1-a' );
		$sys->update_status( 'completed' );
		ofwc_it_check( 'completed' === ofwc_it_d1_status( $sys->get_id() ), 'O2: the system (CLI, cron, gateways) is not blocked' );

		// An administrator is not restricted either.
		add_filter( 'ofwc_actor_is_person', $person );
		wp_set_current_user( $admins[0]->ID );
		$adm = ofwc_it_d1_order( 'of-d1-a' );
		$adm->update_status( 'completed', '', true );
		ofwc_it_check( 'completed' === ofwc_it_d1_status( $adm->get_id() ), 'O2: an administrator is not blocked' );
		wp_set_current_user( $user_id );
		remove_filter( 'ofwc_actor_is_person', $person );

		// O12: history on every change, a note only when a custom status or a comment is involved.
		$plain = ofwc_it_d1_order( 'pending' );
		$plain->update_status( 'processing' );
		$plain = wc_get_order( $plain->get_id() );
		$notes = wc_get_order_notes( array( 'order_id' => $plain->get_id() ) );
		$ours  = array_filter( $notes, static fn( $n ): bool => str_starts_with( (string) $n->content, 'Order Flow:' ) );
		ofwc_it_check( array() === $ours, 'O12: no Order Flow note for a change between WooCommerce statuses' );
		ofwc_it_check( count( OrderFlowWC\History\History::get( $plain ) ) >= 1, 'O12: the history entry is still written' );
		Context::set( 'Paid by wire', $user_id );
		$plain->update_status( 'completed' );
		Context::clear();
		$notes = wc_get_order_notes( array( 'order_id' => $plain->get_id() ) );
		$ours  = array_filter( $notes, static fn( $n ): bool => str_starts_with( (string) $n->content, 'Order Flow:' ) );
		ofwc_it_check( 1 === count( $ours ), 'O12: a comment earns a note' );

		wp_set_current_user( 0 );
	} finally {
		remove_all_filters( 'ofwc_actor_is_person' );
		update_option( Repository::TRANSITIONS_OPTION, $old );
		if ( $user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
		wp_set_current_user( 0 );
		ofwc_it_delete_test_orders();
		foreach ( array( 'of-d1-a', 'of-d1-b' ) as $slug ) {
			if ( null !== $repo->get( $slug ) ) {
				$repo->delete( $slug, 'on-hold' );
			}
		}
		Rules::flush_cache();
	}
}

/**
 * The comment reaches the customer only when the person says so; masking and Analytics.
 */
function ofwc_it_phase6_privacy(): void {
	$repo = new Repository();
	ofwc_it_delete_test_orders();
	foreach ( array( 'of-d1-hidden', 'of-d1-unpaid', 'of-d1-paid' ) as $slug ) {
		if ( null !== $repo->get( $slug ) ) {
			$repo->delete( $slug, 'on-hold' );
		}
	}
	$user_id = 0;
	try {
		$repo->save(
			array(
				'slug'            => 'of-d1-hidden',
				'label'           => 'OF D1 Internal step',
				'show_in_account' => false,
				'email_customer'  => true,
				'email_admin'     => true,
				'email_subject'   => 'OF D1 {status} for {order_number}',
				'email_body'      => '<p>Status {status}.</p><p>{note}</p>',
			)
		);
		$repo->save(
			array(
				'slug'  => 'of-d1-unpaid',
				'label' => 'OF D1 Unpaid',
				'paid'  => false,
			)
		);
		$repo->save(
			array(
				'slug'  => 'of-d1-paid',
				'label' => 'OF D1 Paid',
				'paid'  => true,
			)
		);
		( new Registrar( $repo ) )->register_post_statuses();

		$order = ofwc_it_d1_order( 'of-d1-hidden' );

		$run = static function ( bool $tick ) use ( $order ): array {
			$captured = array();
			$capture  = static function ( $mail ) use ( &$captured ): void {
				$captured[] = $mail;
			};
			add_action( 'wp_mail_succeeded', $capture );
			Context::set( 'INTERNAL: credit check failed', 1, $tick );
			do_action( 'ofwc_status_entered', $order, 'of-d1-hidden', 'processing', 'INTERNAL: credit check failed' );
			Context::clear();
			remove_action( 'wp_mail_succeeded', $capture );
			$to_customer = '';
			$to_admin    = '';
			foreach ( $captured as $mail ) {
				$recipients = implode( ',', (array) ( $mail['to'] ?? array() ) );
				if ( str_contains( $recipients, 'of-d1@example.test' ) ) {
					$to_customer .= (string) ( $mail['message'] ?? '' ) . (string) ( $mail['subject'] ?? '' );
				} else {
					$to_admin .= (string) ( $mail['message'] ?? '' ) . (string) ( $mail['subject'] ?? '' );
				}
			}
			return array( $to_customer, $to_admin );
		};

		list( $customer, $admin ) = $run( false );
		ofwc_it_check( '' !== $customer && '' !== $admin, 'O6: both emails were sent' );
		ofwc_it_check( ! str_contains( $customer, 'INTERNAL' ), 'O6: the internal comment is not in the email to the customer by default' );
		ofwc_it_check( str_contains( $admin, 'INTERNAL: credit check failed' ), 'O6: the shop admin email carries the comment' );
		list( $customer ) = $run( true );
		ofwc_it_check( str_contains( $customer, 'INTERNAL: credit check failed' ), 'O6: with the tick the customer email carries the comment' );

		// O13: the label.
		list( $customer, $admin ) = $run( false );
		ofwc_it_check( str_contains( $customer, 'Processing' ) && ! str_contains( $customer, 'OF D1 Internal step' ), 'O13: the email to the customer says Processing for an internal step' );
		ofwc_it_check( str_contains( $admin, 'OF D1 Internal step' ), 'O13: the email to the shop admin keeps the real label' );

		$manager = wp_insert_user(
			array(
				'user_login' => 'of-d1-staff',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'of-d1-staff@example.invalid',
				'role'       => 'shop_manager',
			)
		);
		wp_set_current_user( is_int( $manager ) ? $manager : 0 );
		ofwc_it_check( 'OF D1 Internal step' === wc_get_order_status_name( 'of-d1-hidden' ), 'O13: a person who works on orders sees the real label' );
		// The customer side (shop pages, REST, Store API) is checked over HTTP: wp eval runs as WP-CLI, which never masks.
		wp_set_current_user( 0 );
		if ( is_int( $manager ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $manager );
		}

		// O14: Analytics.
		$excluded = apply_filters( 'woocommerce_analytics_excluded_order_statuses', array( 'pending' ) );
		ofwc_it_check( in_array( 'of-d1-unpaid', $excluded, true ) && in_array( 'of-d1-hidden', $excluded, true ), 'O14: unpaid custom statuses are excluded from Analytics' );
		ofwc_it_check( ! in_array( 'of-d1-paid', $excluded, true ) && in_array( 'pending', $excluded, true ), 'O14: a paid status is not excluded and the shop setting stays' );
	} finally {
		wp_set_current_user( 0 );
		if ( $user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
		ofwc_it_delete_test_orders();
		foreach ( array( 'of-d1-hidden', 'of-d1-unpaid', 'of-d1-paid' ) as $slug ) {
			if ( null !== $repo->get( $slug ) ) {
				$repo->delete( $slug, 'on-hold' );
			}
		}
	}
}
