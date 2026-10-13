<?php
/**
 * Compatibility matrix: order state around deactivation and uninstall (development only, not shipped).
 *
 * MATRIX_ARG=<mode> [MATRIX_MANIFEST=/path/manifest.json] wp --user=admin eval-file bin/matrix-state.php
 *
 * Modes:
 *   prepare    needs the plugin: create two statuses, a rule, a product with managed stock and many orders in the
 *              statuses (plus one core order), add decoy options of other plugins; writes the manifest.
 *   snapshot   write {order id: status} for every shop order read from the database (works without the plugin).
 *   expect     MATRIX_EXPECT=kept|parked|restored|uninstalled: compare the live state with the manifest snapshot.
 *   leftovers  print LEFTOVERS {json}: rows the plugin owns and WooCommerce option counts, on every site.
 *   cleanup    remove everything prepare created.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require __DIR__ . '/matrix-lib.php';

$mode     = (string) getenv( 'MATRIX_ARG' );
$manifest = (string) getenv( 'MATRIX_MANIFEST' );
global $wpdb;

/**
 * Orders as {id: status} read straight from the active storage (no registered statuses needed).
 *
 * @return array<int, string>
 */
function ofm_raw_orders(): array {
	global $wpdb;
	$hpos = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
	if ( $hpos ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM {$wpdb->prefix}wc_orders WHERE type = %s", 'shop_order' ), ARRAY_A ); // phpcs:ignore
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID AS id, post_status AS status FROM {$wpdb->posts} WHERE post_type = %s", 'shop_order' ), ARRAY_A ); // phpcs:ignore
	}
	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row['id'] ] = (string) preg_replace( '/^wc-/', '', (string) $row['status'] );
	}
	ksort( $out );
	return $out;
}

/**
 * Marker meta of an order straight from the database.
 *
 * @param int $id Order id.
 */
function ofm_raw_marker( int $id ): string {
	global $wpdb;
	$hpos = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
	if ( $hpos ) {
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s LIMIT 1", $id, '_ofwc_pre_deactivation_status' ) ); // phpcs:ignore
	} else {
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, '_ofwc_pre_deactivation_status' ) ); // phpcs:ignore
	}
	return null === $v ? '' : (string) $v;
}

/**
 * Number of order items per order straight from the database.
 *
 * @param int[] $ids Order ids.
 * @return array<int, int>
 */
function ofm_raw_items( array $ids ): array {
	global $wpdb;
	if ( array() === $ids ) {
		return array();
	}
	$in   = implode( ',', array_map( 'intval', $ids ) );
	$rows = $wpdb->get_results( "SELECT order_id, COUNT(*) AS n FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ($in) GROUP BY order_id", ARRAY_A ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row['order_id'] ] = (int) $row['n'];
	}
	return $out;
}

/**
 * Stock quantity of a product straight from the database.
 *
 * @param int $product_id Product id.
 */
function ofm_raw_stock( int $product_id ): string {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'", $product_id ) ); // phpcs:ignore
	return null === $v ? '' : (string) $v;
}

/**
 * Write the manifest file.
 *
 * @param string               $path Path.
 * @param array<string, mixed> $data Data.
 */
function ofm_write_manifest( string $path, array $data ): void {
	file_put_contents( $path, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Read the manifest file.
 *
 * @param string $path Path.
 * @return array<string, mixed>
 */
function ofm_read_manifest( string $path ): array {
	$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return is_array( $data ) ? $data : array();
}

if ( 'prepare' === $mode ) {
	$count = max( 4, (int) ( getenv( 'MATRIX_ORDERS' ) ?: 120 ) );
	$repo  = new OrderFlowWC\Statuses\Repository();
	foreach ( array( 'of-cy-a', 'of-cy-b' ) as $i => $slug ) {
		if ( null === $repo->get( $slug ) ) {
			$saved = $repo->save(
				array(
					'slug'  => $slug,
					'label' => 'CY ' . ( 0 === $i ? 'A' : 'B' ),
				)
			);
			if ( true !== $saved ) {
				fwrite( STDERR, 'status not saved: ' . $slug . "\n" ); // phpcs:ignore
				WP_CLI::halt( 1 );
			}
		}
	}
	( new OrderFlowWC\Statuses\Registrar( $repo ) )->register_post_statuses();
	( new OrderFlowWC\Transitions\Rules( $repo ) )->save_all(
		array(
			array(
				'from'  => 'of-cy-a',
				'to'    => 'of-cy-b',
				'roles' => array( 'shop_manager' ),
			),
		)
	);
	$product = new WC_Product_Simple();
	$product->set_name( 'Matrix stock item' );
	$product->set_regular_price( '12' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 500 );
	$product->save();
	$ids = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->update_meta_data( '_ofm_test', '1' );
		$order->set_status( 0 === $i % 2 ? 'of-cy-a' : 'of-cy-b' );
		$order->save();
		$ids[] = $order->get_id();
	}
	$core = wc_create_order();
	$core->add_product( $product, 1 );
	$core->update_meta_data( '_ofm_test', '1' );
	$core->set_status( 'processing' );
	$core->save();
	wc_update_product_stock( $product, 500, 'set' );
	foreach ( array( 'ofwcommerce_foreign_option', 'my_ofwc_option', 'woocommerce_ofwcx_settings' ) as $name ) {
		update_option( $name, 'keep' );
	}
	update_option( 'ofwc_matrix_probe', '1' );
	set_transient( 'ofwc_matrix_probe', '1', 3600 );
	ofm_write_manifest( $manifest, array( 'product' => $product->get_id(), 'orders' => $ids, 'core_order' => $core->get_id() ) );
	echo 'PREPARED orders=' . count( $ids ) . " core=" . $core->get_id() . "\n";
	return;
}

if ( 'snapshot' === $mode ) {
	$data              = ofm_read_manifest( $manifest );
	$orders            = ofm_raw_orders();
	$data['snapshot']  = $orders;
	$data['items']     = ofm_raw_items( array_keys( $orders ) );
	$data['stock']     = ofm_raw_stock( (int) ( $data['product'] ?? 0 ) );
	$data['snap_time'] = time();
	ofm_write_manifest( $manifest, $data );
	echo 'SNAPSHOT orders=' . count( $orders ) . ' stock=' . $data['stock'] . "\n";
	return;
}

if ( 'expect' === $mode ) {
	$expect   = (string) getenv( 'MATRIX_EXPECT' );
	$data     = ofm_read_manifest( $manifest );
	$snapshot = (array) ( $data['snapshot'] ?? array() );
	$now      = ofm_raw_orders();
	$items    = ofm_raw_items( array_keys( $now ) );
	$bad      = array();
	$moved    = 0;
	$markers  = 0;
	foreach ( $snapshot as $id => $was ) {
		$id = (int) $id;
		if ( ! isset( $now[ $id ] ) ) {
			$bad[] = "order $id is gone";
			continue;
		}
		$is_custom = str_starts_with( (string) $was, 'of-' );
		$want      = $was;
		if ( ( 'parked' === $expect || 'uninstalled' === $expect ) && $is_custom ) {
			$want = 'on-hold';
		}
		if ( $now[ $id ] !== $want ) {
			$bad[] = "order $id is {$now[$id]}, expected $want";
		}
		if ( $is_custom && $now[ $id ] === 'on-hold' ) {
			++$moved;
			$m = ofm_raw_marker( $id );
			if ( $m !== $was ) {
				$bad[] = "order $id marker '$m', expected '$was'";
			} else {
				++$markers;
			}
		}
		if ( 'restored' === $expect || 'kept' === $expect ) {
			if ( '' !== ofm_raw_marker( $id ) ) {
				$bad[] = "order $id still has the marker";
			}
		}
		if ( ( $data['items'][ $id ] ?? null ) !== ( $items[ $id ] ?? null ) ) {
			$bad[] = "order $id items changed";
		}
	}
	if ( count( $now ) !== count( $snapshot ) ) {
		$bad[] = 'order count ' . count( $snapshot ) . ' -> ' . count( $now );
	}
	if ( (string) ( $data['stock'] ?? '' ) !== ofm_raw_stock( (int) ( $data['product'] ?? 0 ) ) ) {
		$bad[] = 'stock ' . ( $data['stock'] ?? '' ) . ' -> ' . ofm_raw_stock( (int) ( $data['product'] ?? 0 ) );
	}
	if ( 'restored' === $expect || 'kept' === $expect ) {
		$stray = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND comment_content LIKE %s", '%' . $wpdb->esc_like( 'while the plugin was inactive' ) . '%' ) ); // phpcs:ignore
		if ( $stray > 0 ) {
			$bad[] = "$stray orders got the note 'status changed while the plugin was inactive'";
		}
	}
	// Orders must stay readable through WooCommerce itself, with or without the plugin.
	$unreadable = 0;
	foreach ( array_slice( array_keys( $snapshot ), 0, 25 ) as $id ) {
		$o = wc_get_order( (int) $id );
		if ( ! $o instanceof WC_Order || 3 > strlen( (string) $o->get_status() ) && '' === (string) $o->get_status() ) {
			++$unreadable;
		}
	}
	if ( $unreadable > 0 ) {
		$bad[] = "$unreadable orders not readable through wc_get_order()";
	}
	$detail = "expect=$expect orders=" . count( $now ) . " moved_to_on_hold=$moved markers_ok=$markers";
	echo ( array() === $bad ? 'CHECK PASS ' : 'CHECK FAIL ' ) . $detail . ( array() === $bad ? '' : ' :: ' . implode( '; ', array_slice( $bad, 0, 5 ) ) ) . "\n";
	return;
}

if ( 'cleanup' === $mode ) {
	ofm_delete_test_orders();
	foreach ( array( 'ofwcommerce_foreign_option', 'my_ofwc_option', 'woocommerce_ofwcx_settings' ) as $name ) {
		delete_option( $name );
	}
	$repo = new OrderFlowWC\Statuses\Repository();
	foreach ( array( 'of-cy-a', 'of-cy-b' ) as $slug ) {
		if ( null !== $repo->get( $slug ) ) {
			$repo->remove( $slug );
		}
	}
	$data = ofm_read_manifest( $manifest );
	if ( ! empty( $data['product'] ) ) {
		wp_delete_post( (int) $data['product'], true );
	}
	echo "CLEANED\n";
	return;
}

if ( 'leftovers' === $mode ) {
	$like  = static fn( string $prefix ): string => $wpdb->esc_like( $prefix ) . '%';
	$rows  = array();
	$wc    = 0;
	$ver   = '';
	$kept  = 0;
	$blogs = is_multisite() ? get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) : array( get_current_blog_id() );
	foreach ( $blogs as $bid ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $bid );
		}
		$found = $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like( 'ofwc_' ),
				$like( '_transient_ofwc_' ),
				$like( '_transient_timeout_ofwc_' ),
				$like( 'woocommerce_ofwc_' )
			)
		);
		foreach ( $found as $name ) {
			$rows[] = 'blog' . $bid . ':' . $name;
		}
		$as = $wpdb->prefix . 'actionscheduler_actions';
		if ( $as === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $as ) ) ) ) { // phpcs:ignore
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$as} WHERE hook LIKE %s AND status IN ('pending','in-progress')", $like( 'ofwc_' ) ) ); // phpcs:ignore
			if ( $n > 0 ) {
				$rows[] = 'blog' . $bid . ':actionscheduler(' . $n . ')';
			}
		}
		foreach ( array( 'ofwcommerce_foreign_option', 'my_ofwc_option', 'woocommerce_ofwcx_settings' ) as $name ) {
			if ( false !== get_option( $name, false ) ) {
				++$kept;
			}
		}
		$wc += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like( 'woocommerce_' ) ) ); // phpcs:ignore
		if ( '' === $ver ) {
			$ver = (string) get_option( 'woocommerce_version', '' );
		}
		$up = wp_upload_dir( null, false );
		foreach ( array( 'ofwc-tmp', 'ofwc-fonts' ) as $dir ) {
			if ( ! empty( $up['basedir'] ) && is_dir( $up['basedir'] . '/' . $dir ) ) {
				$rows[] = 'blog' . $bid . ':uploads/' . $dir;
			}
		}
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
	if ( is_multisite() ) {
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $like( 'ofwc_' ), $like( '_site_transient_ofwc_' ) ) ); // phpcs:ignore
		foreach ( $found as $name ) {
			$rows[] = 'network:' . $name;
		}
	}
	echo 'LEFTOVERS ' . wp_json_encode( array( 'plugin_rows' => $rows, 'wc_options' => $wc, 'wc_version' => $ver, 'decoys' => $kept ) ) . "\n";
	return;
}

fwrite( STDERR, "unknown MATRIX_ARG '$mode'\n" ); // phpcs:ignore
WP_CLI::halt( 64 );
