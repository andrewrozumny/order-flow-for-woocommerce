<?php
/**
 * Compatibility matrix: data for the browserless smoke test (development only, not shipped).
 *
 * MATRIX_ARG=setup|teardown MATRIX_MANIFEST=/path/smoke.json wp --user=admin eval-file bin/matrix-smoke-data.php
 *
 * setup creates a shop manager, a subscriber and two customers, two statuses with PDF documents (one hidden from
 * customers), a rule for the shop manager and four orders; teardown removes all of it.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require __DIR__ . '/matrix-lib.php';

use OrderFlowWC\Statuses\Registrar;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Transitions\Rules;

$mode     = (string) getenv( 'MATRIX_ARG' );
$manifest = (string) getenv( 'MATRIX_MANIFEST' );
$repo     = new Repository();
$logins   = array( 'of_sm_manager', 'of_sm_sub', 'of_sm_cust_a', 'of_sm_cust_b' );

if ( 'setup' === $mode ) {
	foreach ( array( 'of-sm-a', 'of-sm-b' ) as $slug ) {
		if ( null !== $repo->get( $slug ) ) {
			$repo->remove( $slug );
		}
	}
	$repo->save(
		array(
			'slug'            => 'of-sm-a',
			'label'           => 'SM Alpha',
			'paid'            => true,
			'show_in_account' => true,
			'pdf_enabled'     => true,
			'pdf_title'       => 'SM Alpha sheet',
			'pdf_text'        => '<p>Order {order_number} is in Alpha.</p>',
		)
	);
	$repo->save(
		array(
			'slug'            => 'of-sm-b',
			'label'           => 'SM Beta',
			'show_in_account' => false,
			'pdf_enabled'     => true,
			'pdf_title'       => 'SM Beta sheet',
			'pdf_text'        => '<p>Internal sheet for {order_number}.</p>',
		)
	);
	( new Registrar( $repo ) )->register_post_statuses();
	( new Rules( $repo ) )->save_all(
		array(
			array(
				'from'  => 'of-sm-a',
				'to'    => 'of-sm-b',
				'roles' => array( 'shop_manager' ),
			),
		)
	);
	$ids = array(
		'manager' => ofm_user( 'of_sm_manager', 'shop_manager' ),
		'sub'     => ofm_user( 'of_sm_sub', 'subscriber' ),
		'cust_a'  => ofm_user( 'of_sm_cust_a', 'customer' ),
		'cust_b'  => ofm_user( 'of_sm_cust_b', 'customer' ),
	);
	$orders = array(
		'a_owned'   => ofm_order( $ids['cust_a'], 'of-sm-a', 2 )->get_id(),
		'b_owned'   => ofm_order( $ids['cust_b'], 'of-sm-a', 2 )->get_id(),
		'a_hidden'  => ofm_order( $ids['cust_a'], 'of-sm-b', 2 )->get_id(),
		'guest'     => ofm_order( 0, 'of-sm-a', 2 )->get_id(),
		'a_for_mgr' => ofm_order( $ids['cust_a'], 'of-sm-a', 1 )->get_id(),
		'a_for_adm' => ofm_order( $ids['cust_a'], 'of-sm-a', 1 )->get_id(),
	);
	file_put_contents( $manifest, wp_json_encode( array( 'users' => $ids, 'orders' => $orders, 'pass' => 'matrix-Pass-123!', 'blog' => get_current_blog_id() ) ) ); // phpcs:ignore
	echo 'SMOKE-DATA ready orders=' . count( $orders ) . "\n";
	return;
}

if ( 'teardown' === $mode ) {
	ofm_delete_test_orders();
	foreach ( array( 'of-sm-a', 'of-sm-b' ) as $slug ) {
		if ( null !== $repo->get( $slug ) ) {
			$repo->remove( $slug );
		}
	}
	foreach ( $logins as $login ) {
		ofm_delete_user( $login );
	}
	echo "SMOKE-DATA removed\n";
	return;
}

fwrite( STDERR, "unknown MATRIX_ARG '$mode'\n" ); // phpcs:ignore
WP_CLI::halt( 64 );
