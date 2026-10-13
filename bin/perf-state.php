<?php
/**
 * Performance run: Order Flow configuration for the data set (development only, not shipped).
 *
 * PERF_ARG=config wp eval-file bin/perf-state.php
 *
 * Saves the three statuses the data set uses (of-review, of-proof with a PDF document, of-ready) and the transition rules
 * for the shop manager. Safe to run again.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

use OrderFlowWC\Statuses\Registrar;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Transitions\Rules;

$repo = new Repository();
$defs = array(
	'of-review' => array(
		'label' => 'In review',
		'color' => '#2271b1',
	),
	'of-proof'  => array(
		'label'           => 'Proof sent',
		'color'           => '#dba617',
		'paid'            => true,
		'show_in_account' => true,
		'pdf_enabled'     => true,
		'pdf_title'       => 'Proof sheet',
		'pdf_text'        => '<p>Order {order_number}: please check the items below and confirm.</p>',
	),
	'of-ready'  => array(
		'label'          => 'Ready to ship',
		'color'          => '#00a32a',
		'email_customer' => true,
		'email_admin'    => true,
	),
);
foreach ( $defs as $slug => $def ) {
	if ( null === $repo->get( $slug ) ) {
		$saved = $repo->save( array_merge( array( 'slug' => $slug ), $def ) );
		if ( true !== $saved ) {
			fwrite( STDERR, 'cannot save ' . $slug . "\n" ); // phpcs:ignore
			WP_CLI::halt( 1 );
		}
	}
}
Repository::flush_cache();
( new Registrar( $repo ) )->register_post_statuses();
( new Rules( $repo ) )->save_all(
	array(
		array(
			'from'  => 'of-review',
			'to'    => 'of-proof',
			'roles' => array( 'shop_manager' ),
		),
		array(
			'from'  => 'of-proof',
			'to'    => 'of-ready',
			'roles' => array( 'shop_manager' ),
		),
	)
);
echo 'CONFIGURED ' . count( $repo->all() ) . " statuses\n";
