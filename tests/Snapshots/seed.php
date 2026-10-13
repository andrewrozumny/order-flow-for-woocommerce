<?php
/**
 * Deterministic data set for the behaviour snapshots. Run inside the clone: wp eval-file seed.php
 *
 * Environment: SNAP_STAGE = clean (remove the snapshot orders) or seed (reset the configuration, create the order).
 * The last output line is a JSON object with the ids the harness needs.
 *
 * No strict_types here: wp eval-file evaluates the code.
 *
 * @package OrderFlowWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// phpcs:disable WordPress.DB, WordPress.WP.GlobalVariablesOverride -- Test fixture run by wp eval-file in a throwaway clone: it resets tables and reuses loop variables at file scope.

$stage = (string) getenv( 'SNAP_STAGE' );

$snap_orders = static function (): array {
	global $wpdb;
	$ids = array();
	// Both stores, so the helper works whichever one is active.
	foreach ( (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_snap_order'" ) as $id ) {
		$ids[] = (int) $id;
	}
	$hpos = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos}'" ) === $hpos ) {
		foreach ( (array) $wpdb->get_col( "SELECT order_id FROM {$hpos} WHERE meta_key = '_snap_order'" ) as $id ) {
			$ids[] = (int) $id;
		}
	}
	return array_values( array_unique( $ids ) );
};

if ( 'clean' === $stage ) {
	foreach ( $snap_orders() as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	delete_transient( 'ofwc_tn_1' );
	echo wp_json_encode( array( 'cleaned' => true ) ) . "\n";
	return;
}

// Users: the harness creates them; this only fixes the names that appear in history and notes.
$admin = get_user_by( 'login', 'of-destr-admin' );
$shop  = get_user_by( 'login', 'of-destr-shopmgr' );
$cust  = get_user_by( 'login', 'of-destr-custa' );
wp_update_user(
	array(
		'ID'           => $admin->ID,
		'display_name' => 'Admin Snapshot',
	)
);
wp_update_user(
	array(
		'ID'           => $shop->ID,
		'display_name' => 'Shop Snapshot',
	)
);
wp_update_user(
	array(
		'ID'           => $cust->ID,
		'display_name' => 'Alice Snapshot',
	)
);

update_option( 'admin_email', 'admin@example.test' );
update_option( 'blogname', 'Snapshot Store' );
update_option( 'date_format', 'F j, Y' );
update_option( 'time_format', 'g:i a' );
update_option( 'timezone_string', 'UTC' );
update_option( 'woocommerce_store_address', '1 Main Street' );
update_option( 'woocommerce_store_city', 'Springfield' );
update_option( 'woocommerce_store_postcode', '12345' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_email_header_image', '' );
update_option( 'woocommerce_email_footer_text', '{site_title}' );

$status_row = static function ( array $over ): array {
	return array_merge(
		array(
			'slug'            => '',
			'label'           => '',
			'color'           => '#777777',
			'icon'            => 'dashicons-marker',
			'paid'            => false,
			'show_in_account' => true,
			'reduce_stock'    => false,
			'email_customer'  => false,
			'email_admin'     => false,
			'email_subject'   => 'Your {site_title} order {order_number} is now {status}',
			'email_heading'   => 'Order {status}',
			'email_body'      => '<p>Hi {customer_name},</p><p>Your order {order_number} is now {status}.</p><p>{note}</p>',
			'pdf_enabled'     => false,
			'pdf_title'       => '{status}',
			'pdf_text'        => '',
			'pdf_attach'      => false,
			'position'        => 0,
		),
		$over
	);
};

$statuses = array(
	'awaiting-approval' => $status_row(
		array(
			'slug'           => 'awaiting-approval',
			'label'          => 'Awaiting approval',
			'color'          => '#f0ad4e',
			'icon'           => 'dashicons-clock',
			'email_customer' => true,
			'email_admin'    => true,
			'email_subject'  => '[{site_title}] Order {order_number} waits for approval',
			'email_heading'  => 'Waiting for approval',
			'email_body'     => '<p>Hello {customer_name},</p><p>Order {order_number} from {order_date} ({order_total}) is <strong>{status}</strong>.</p><p>Comment: {note}</p>',
			'pdf_enabled'    => true,
			'pdf_title'      => 'Request {order_number}',
			'pdf_text'       => '<p>This request for {customer_name} is <em>{status}</em>.</p><ul><li>Check the drawing</li><li>Confirm the price {order_total}</li></ul>',
			'pdf_attach'     => true,
			'position'       => 1,
		)
	),
	'approved'          => $status_row(
		array(
			'slug'           => 'approved',
			'label'          => 'Approved',
			'color'          => '#46b450',
			'icon'           => 'dashicons-yes-alt',
			'paid'           => true,
			'email_customer' => true,
			'email_admin'    => false,
			'email_subject'  => 'Order {order_number} approved',
			'email_heading'  => 'Approved',
			'email_body'     => '<p>Good news, {customer_name}. Order {order_number} is approved.</p><p>{note}</p>',
			'pdf_enabled'    => true,
			'pdf_title'      => 'Approval {order_number}',
			'pdf_text'       => '<p>Approved for production. Thank you, {customer_name}.</p>',
			'pdf_attach'     => false,
			'position'       => 2,
		)
	),
	'in-production'     => $status_row(
		array(
			'slug'            => 'in-production',
			'label'           => 'In production',
			'color'           => '#2271b1',
			'icon'            => 'dashicons-hammer',
			'show_in_account' => false,
			'email_customer'  => true,
			'email_admin'     => true,
			'email_subject'   => 'Order {order_number} is {status}',
			'email_heading'   => 'In production',
			'email_body'      => '<p>{customer_name}, production of order {order_number} has started.</p>',
			'pdf_enabled'     => true,
			'pdf_title'       => 'Work order {order_number}',
			'pdf_text'        => '<p>Internal work order.</p>',
			'pdf_attach'      => true,
			'position'        => 3,
		)
	),
);
update_option( 'ofwc_statuses', $statuses, true );
update_option(
	'ofwc_transitions',
	array(
		array(
			'from'            => 'pending',
			'to'              => 'awaiting-approval',
			'roles'           => array( 'administrator', 'shop_manager' ),
			'require_comment' => false,
		),
		array(
			'from'            => 'awaiting-approval',
			'to'              => 'approved',
			'roles'           => array( 'administrator' ),
			'require_comment' => true,
		),
		array(
			'from'            => 'approved',
			'to'              => 'in-production',
			'roles'           => array( 'administrator', 'shop_manager' ),
			'require_comment' => false,
		),
	),
	true
);
update_option( 'ofwc_settings', array( 'store_details' => '<strong>Acme Workshop</strong><br>1 Main Street<br>VAT 12345' ), true );
foreach ( array( 'awaiting-approval', 'approved', 'in-production' ) as $slug ) {
	delete_option( 'woocommerce_ofwc_' . $slug . '_customer_settings' );
	delete_option( 'woocommerce_ofwc_' . $slug . '_admin_settings' );
}
// One email of each format: HTML (default), multipart (HTML plus plain alternative) and plain text.
update_option(
	'woocommerce_ofwc_approved_customer_settings',
	array(
		'enabled'    => 'yes',
		'email_type' => 'multipart',
	)
);
update_option(
	'woocommerce_ofwc_in-production_customer_settings',
	array(
		'enabled'    => 'yes',
		'email_type' => 'plain',
	)
);
delete_option( 'ofwc_restore_pending' );
\OrderFlowWC\Statuses\Repository::flush_cache();

// Products: reused between runs, so their ids stay put.
$product_ids = array();
$catalog     = array(
	'SNAP-001' => array( 'Oak desk, 120 cm', '249.00' ),
	'SNAP-002' => array( 'Desk lamp "Nordic"', '39.90' ),
	'SNAP-003' => array( 'Cable tray & clips', '12.50' ),
);
foreach ( $catalog as $sku => $row ) {
	$existing = wc_get_product_id_by_sku( $sku );
	$product  = $existing ? wc_get_product( $existing ) : new WC_Product_Simple();
	$product->set_name( $row[0] );
	$product->set_sku( $sku );
	$product->set_regular_price( $row[1] );
	$product->set_manage_stock( false );
	$product->set_status( 'publish' );
	$product->save();
	$product_ids[ $sku ] = $product->get_id();
}

foreach ( $snap_orders() as $id ) {
	$old = wc_get_order( $id );
	if ( $old ) {
		$old->delete( true );
	}
}

// A large fixed id, so the order number never collides with numbers in markup and the snapshots stay readable.
global $wpdb;
$wpdb->query( "ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 40000" );
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'" ) === $wpdb->prefix . 'wc_orders' ) {
	$wpdb->query( "ALTER TABLE {$wpdb->prefix}wc_orders AUTO_INCREMENT = 40000" );
}

$order = wc_create_order(
	array(
		'customer_id' => $cust->ID,
		'status'      => 'pending',
	)
);
$order->add_product( wc_get_product( $product_ids['SNAP-001'] ), 1 );
$order->add_product( wc_get_product( $product_ids['SNAP-002'] ), 2 );
$order->add_product( wc_get_product( $product_ids['SNAP-003'] ), 4 );
$address = array(
	'first_name' => 'Alice',
	'last_name'  => 'Snapshot',
	'company'    => 'Snapshot Studio',
	'address_1'  => '77 Elm Road',
	'address_2'  => 'Suite 4',
	'city'       => 'Portland',
	'state'      => 'OR',
	'postcode'   => '97201',
	'country'    => 'US',
);
$order->set_address(
	array_merge(
		$address,
		array(
			'email' => 'alice.snapshot@example.test',
			'phone' => '555-0101',
		)
	),
	'billing'
);
$order->set_address(
	array_merge(
		$address,
		array(
			'address_1' => '9 Dock Street',
			'address_2' => '',
		)
	),
	'shipping'
);
$order->set_payment_method( 'bacs' );
$order->set_payment_method_title( 'Direct bank transfer' );
$order->set_date_created( '2026-01-15 10:30:00' );
$order->update_meta_data( '_snap_order', '1' );
$order->calculate_totals( false );
$order->save();

echo wp_json_encode(
	array(
		'order_id' => $order->get_id(),
		'products' => $product_ids,
		'admin_id' => $admin->ID,
		'shop_id'  => $shop->ID,
		'cust_id'  => $cust->ID,
		'hpos'     => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
		'total'    => $order->get_total(),
	)
) . "\n";
