<?php
/**
 * Performance data set generator (development only, not shipped).
 *
 * PERF_HPOS_MODE=on|off PERF_PRODUCTS=10000 PERF_ORDERS=50000 wp eval-file bin/perf-gen.php
 *
 * Builds a few template products, customers and orders through the WooCommerce API (so every row has the shape
 * WooCommerce itself writes), then multiplies them with INSERT ... SELECT in batches, straight into the right tables.
 * Orders go to the HPOS tables (with the placeholder post WooCommerce keeps) or to posts/postmeta, depending on the mode.
 *
 * 40 percent of the orders sit in three Order Flow statuses (of-review, of-proof, of-ready), listed in the table
 * ofp_cohort so a run can put them back. The plugin itself is not needed here: statuses are plain strings in the database.
 * Special records: one customer with 500 orders, one order with a 200-entry status history, one order with 300 items.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;
$wpdb->show_errors( false );
$wpdb->suppress_errors( false );
$P        = $wpdb->prefix;
$hpos     = 'on' === getenv( 'PERF_HPOS_MODE' );
$n_prod   = max( 60, (int) ( getenv( 'PERF_PRODUCTS' ) ?: 10000 ) );
$n_orders = max( 1000, (int) ( getenv( 'PERF_ORDERS' ) ?: 50000 ) );
$GLOBALS['t_start'] = microtime( true );

function ofp_say( string $m ): void {
	printf( "[gen %5.1fs] %s\n", microtime( true ) - $GLOBALS['t_start'], $m );
}

function ofp_q( string $sql ): int {
	global $wpdb;
	$r = $wpdb->query( $sql ); // phpcs:ignore
	if ( false === $r || '' !== $wpdb->last_error ) {
		fwrite( STDERR, 'SQL FAILED: ' . $wpdb->last_error . "\n" . substr( $sql, 0, 1500 ) . "\n" );
		exit( 1 );
	}
	return (int) $r;
}

/** @return array<string,string> column => EXTRA */
function ofp_cols( string $table ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT COLUMN_NAME c, EXTRA e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $table ), ARRAY_A );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ $r['c'] ] = (string) $r['e'];
	}
	if ( ! $out ) {
		fwrite( STDERR, "table $table has no columns (missing?)\n" );
		exit( 1 );
	}
	return $out;
}

/**
 * INSERT INTO $table SELECT ... FROM $from. Every column is copied from alias s unless $expr overrides it.
 *
 * @param array<string,string> $expr Column => SQL expression.
 * @param string[]             $skip Columns left to the database (auto increment).
 */
function ofp_clone( string $table, string $from, array $expr, array $skip = array() ): int {
	$cols = array();
	$sel  = array();
	foreach ( ofp_cols( $table ) as $c => $extra ) {
		if ( in_array( $c, $skip, true ) || false !== stripos( $extra, 'GENERATED' ) ) {
			continue;
		}
		$cols[] = "`$c`";
		$sel[]  = $expr[ $c ] ?? "s.`$c`";
	}
	return ofp_q( "INSERT INTO `$table` (" . implode( ',', $cols ) . ') SELECT ' . implode( ',', $sel ) . " FROM $from" );
}

function ofp_batches( int $total, int $size, callable $fn ): void {
	for ( $a = 1; $a <= $total; $a += $size ) {
		$fn( $a, min( $total, $a + $size - 1 ) );
	}
}

$lorem = str_repeat( 'Hand finished and tested. Ships in two working days. Care instructions are on the label. ', 6 );

// ---- 1. template products ------------------------------------------------------------------------------------
$groups_simple = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( "Perf simple template $i" );
	$p->set_regular_price( (string) ( 10 + 5 * $i ) );
	$p->set_sku( "TPL-S-$i" );
	$p->set_description( $lorem );
	$p->set_status( 'publish' );
	if ( 0 === $i % 2 ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 40 + $i );
	}
	$groups_simple[] = array( (int) $p->save() );
}

// ---- 2. users and template orders -------------------------------------------------------------------------------
$tpl_user = wp_insert_user(
	array(
		'user_login' => 'tplcustomer',
		'user_pass'  => 'x-Pass-123!',
		'user_email' => 'tplcustomer@example.test',
		'first_name' => 'Template',
		'last_name'  => 'Customer',
		'role'       => 'customer',
	)
);
for ( $a = 1; $a <= 4; $a++ ) {
	wp_insert_user(
		array(
			'user_login' => "perfadmin$a",
			'user_pass'  => 'x-Pass-123!',
			'user_email' => "perfadmin$a@example.test",
			'role'       => 'administrator',
		)
	);
}
$manager_id = wp_insert_user(
	array(
		'user_login' => 'perfmanager',
		'user_pass'  => 'x-Pass-123!',
		'user_email' => 'perfmanager@example.test',
		'role'       => 'shop_manager',
	)
);
$owner_id = wp_insert_user(
	array(
		'user_login' => 'perfowner',
		'user_pass'  => 'x-Pass-123!',
		'user_email' => 'perfowner@example.test',
		'first_name' => 'Perf',
		'last_name'  => 'Owner',
		'role'       => 'customer',
	)
);

$n_tpl_orders = 24;
$tpl_orders   = array();
$statuses_t   = array( 'completed', 'processing', 'on-hold', 'pending', 'cancelled', 'failed' );
for ( $i = 1; $i <= $n_tpl_orders; $i++ ) {
	$order = wc_create_order( array( 'customer_id' => (int) $tpl_user ) );
	$items = ( $i % 5 ) + 1;
	for ( $j = 0; $j < $items; $j++ ) {
		$order->add_product( wc_get_product( $groups_simple[ ( $i + $j ) % 10 ][0] ), 1 + ( $i + $j ) % 3 );
	}
	if ( 0 === $i % 2 && $items < 5 ) {
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( 'Flat rate' );
		$ship->set_method_id( 'flat_rate' );
		$ship->set_total( '5.00' );
		$order->add_item( $ship );
	}
	$order->set_address(
		array(
			'first_name' => 'Template',
			'last_name'  => 'Customer',
			'email'      => 'tplcustomer@example.test',
			'phone'      => '5551234567',
			'address_1'  => '1 Test Street',
			'city'       => 'Testville',
			'postcode'   => '90001',
			'country'    => 'US',
			'state'      => 'CA',
		),
		'billing'
	);
	$order->set_address(
		array(
			'first_name' => 'Template',
			'last_name'  => 'Customer',
			'address_1'  => '1 Test Street',
			'city'       => 'Testville',
			'postcode'   => '90001',
			'country'    => 'US',
			'state'      => 'CA',
		),
		'shipping'
	);
	$order->set_payment_method( 'cod' );
	$order->set_payment_method_title( 'Cash on delivery' );
	$order->set_created_via( 'checkout' );
	$order->calculate_totals();
	$order->set_status( $statuses_t[ $i % 6 ] );
	if ( in_array( $statuses_t[ $i % 6 ], array( 'completed', 'processing' ), true ) ) {
		$order->set_date_paid( time() - 86400 );
	}
	$tpl_orders[] = (int) $order->save();
}
ofp_say( 'templates created: ' . count( $groups_simple ) . ' simple products, ' . count( $tpl_orders ) . ' orders' );

// ---- 3. number table and product map ----------------------------------------------------------------------------
$max_n = max( $n_prod * 3, $n_orders, 600 );
ofp_q( 'DROP TABLE IF EXISTS ofp_nums' );
ofp_q( 'CREATE TABLE ofp_nums (n INT NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
ofp_q( 'SET SESSION cte_max_recursion_depth = 10000000' );
ofp_q( "INSERT INTO ofp_nums WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < $max_n) SELECT n FROM s" );
ofp_q( 'DROP TABLE IF EXISTS ofp_map' );
ofp_q( 'CREATE TABLE ofp_map (grp INT NOT NULL, old_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (old_id), KEY g (grp)) ENGINE=InnoDB' );
foreach ( $groups_simple as $g => $ids ) {
	ofp_q( "INSERT INTO ofp_map VALUES ($g, {$ids[0]})" );
}
$ts = count( $groups_simple );

// ---- 4. clone products -------------------------------------------------------------------------------------------
$n_simple = max( 1, $n_prod - $ts );
$max_id   = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}posts" );
$sbase    = $max_id + 1000;
$new      = "($sbase + nn.n)";
ofp_batches(
	$n_simple,
	2000,
	static function ( $a, $b ) use ( $P, $ts, $new ) {
		$on = "ofp_nums nn JOIN ofp_map m ON m.grp = nn.n % $ts";
		ofp_clone(
			"{$P}posts",
			"$on JOIN {$P}posts s ON s.ID = m.old_id WHERE nn.n BETWEEN $a AND $b",
			array(
				'ID'            => $new,
				'post_title'    => "CONCAT(s.post_title, ' ', nn.n)",
				'post_name'     => "CONCAT(s.post_name, '-', nn.n)",
				'guid'          => "CONCAT('http://127.0.0.1:8080/?p=', $new)",
				'post_date'     => 'DATE_SUB(s.post_date, INTERVAL (nn.n % 900) DAY)',
				'post_date_gmt' => 'DATE_SUB(s.post_date_gmt, INTERVAL (nn.n % 900) DAY)',
			)
		);
		ofp_clone(
			"{$P}postmeta",
			"$on JOIN {$P}postmeta s ON s.post_id = m.old_id WHERE nn.n BETWEEN $a AND $b",
			array(
				'post_id'    => $new,
				'meta_value' => "CASE s.meta_key WHEN '_sku' THEN IF(s.meta_value = '', '', CONCAT('SKU-', $new)) ELSE s.meta_value END",
			),
			array( 'meta_id' )
		);
		ofp_clone( "{$P}term_relationships", "$on JOIN {$P}term_relationships s ON s.object_id = m.old_id WHERE nn.n BETWEEN $a AND $b", array( 'object_id' => $new ) );
		$lookup_expr = array(
			'product_id' => $new,
			'sku'        => "IF(s.sku = '', '', CONCAT('SKU-', $new))",
		);
		if ( isset( ofp_cols( "{$P}wc_product_meta_lookup" )['global_unique_id'] ) ) {
			$lookup_expr['global_unique_id'] = "''";
		}
		ofp_clone( "{$P}wc_product_meta_lookup", "$on JOIN {$P}wc_product_meta_lookup s ON s.product_id = m.old_id WHERE nn.n BETWEEN $a AND $b", $lookup_expr );
	}
);
ofp_q( "UPDATE {$P}term_taxonomy tt SET count = (SELECT COUNT(*) FROM {$P}term_relationships tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id) WHERE taxonomy IN ('product_cat','product_type','product_visibility')" );
ofp_say( "cloned $n_simple products" );

// ---- 5. customers -------------------------------------------------------------------------------------------------
$n_cust = 500;
$ubase  = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}users" ) + 100;
ofp_clone(
	"{$P}users",
	"ofp_nums nn JOIN {$P}users s ON s.ID = $tpl_user WHERE nn.n <= $n_cust",
	array(
		'ID'            => "($ubase + nn.n)",
		'user_login'    => "CONCAT('customer', nn.n - 1)",
		'user_nicename' => "CONCAT('customer', nn.n - 1)",
		'user_email'    => "CONCAT('customer', nn.n - 1, '@example.test')",
		'display_name'  => "CONCAT('Customer ', nn.n - 1)",
	)
);
ofp_clone( "{$P}usermeta", "ofp_nums nn JOIN {$P}usermeta s ON s.user_id = $tpl_user WHERE nn.n <= $n_cust", array( 'user_id' => "($ubase + nn.n)" ), array( 'umeta_id' ) );
ofp_say( "$n_cust customers" );

// ---- 6. clone orders ------------------------------------------------------------------------------------------------
$o_posts_max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}posts" );
$o_wc_max    = $hpos ? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$P}wc_orders" ) : 0;
$obase       = max( $o_posts_max, $o_wc_max ) + 1000;
$i_max       = (int) $wpdb->get_var( "SELECT COALESCE(MAX(order_item_id),0) FROM {$P}woocommerce_order_items" );
$ibase       = $i_max + 1000;
$istride     = 8;
$first_simple = $sbase + 1;

ofp_q( 'DROP TABLE IF EXISTS ofp_imap' );
ofp_q( 'CREATE TABLE ofp_imap (old_item BIGINT UNSIGNED NOT NULL PRIMARY KEY, tpl INT NOT NULL, off INT NOT NULL, old_order BIGINT UNSIGNED NOT NULL, KEY t (tpl)) ENGINE=InnoDB' );
ofp_q( 'DROP TABLE IF EXISTS ofp_omap' );
ofp_q( 'CREATE TABLE ofp_omap (tpl INT NOT NULL PRIMARY KEY, old_order BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB' );
foreach ( $tpl_orders as $t => $oid ) {
	ofp_q( "INSERT INTO ofp_omap VALUES ($t, $oid)" );
	$items = $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$P}woocommerce_order_items WHERE order_id = %d ORDER BY order_item_id", $oid ) );
	if ( count( $items ) >= $istride ) {
		fwrite( STDERR, "template order $oid has too many items\n" );
		exit( 1 );
	}
	foreach ( $items as $off => $iid ) {
		ofp_q( 'INSERT INTO ofp_imap VALUES (' . (int) $iid . ", $t, " . ( $off + 1 ) . ", $oid)" );
	}
}

// Plan: one row per new order. n % 50 decides the status: 0-11 of-review, 12-16 of-proof, 17-19 of-ready (together 40 percent), the rest core statuses.
// Orders 1..500 belong to the customer "perfowner".
ofp_q( 'DROP TABLE IF EXISTS ofp_plan' );
ofp_q( 'CREATE TABLE ofp_plan (n INT NOT NULL PRIMARY KEY, tpl INT NOT NULL, st VARCHAR(30) NOT NULL, age BIGINT NOT NULL, cust BIGINT NOT NULL, oid BIGINT UNSIGNED NOT NULL, cidx INT NOT NULL, dt DATETIME NOT NULL, pid BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB' );
$tn = count( $tpl_orders );
ofp_q(
	"INSERT INTO ofp_plan SELECT q.n, q.n % $tn, q.st, q.age, IF(q.n <= 500, $owner_id, IF(q.n % 10 = 0, 0, $ubase + 1 + (q.n % $n_cust))), $obase + q.n, q.n % $n_cust,
		DATE_SUB(UTC_TIMESTAMP(), INTERVAL q.age SECOND), $first_simple + (q.n * 7919) % $n_simple
	FROM (
		SELECT n,
			CASE
				WHEN n % 50 BETWEEN 0 AND 11 THEN 'wc-of-review'
				WHEN n % 50 BETWEEN 12 AND 16 THEN 'wc-of-proof'
				WHEN n % 50 BETWEEN 17 AND 19 THEN 'wc-of-ready'
				WHEN n % 50 BETWEEN 20 AND 39 THEN 'wc-completed'
				WHEN n % 50 BETWEEN 40 AND 44 THEN 'wc-processing'
				WHEN n % 50 IN (45, 46) THEN 'wc-on-hold'
				WHEN n % 50 = 47 THEN 'wc-pending'
				WHEN n % 50 = 48 THEN 'wc-cancelled'
				ELSE 'wc-refunded' END AS st,
			(1 + (n * 37) % 720) * 86400 + (n * 7919) % 86400 AS age
		FROM ofp_nums WHERE n <= $n_orders
	) q"
);

$cust_email = "CONCAT('customer', pl.cidx, '@example.test')";
$o_from     = static fn( string $src, string $key, int $a, int $b ): string => "ofp_plan pl JOIN ofp_omap om ON om.tpl = pl.tpl JOIN $src s ON s.$key = om.old_order WHERE pl.n BETWEEN $a AND $b";
$ts_utc     = 'pl.dt';

ofp_batches(
	$n_orders,
	4000,
	static function ( $a, $b ) use ( $P, $hpos, $istride, $ibase, $o_from, $cust_email, $ts_utc ) {
		$item_from = 'ofp_plan pl JOIN ofp_imap im ON im.tpl = pl.tpl';
		$new_item  = "($ibase + pl.n * $istride + im.off)";

		if ( $hpos ) {
			ofp_clone(
				"{$P}posts",
				$o_from( "{$P}posts", 'ID', $a, $b ),
				array(
					'ID'                => 'pl.oid',
					'post_date'         => $ts_utc,
					'post_date_gmt'     => $ts_utc,
					'post_modified'     => $ts_utc,
					'post_modified_gmt' => $ts_utc,
					'post_name'         => "CONCAT('order-', pl.oid)",
					'guid'              => "CONCAT('http://127.0.0.1:8080/?post_type=shop_order_placehold&p=', pl.oid)",
				)
			);
			ofp_clone(
				"{$P}wc_orders",
				$o_from( "{$P}wc_orders", 'id', $a, $b ),
				array(
					'id'               => 'pl.oid',
					'status'           => 'pl.st',
					'customer_id'      => 'pl.cust',
					'billing_email'    => $cust_email,
					'date_created_gmt' => $ts_utc,
					'date_updated_gmt' => $ts_utc,
				)
			);
			ofp_clone(
				"{$P}wc_order_addresses",
				$o_from( "{$P}wc_order_addresses", 'order_id', $a, $b ),
				array( 'order_id' => 'pl.oid', 'email' => "IF(s.address_type = 'billing', $cust_email, s.email)" ),
				array( 'id' )
			);
			ofp_clone(
				"{$P}wc_order_operational_data",
				$o_from( "{$P}wc_order_operational_data", 'order_id', $a, $b ),
				array(
					'order_id'           => 'pl.oid',
					'order_key'          => "CONCAT('wc_order_', pl.oid)",
					'date_paid_gmt'      => "IF(s.date_paid_gmt IS NULL, NULL, $ts_utc)",
					'date_completed_gmt' => "IF(s.date_completed_gmt IS NULL, NULL, $ts_utc)",
				),
				array( 'id' )
			);
			ofp_clone( "{$P}wc_orders_meta", $o_from( "{$P}wc_orders_meta", 'order_id', $a, $b ), array( 'order_id' => 'pl.oid' ), array( 'id' ) );
		} else {
			ofp_clone(
				"{$P}posts",
				$o_from( "{$P}posts", 'ID', $a, $b ),
				array(
					'ID'                => 'pl.oid',
					'post_status'       => 'pl.st',
					'post_date'         => $ts_utc,
					'post_date_gmt'     => $ts_utc,
					'post_modified'     => $ts_utc,
					'post_modified_gmt' => $ts_utc,
					'post_name'         => "CONCAT('order-', pl.oid)",
					'guid'              => "CONCAT('http://127.0.0.1:8080/?post_type=shop_order&p=', pl.oid)",
				)
			);
			ofp_clone(
				"{$P}postmeta",
				$o_from( "{$P}postmeta", 'post_id', $a, $b ),
				array(
					'post_id'    => 'pl.oid',
					'meta_value' => "CASE s.meta_key
						WHEN '_customer_user' THEN pl.cust
						WHEN '_billing_email' THEN $cust_email
						WHEN '_order_key' THEN CONCAT('wc_order_', pl.oid)
						WHEN '_date_paid' THEN IF(s.meta_value = '', '', UNIX_TIMESTAMP(pl.dt))
						WHEN '_date_completed' THEN IF(s.meta_value = '', '', UNIX_TIMESTAMP(pl.dt))
						WHEN '_paid_date' THEN IF(s.meta_value = '', '', pl.dt)
						WHEN '_completed_date' THEN IF(s.meta_value = '', '', pl.dt)
						ELSE s.meta_value END",
				),
				array( 'meta_id' )
			);
		}

		ofp_clone(
			"{$P}woocommerce_order_items",
			"$item_from JOIN {$P}woocommerce_order_items s ON s.order_item_id = im.old_item WHERE pl.n BETWEEN $a AND $b",
			array( 'order_item_id' => $new_item, 'order_id' => 'pl.oid' )
		);
		ofp_clone(
			"{$P}woocommerce_order_itemmeta",
			"$item_from JOIN {$P}woocommerce_order_itemmeta s ON s.order_item_id = im.old_item WHERE pl.n BETWEEN $a AND $b",
			array(
				'order_item_id' => $new_item,
				'meta_value'    => "IF(s.meta_key = '_product_id', pl.pid, s.meta_value)",
			),
			array( 'meta_id' )
		);
		ofp_say( "orders $a-$b cloned" );
	}
);

// The orders that sit in an Order Flow status, so a run can put them back.
ofp_q( 'DROP TABLE IF EXISTS ofp_cohort' );
ofp_q( 'CREATE TABLE ofp_cohort (order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, status VARCHAR(30) NOT NULL) ENGINE=InnoDB' );
ofp_q( "INSERT INTO ofp_cohort SELECT oid, REPLACE(st, 'wc-', '') FROM ofp_plan WHERE st LIKE 'wc-of-%'" );
$cohort = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ofp_cohort' );
ofp_say( "cohort of $cohort orders in Order Flow statuses" );

foreach ( array( 'ofp_map', 'ofp_imap', 'ofp_omap', 'ofp_plan', 'ofp_nums' ) as $t ) {
	ofp_q( "DROP TABLE IF EXISTS $t" );
}
ofp_q( "DELETE FROM {$P}options WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
foreach ( array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'term_relationships', 'term_taxonomy', 'wc_product_meta_lookup', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_order_items', 'woocommerce_order_itemmeta' ) as $t ) {
	$wpdb->query( "ANALYZE TABLE {$P}{$t}" ); // phpcs:ignore
}

// ---- 7. the special records ------------------------------------------------------------------------------------------
wp_cache_flush();
// An order with 200 status changes in its history.
$history_id = $obase + 501;
$h_order    = wc_get_order( $history_id );
if ( ! $h_order instanceof WC_Order ) {
	fwrite( STDERR, "history order $history_id missing\n" );
	exit( 1 );
}
$cycle   = array( 'of-review', 'of-proof', 'of-ready' );
$history = array();
$t       = time() - 200 * 3600;
for ( $i = 0; $i < 200; $i++ ) {
	$history[] = array(
		'time'    => $t + $i * 3600,
		'user_id' => 1,
		'from'    => $cycle[ $i % 3 ],
		'to'      => $cycle[ ( $i + 1 ) % 3 ],
		'comment' => 0 === $i % 4 ? 'Checked with the customer, change ' . $i : '',
	);
}
$h_order->update_meta_data( '_ofwc_history', $history );
$h_order->save_meta_data();
ofp_say( "history order $history_id with 200 entries" );

// An order with 300 line items and long names (the PDF runs over many pages).
$name_len = (int) ( getenv( 'PERF_BIG_NAME_LEN' ) ?: 500 );
$big      = wc_create_order( array( 'customer_id' => $owner_id ) );
$word     = 'Hand-finished solid oak shelf bracket with brushed brass fittings and a five year guarantee. ';
for ( $i = 1; $i <= 300; $i++ ) {
	$item = new WC_Order_Item_Product();
	$item->set_name( 'Line ' . $i . ': ' . substr( str_repeat( $word, (int) ceil( $name_len / strlen( $word ) ) ), 0, $name_len ) );
	$item->set_product_id( $first_simple + $i );
	$item->set_quantity( 1 + $i % 4 );
	$item->set_subtotal( (string) ( 5 + $i % 17 ) );
	$item->set_total( (string) ( 5 + $i % 17 ) );
	$big->add_item( $item );
}
$big->set_address(
	array(
		'first_name' => 'Big',
		'last_name'  => 'Order',
		'email'      => 'big-order@example.test',
		'address_1'  => '1 Test Street',
		'city'       => 'Testville',
		'postcode'   => '90001',
		'country'    => 'US',
		'state'      => 'CA',
	),
	'billing'
);
$big->set_payment_method( 'cod' );
$big->calculate_totals();
$big->set_status( 'pending' );
$big->save();
ofp_q( "UPDATE " . ( $hpos ? "{$P}wc_orders SET status = 'wc-of-proof' WHERE id = " : "{$P}posts SET post_status = 'wc-of-proof' WHERE ID = " ) . (int) $big->get_id() );
ofp_say( 'big order #' . $big->get_id() . ' with 300 items' );

// ---- 8. integrity ----------------------------------------------------------------------------------------------------------
wp_cache_flush();
$fail   = array();
$counts = array(
	'products'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}posts WHERE post_type = 'product'" ),
	'orders'    => $hpos ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}wc_orders WHERE type = 'shop_order'" ) : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}posts WHERE post_type = 'shop_order'" ),
	'items'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}woocommerce_order_items" ),
	'users'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}users" ),
	'admins'    => count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ),
	'customers' => count( get_users( array( 'role' => 'customer', 'fields' => 'ID' ) ) ),
	'cohort'    => $cohort,
	'owner'     => (int) $owner_id,
	'manager'   => (int) $manager_id,
	'history_order' => $history_id,
	'big_order' => (int) $big->get_id(),
	'owner_orders' => $hpos
		? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$P}wc_orders WHERE type = 'shop_order' AND customer_id = %d", $owner_id ) )
		: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$P}postmeta WHERE meta_key = '_customer_user' AND meta_value = %d", $owner_id ) ),
);
ofp_say( 'COUNTS ' . wp_json_encode( $counts ) );

mt_srand( 42 );
for ( $s = 0; $s < 25; $s++ ) {
	$oid = $obase + mt_rand( 1, $n_orders );
	$o   = wc_get_order( $oid );
	if ( ! $o instanceof WC_Order ) {
		$fail[] = "wc_get_order($oid) failed";
		continue;
	}
	if ( 0 === count( $o->get_items() ) ) {
		$fail[] = "order $oid has no items";
	}
	if ( '' === $o->get_status() || (float) $o->get_total() <= 0 ) {
		$fail[] = "order $oid status/total odd: " . $o->get_status() . ' ' . $o->get_total();
	}
}
$bo = wc_get_order( (int) $big->get_id() );
if ( ! $bo instanceof WC_Order || 300 !== count( $bo->get_items() ) || 'of-proof' !== $bo->get_status() ) {
	$fail[] = 'big order is not as expected';
}
if ( $counts['admins'] !== 5 ) {
	$fail[] = 'admins ' . $counts['admins'];
}
if ( 501 !== $counts['owner_orders'] ) {
	$fail[] = 'owner orders ' . $counts['owner_orders'];
}
if ( $fail ) {
	fwrite( STDERR, 'INTEGRITY FAIL: ' . implode( '; ', array_slice( $fail, 0, 8 ) ) . "\n" );
	exit( 1 );
}
ofp_say( 'INTEGRITY ok (25 random orders, big order, history order)' );
update_option( 'ofp_perf_counts', $counts, false );
