<?php
/**
 * Integration checks, run inside the lab site with `wp eval-file`.
 *
 * Each phase adds a section function (ofwc_it_*) and one line in the runner at the bottom.
 * Output: one PASS or FAIL line per check; the script exits non-zero when any check fails.
 * No strict_types declaration here: wp eval-file evaluates the code, where it is not allowed.
 * Everything it creates is named "OF ..." and removed again.
 *
 * @package OrderFlowWC
 */

use OrderFlowWC\Admin\Page;
use OrderFlowWC\Statuses\Registrar;
use OrderFlowWC\Statuses\Repository;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

/**
 * Failure counter shared by all sections.
 *
 * @param int $add Failures to add.
 * @return int Total failures so far.
 */
function ofwc_it_failures( int $add = 0 ): int {
	static $total = 0;
	$total       += $add;
	return $total;
}

/**
 * Report one check.
 *
 * @param bool   $ok    Whether the check passed.
 * @param string $label What was checked.
 */
function ofwc_it_check( bool $ok, string $label ): void {
	if ( ! $ok ) {
		ofwc_it_failures( 1 );
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $label );
}

/**
 * Remove every order flagged as a test order.
 */
function ofwc_it_delete_test_orders(): void {
	$ids = wc_get_orders(
		array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_key'   => '_ofwc_test', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test cleanup on a lab site.
			'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test cleanup on a lab site.
		)
	);
	foreach ( $ids as $id ) {
		$order = wc_get_order( (int) $id );
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
	}
}

/**
 * Create a flagged test order.
 */
function ofwc_it_create_order(): WC_Order {
	$order = wc_create_order();
	if ( ! $order instanceof WC_Order ) {
		WP_CLI::error( 'Could not create a test order.' );
	}
	$order->update_meta_data( '_ofwc_test', '1' );
	$order->set_billing_first_name( 'OF Test' );
	$order->save();
	return $order;
}

/**
 * Remove leftovers of an earlier aborted run.
 *
 * @param Repository $repo Status repository.
 */
function ofwc_it_cleanup( Repository $repo ): void {
	ofwc_it_delete_test_orders();
	if ( null !== $repo->get( 'of-int-test' ) ) {
		$repo->delete( 'of-int-test', 'on-hold' );
	}
	Repository::flush_cache();
}

/**
 * Phase 1: statuses registration, paid filter, order round trip, deletion with a target, admin rendering.
 */
function ofwc_it_phase1_statuses(): void {
	$repo      = new Repository();
	$registrar = new Registrar( $repo );
	ofwc_it_cleanup( $repo );

	// Validation.
	$bad = $repo->save(
		array(
			'slug'  => 'processing',
			'label' => 'OF core clash',
		)
	);
	ofwc_it_check( is_wp_error( $bad ) && 'ofwc_slug_core' === $bad->get_error_code(), 'core slug is rejected' );

	// Create the temporary status.
	$saved = $repo->save(
		array(
			'slug'            => 'of-int-test',
			'label'           => 'OF Integration test',
			'color'           => '#2271b1',
			'paid'            => true,
			'show_in_account' => true,
		)
	);
	ofwc_it_check( true === $saved, 'status saved through the repository' );
	ofwc_it_check( null !== $repo->get( 'of-int-test' ), 'status is readable again' );

	$dupe = $repo->save(
		array(
			'slug'  => 'of-int-test',
			'label' => 'OF duplicate',
		)
	);
	ofwc_it_check( is_wp_error( $dupe ) && 'ofwc_slug_duplicate' === $dupe->get_error_code(), 'duplicate slug is rejected' );

	// Registration.
	$registrar->register_post_statuses();
	$object = get_post_status_object( 'wc-of-int-test' );
	ofwc_it_check( null !== $object && 'OF Integration test' === $object->label, 'post status wc-of-int-test is registered' );

	$statuses = wc_get_order_statuses();
	ofwc_it_check( isset( $statuses['wc-of-int-test'] ) && 'OF Integration test' === $statuses['wc-of-int-test'], 'wc_get_order_statuses() contains wc-of-int-test' );
	$keys = array_keys( $statuses );
	ofwc_it_check( array_search( 'wc-of-int-test', $keys, true ) === array_search( 'wc-processing', $keys, true ) + 1, 'custom status sits right after Processing' );
	ofwc_it_check( in_array( 'of-int-test', wc_get_is_paid_statuses(), true ), 'paid filter includes the status' );

	// Order round trip.
	$order = ofwc_it_create_order();
	$order->set_status( 'of-int-test' );
	$order->save();
	$reloaded = wc_get_order( $order->get_id() );
	ofwc_it_check( $reloaded instanceof WC_Order && 'of-int-test' === $reloaded->get_status(), 'order keeps the custom status after reload' );
	ofwc_it_check( $reloaded instanceof WC_Order && $reloaded->is_paid(), 'order in a paid status counts as paid' );
	ofwc_it_check( 1 === $repo->count_orders( 'of-int-test' ), 'count_orders() sees the order' );

	// Admin rendering.
	ofwc_it_admin_render( $repo );

	// Delete with a target.
	$no_target = $repo->delete( 'of-int-test' );
	ofwc_it_check( is_wp_error( $no_target ) && 'ofwc_target_required' === $no_target->get_error_code(), 'delete without a target is refused while orders exist' );

	$deleted = $repo->delete( 'of-int-test', 'on-hold' );
	ofwc_it_check( true === $deleted, 'delete with target on-hold succeeds' );
	$moved = wc_get_order( $order->get_id() );
	ofwc_it_check( $moved instanceof WC_Order && 'on-hold' === $moved->get_status(), 'order moved to on-hold' );
	ofwc_it_check( null === $repo->get( 'of-int-test' ), 'status is gone from the repository' );
	ofwc_it_check( ! isset( wc_get_order_statuses()['wc-of-int-test'] ), 'status is gone from wc_get_order_statuses()' );

	// Cleanup.
	ofwc_it_cleanup( $repo );
	ofwc_it_check( null === $repo->get( 'of-int-test' ) && false === wc_get_order( $order->get_id() ), 'test order and status removed' );
}

/**
 * Render the admin page views and make sure they print the expected markup.
 *
 * @param Repository $repo Status repository.
 */
function ofwc_it_admin_render( Repository $repo ): void {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	if ( array() === $admins ) {
		ofwc_it_check( false, 'an administrator exists for the render check' );
		return;
	}
	$previous = get_current_user_id();
	wp_set_current_user( $admins[0]->ID );

	$page  = new Page();
	$views = array(
		'list'   => array(),
		'add'    => array( 'view' => 'add' ),
		'edit'   => array(
			'view'   => 'edit',
			'status' => 'of-int-test',
		),
		'delete' => array(
			'view'   => 'delete',
			'status' => 'of-int-test',
		),
	);
	foreach ( $views as $name => $query ) {
		$_GET = array_merge(
			array(
				'page' => Page::SLUG,
				'tab'  => 'statuses',
			),
			$query
		);
		ob_start();
		$page->render();
		$html   = (string) ob_get_clean();
		$needle = 'add' === $name ? 'name="label"' : 'OF Integration test';
		ofwc_it_check( str_contains( $html, 'ofwc-tabs' ) && str_contains( $html, $needle ), "admin statuses tab renders the {$name} view" );
	}
	foreach ( array( 'transitions', 'documents', 'import-export' ) as $tab ) {
		$_GET = array(
			'page' => Page::SLUG,
			'tab'  => $tab,
		);
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		ofwc_it_check( str_contains( $html, 'nav-tab-active' ), "admin {$tab} tab renders" );
	}
	$_GET = array();
	wp_set_current_user( $previous );
	unset( $repo );
}

try {
	ofwc_it_phase1_statuses();
} finally {
	ofwc_it_cleanup( new Repository() );
}

if ( ofwc_it_failures() > 0 ) {
	WP_CLI::halt( 1 );
}
WP_CLI::log( 'All integration checks passed.' );
