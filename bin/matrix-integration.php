<?php
/**
 * Compatibility matrix: integration run (development only, not shipped).
 *
 * wp --user=admin eval-file bin/matrix-integration.php
 *
 * Runs against the plugin installed from the release zip: three statuses, transition rules, an order, a move as the
 * administrator and as a shop manager, the status email (captured, never sent), the PDF with the order number in its
 * text, and an export followed by an import that must change nothing. Everything it creates is removed again.
 * Last line: INTEGRATION PASS or INTEGRATION FAIL.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require __DIR__ . '/matrix-lib.php';

use OrderFlowWC\Config\Exporter;
use OrderFlowWC\Config\Importer;
use OrderFlowWC\Config\ImportResult;
use OrderFlowWC\History\History;
use OrderFlowWC\Pdf\Generator;
use OrderFlowWC\Statuses\Registrar;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Transitions\Rules;
use OrderFlowWC\Transitions\Service;

echo 'INTEGRATION site=' . home_url() . ' wp=' . get_bloginfo( 'version' ) . ' php=' . PHP_VERSION . ' woo=' . WC()->version . "\n";

$repo      = new Repository();
$registrar = new Registrar( $repo );
$rules     = new Rules( $repo );
$service   = new Service( $rules );
$slugs     = array( 'of-mx-proof', 'of-mx-ready', 'of-mx-done' );

$prev_statuses = get_option( Repository::OPTION, array() );
$prev_rules    = get_option( Repository::TRANSITIONS_OPTION, array() );
$admin_id      = get_current_user_id();
$manager_id    = 0;

ofm_step( 'plugin classes loaded', class_exists( Repository::class ) && class_exists( Service::class ) && class_exists( Generator::class ) );
ofm_step( 'administrator is signed in', $admin_id > 0 && user_can( $admin_id, 'manage_options' ) );

try {
	ofm_delete_test_orders();
	foreach ( $slugs as $slug ) {
		if ( null !== $repo->get( $slug ) ) {
			$repo->remove( $slug );
		}
	}

	// ---- three statuses ---------------------------------------------------------------------
	ofm_try(
		'three statuses are created',
		static function () use ( $repo ): ?string {
			$defs = array(
				'of-mx-proof' => array(
					'label'          => 'MX Proof',
					'color'          => '#2271b1',
					'paid'           => true,
					'email_customer' => true,
					'email_admin'    => true,
					'email_subject'  => 'MX mail: order {order_number} is {status}',
					'pdf_enabled'    => true,
					'pdf_title'      => 'MX Proof sheet',
					'pdf_text'       => '<p>Proof for order {order_number}.</p>',
				),
				'of-mx-ready' => array(
					'label' => 'MX Ready',
					'color' => '#00a32a',
				),
				'of-mx-done'  => array(
					'label' => 'MX Done',
					'color' => '#8c8f94',
				),
			);
			foreach ( $defs as $slug => $def ) {
				$saved = $repo->save( array_merge( array( 'slug' => $slug ), $def ) );
				if ( true !== $saved ) {
					return $slug . ': ' . ( is_wp_error( $saved ) ? $saved->get_error_message() : 'not saved' );
				}
			}
			return null;
		}
	);
	$registrar->register_post_statuses();
	ofm_try(
		'statuses are registered with WordPress and WooCommerce',
		static function (): ?string {
			$known = wc_get_order_statuses();
			foreach ( array( 'wc-of-mx-proof', 'wc-of-mx-ready', 'wc-of-mx-done' ) as $key ) {
				if ( ! isset( $known[ $key ] ) || null === get_post_status_object( $key ) ) {
					return $key . ' missing';
				}
			}
			return in_array( 'of-mx-proof', wc_get_is_paid_statuses(), true ) ? null : 'paid status not in wc_get_is_paid_statuses()';
		}
	);

	// ---- transition rules -------------------------------------------------------------------
	$manager_id = ofm_user( 'of_mx_manager', 'shop_manager' );
	ofm_step( 'shop manager user exists', $manager_id > 0 );
	$rules->save_all(
		array(
			array(
				'from'  => 'of-mx-proof',
				'to'    => 'of-mx-ready',
				'roles' => array( 'shop_manager' ),
			),
			array(
				'from'            => 'of-mx-ready',
				'to'              => 'of-mx-done',
				'roles'           => array( 'shop_manager' ),
				'require_comment' => true,
			),
		)
	);
	ofm_try(
		'rules are stored and evaluated',
		static function () use ( $rules, $manager_id, $admin_id ): ?string {
			if ( 2 !== count( $rules->all() ) ) {
				return 'expected 2 rules, got ' . count( $rules->all() );
			}
			if ( ! $rules->is_allowed( 'of-mx-proof', 'of-mx-ready', $manager_id ) ) {
				return 'manager should be allowed proof to ready';
			}
			if ( $rules->is_allowed( 'of-mx-ready', 'of-mx-proof', $manager_id ) ) {
				return 'manager should not be allowed ready to proof';
			}
			if ( ! $rules->is_allowed( 'of-mx-ready', 'of-mx-proof', $admin_id ) ) {
				return 'administrator should be allowed everything';
			}
			return $rules->requires_comment( 'of-mx-ready', 'of-mx-done' ) ? null : 'comment requirement missing';
		}
	);

	// ---- order, mail capture, transition as administrator -----------------------------------
	$mails   = array();
	$capture = static function ( $pre, $atts ) use ( &$mails ) {
		$mails[] = is_array( $atts ) ? $atts : array();
		return true;
	};
	add_filter( 'pre_wp_mail', $capture, 10, 2 );

	$order = ofm_order( 0, 'pending', 3 );
	$order->set_billing_email( 'matrix-customer@example.test' );
	$order->save();
	$order_id = $order->get_id();

	ofm_try(
		'administrator moves the order into the first status',
		static function () use ( $service, $order_id, $admin_id ) {
			$order  = wc_get_order( $order_id );
			$result = $service->transition( $order, 'of-mx-proof', 'Proof uploaded', $admin_id );
			if ( true !== $result ) {
				return is_wp_error( $result ) ? $result->get_error_message() : 'not moved';
			}
			$fresh = wc_get_order( $order_id );
			return $fresh instanceof WC_Order && 'of-mx-proof' === $fresh->get_status() ? null : 'order not in of-mx-proof';
		}
	);
	remove_filter( 'pre_wp_mail', $capture, 10 );

	ofm_try(
		'status email is intercepted for customer and admin',
		static function () use ( &$mails, $order_id ): ?string {
			$order   = wc_get_order( $order_id );
			$subject = 'MX mail: order ' . ( $order instanceof WC_Order ? $order->get_order_number() : '' ) . ' is MX Proof';
			$found   = array_filter(
				$mails,
				static fn( $m ) => ( $m['subject'] ?? '' ) === $subject
			);
			if ( 2 !== count( $found ) ) {
				return 'expected 2 mails with subject "' . $subject . '", got ' . count( $found ) . ' of ' . count( $mails );
			}
			$to = array();
			foreach ( $found as $m ) {
				$to = array_merge( $to, (array) ( $m['to'] ?? array() ) );
			}
			return in_array( 'matrix-customer@example.test', $to, true ) ? null : 'customer address missing in ' . implode( ',', $to );
		}
	);

	ofm_try(
		'history and order note are written',
		static function () use ( $order_id, $admin_id ): ?string {
			$order   = wc_get_order( $order_id );
			$history = $order instanceof WC_Order ? History::get( $order ) : array();
			$last    = array() === $history ? array() : end( $history );
			if ( ( $last['to'] ?? '' ) !== 'of-mx-proof' || ( $last['from'] ?? '' ) !== 'pending' || (int) ( $last['user_id'] ?? 0 ) !== $admin_id ) {
				return 'history entry wrong: ' . wp_json_encode( $last );
			}
			foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
				if ( str_contains( $note->content, 'Order Flow:' ) && str_contains( $note->content, 'Proof uploaded' ) ) {
					return null;
				}
			}
			return 'no Order Flow note on the order';
		}
	);

	// ---- PDF --------------------------------------------------------------------------------
	ofm_try(
		'PDF is generated and carries the order number',
		static function () use ( $repo, $order_id ): ?string {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return 'order missing';
			}
			$pdf = ( new Generator( $repo ) )->render_pdf( $order, 'of-mx-proof' );
			if ( ! str_starts_with( $pdf, '%PDF' ) ) {
				return 'render_pdf did not return a PDF (' . strlen( $pdf ) . ' bytes)';
			}
			$text = ofm_pdf_text( $pdf );
			if ( null === $text ) {
				return 'PDF text parser not available (MATRIX_VENDOR_AUTOLOAD)';
			}
			$number = (string) $order->get_order_number();
			if ( ! str_contains( $text, $number ) || ! str_contains( $text, 'MX Proof sheet' ) ) {
				return 'text lacks order number ' . $number . ' or title: ' . substr( preg_replace( '/\s+/', ' ', $text ), 0, 160 );
			}
			return null;
		}
	);

	// ---- transitions as shop manager --------------------------------------------------------
	ofm_try(
		'shop manager moves proof to ready (allowed)',
		static function () use ( $service, $order_id, $manager_id ) {
			$result = $service->transition( wc_get_order( $order_id ), 'of-mx-ready', '', $manager_id );
			return true === $result ? null : ( is_wp_error( $result ) ? $result->get_error_message() : 'not moved' );
		}
	);
	ofm_try(
		'shop manager is refused ready to proof',
		static function () use ( $service, $order_id, $manager_id ): ?string {
			$result = $service->transition( wc_get_order( $order_id ), 'of-mx-proof', '', $manager_id );
			if ( ! is_wp_error( $result ) || 'ofwc_not_allowed' !== $result->get_error_code() ) {
				return 'expected ofwc_not_allowed';
			}
			$fresh = wc_get_order( $order_id );
			return $fresh instanceof WC_Order && 'of-mx-ready' === $fresh->get_status() ? null : 'status changed by a refused move';
		}
	);
	ofm_try(
		'ready to done needs a comment',
		static function () use ( $service, $order_id, $manager_id ): ?string {
			$result = $service->transition( wc_get_order( $order_id ), 'of-mx-done', '', $manager_id );
			if ( ! is_wp_error( $result ) || 'ofwc_comment_required' !== $result->get_error_code() ) {
				return 'expected ofwc_comment_required';
			}
			$done = $service->transition( wc_get_order( $order_id ), 'of-mx-done', 'Delivered', $manager_id );
			$fresh = wc_get_order( $order_id );
			return true === $done && $fresh instanceof WC_Order && 'of-mx-done' === $fresh->get_status() ? null : 'move with a comment failed';
		}
	);
	ofm_try(
		'history lists the three moves',
		static function () use ( $order_id ): ?string {
			$order = wc_get_order( $order_id );
			$n     = $order instanceof WC_Order ? count( History::get( $order ) ) : 0;
			return $n >= 3 ? null : 'history has ' . $n . ' entries';
		}
	);

	// ---- export and import ------------------------------------------------------------------
	ofm_try(
		'export, import and export again changes nothing',
		static function () use ( $repo, $rules ): ?string {
			$exporter = new Exporter( $repo, $rules );
			$before   = $exporter->build();
			$json     = $exporter->to_json();
			$importer = new Importer( $repo, $rules );
			foreach ( array( Importer::MODE_MERGE, Importer::MODE_REPLACE ) as $mode ) {
				$result = $importer->import_json( $json, $mode );
				if ( ! $result instanceof ImportResult ) {
					return $mode . ' import failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'unknown' );
				}
				if ( $result->changed() ) {
					return $mode . ' import changed something: ' . $result->summary();
				}
			}
			$after = $exporter->build();
			return $before['statuses'] === $after['statuses'] && $before['transitions'] === $after['transitions'] ? null : 'configuration differs after the round trip';
		}
	);
} finally {
	ofm_delete_test_orders();
	foreach ( $slugs as $slug ) {
		if ( null !== $repo->get( $slug ) ) {
			$repo->remove( $slug );
		}
	}
	update_option( Repository::OPTION, $prev_statuses, true );
	update_option( Repository::TRANSITIONS_OPTION, $prev_rules, true );
	Repository::flush_cache();
	ofm_delete_user( 'of_mx_manager' );
}
ofm_step( 'test data is removed', null === $repo->get( 'of-mx-proof' ) && false === get_user_by( 'login', 'of_mx_manager' ) );

ofm_finish( 'INTEGRATION' );
