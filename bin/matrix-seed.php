<?php
/**
 * Compatibility matrix helper (development only, not shipped).
 *
 * MATRIX_ARG=clean|create wp eval-file bin/matrix-seed.php
 * Creates a few products and orders so the checks have something to read, or removes the orders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$mode = getenv( 'MATRIX_ARG' );
$mode = false === $mode || '' === $mode ? 'create' : $mode;

if ( 'clean' === $mode ) {
	$ids = wc_get_orders(
		array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => 'any',
		)
	);
	foreach ( $ids as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
	}
	echo 'SEED cleaned orders=' . count( $ids ) . "\n";
	return;
}

$product_ids = get_posts(
	array(
		'post_type'      => 'product',
		'meta_key'       => '_of_matrix',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
if ( count( $product_ids ) < 4 ) {
	for ( $i = 1; $i <= 4; $i++ ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Matrix product ' . $i );
		$p->set_regular_price( (string) ( 10 * $i ) );
		if ( 2 === $i ) {
			$p->set_sale_price( '15' );
		}
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 20 - $i );
		$p->set_status( 'publish' );
		$p->update_meta_data( '_of_matrix', '1' );
		$product_ids[] = $p->save();
	}
}

$statuses = array( 'completed', 'processing', 'on-hold', 'pending' );
$created  = 0;
foreach ( $statuses as $n => $status ) {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_ids[ $n % count( $product_ids ) ] ), 1 + $n );
	$order->set_address(
		array(
			'first_name' => 'Matrix',
			'last_name'  => 'Buyer' . $n,
			'email'      => 'buyer' . $n . '@example.test',
			'address_1'  => '1 Test Street',
			'city'       => 'Testville',
			'postcode'   => '90001',
			'country'    => 'US',
			'state'      => 'CA',
		),
		'billing'
	);
	$order->set_payment_method( 'cod' );
	$order->calculate_totals();
	$order->update_status( $status );
	$order->save();
	++$created;
}

$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off';
echo 'SEED products=' . count( $product_ids ) . ' orders=' . $created . ' hpos=' . $hpos . "\n";
