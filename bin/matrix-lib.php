<?php
/**
 * Compatibility matrix helpers shared by the matrix scripts (development only, not shipped).
 *
 * Loaded with require from the scripts that WP-CLI evaluates (`wp eval-file`). Output is one line per step:
 * "STEP PASS name" or "STEP FAIL name :: detail".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['ofm_steps'] = array();

/**
 * Record one step.
 *
 * @param string $name   Step name.
 * @param bool   $ok     Result.
 * @param string $detail Detail, shown on failure and success.
 */
function ofm_step( string $name, bool $ok, string $detail = '' ): void {
	$GLOBALS['ofm_steps'][] = array( $name, $ok );
	echo ( $ok ? 'STEP PASS ' : 'STEP FAIL ' ) . $name . ( '' !== $detail ? ' :: ' . $detail : '' ) . "\n";
}

/**
 * Run a callable as a step; true or null pass, a string is a failure message, exceptions fail.
 *
 * @param string   $name Step name.
 * @param callable $fn   Step body.
 */
function ofm_try( string $name, callable $fn ): void {
	try {
		$res = $fn();
		if ( true === $res || null === $res ) {
			ofm_step( $name, true );
		} elseif ( false === $res ) {
			ofm_step( $name, false );
		} else {
			ofm_step( $name, false, (string) $res );
		}
	} catch ( Throwable $e ) {
		ofm_step( $name, false, get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
}

/**
 * Print the closing line and stop WP-CLI with the right exit code.
 *
 * @param string $label Prefix of the closing line, for example "INTEGRATION".
 */
function ofm_finish( string $label ): void {
	$total  = count( $GLOBALS['ofm_steps'] );
	$failed = count( array_filter( $GLOBALS['ofm_steps'], static fn( $s ) => ! $s[1] ) );
	echo $label . ( 0 === $failed ? ' PASS' : ' FAIL' ) . " steps=$total failed=$failed\n";
	if ( $failed > 0 ) {
		WP_CLI::halt( 1 );
	}
}

/**
 * Text of a PDF through the development PDF parser (it is not in the plugin zip); null when the parser is missing.
 *
 * @param string $pdf PDF bytes.
 */
function ofm_pdf_text( string $pdf ): ?string {
	if ( ! class_exists( '\Smalot\PdfParser\Parser', false ) ) {
		$auto = getenv( 'MATRIX_VENDOR_AUTOLOAD' );
		$dir  = false === $auto || '' === $auto ? '' : dirname( $auto ) . '/smalot/pdfparser/src/Smalot/PdfParser/';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return null;
		}
		// Only the parser is loaded, not the whole development autoloader.
		spl_autoload_register(
			static function ( string $class ) use ( $dir ): void {
				$prefix = 'Smalot\\PdfParser\\';
				if ( 0 === strpos( $class, $prefix ) ) {
					$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( is_file( $file ) ) {
						require_once $file;
					}
				}
			}
		);
	}
	if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
		return null;
	}
	return (string) ( new \Smalot\PdfParser\Parser() )->parseContent( $pdf )->getText();
}

/**
 * Create a user with a role (on a network also a member of the current site).
 *
 * @param string $login Login.
 * @param string $role  Role.
 * @param string $pass  Password.
 * @return int User id.
 */
function ofm_user( string $login, string $role, string $pass = 'matrix-Pass-123!' ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing instanceof WP_User ) {
		$id = (int) $existing->ID;
	} else {
		$created = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => $pass,
				'user_email' => $login . '@example.test',
				'role'       => $role,
			)
		);
		$id      = is_int( $created ) ? $created : 0;
	}
	if ( $id > 0 ) {
		if ( is_multisite() ) {
			add_user_to_blog( get_current_blog_id(), $id, $role );
		}
		( new WP_User( $id ) )->set_role( $role );
	}
	return $id;
}

/**
 * Delete a user created by ofm_user().
 *
 * @param string $login Login.
 */
function ofm_delete_user( string $login ): void {
	$user = get_user_by( 'login', $login );
	if ( ! $user instanceof WP_User ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $user->ID );
	} else {
		wp_delete_user( $user->ID );
	}
}

/**
 * Create a billable order with a few items for a customer.
 *
 * @param int    $customer_id Customer id, 0 for a guest.
 * @param string $status      Status slug.
 * @param int    $items       Number of line items.
 * @return WC_Order
 */
function ofm_order( int $customer_id, string $status, int $items = 3 ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	if ( ! $order instanceof WC_Order ) {
		throw new RuntimeException( 'cannot create an order' );
	}
	for ( $i = 1; $i <= $items; $i++ ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Matrix item ' . $i );
		$product->set_regular_price( (string) ( 5 * $i ) );
		$product->save();
		$order->add_product( $product, $i );
	}
	$order->set_address(
		array(
			'first_name' => 'Matrix',
			'last_name'  => 'Customer',
			'email'      => 'matrix-customer@example.test',
			'address_1'  => '1 Test Street',
			'city'       => 'Testville',
			'postcode'   => '90001',
			'country'    => 'US',
			'state'      => 'CA',
		),
		'billing'
	);
	$order->set_payment_method( 'cod' );
	$order->update_meta_data( '_ofm_test', '1' );
	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();
	return $order;
}

/**
 * Delete every order created by ofm_order().
 */
function ofm_delete_test_orders(): void {
	$ids = wc_get_orders(
		array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => 'any',
			'meta_key'   => '_ofm_test', // phpcs:ignore
			'meta_value' => '1', // phpcs:ignore
		)
	);
	foreach ( $ids as $id ) {
		$order = wc_get_order( (int) $id );
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
	}
}
