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

/**
 * Phase 2B: status emails are registered with WooCommerce and sent when an order enters the status.
 */
function ofwc_it_phase2_emails(): void {
	$repo = new Repository();
	ofwc_it_delete_test_orders();
	if ( null !== $repo->get( 'of-it-mail' ) ) {
		$repo->delete( 'of-it-mail' );
	}

	$saved = $repo->save(
		array(
			'slug'           => 'of-it-mail',
			'label'          => 'OF Mail test',
			'email_customer' => true,
			'email_admin'    => true,
			'email_subject'  => 'OF Mail test: order {order_number} is {status}',
			'email_heading'  => 'OF heading {status}',
			'email_body'     => '<p>Hello {customer_name}, note: {note}</p>',
		)
	);
	ofwc_it_check( true === $saved, 'mail test status saved' );
	$registrar = new Registrar( $repo );
	$registrar->register_post_statuses();

	// The emails show up in the WooCommerce email list.
	$classes = apply_filters( 'woocommerce_email_classes', array() );
	ofwc_it_check( isset( $classes['ofwc_of-it-mail_customer'], $classes['ofwc_of-it-mail_admin'] ), 'both emails are in woocommerce_email_classes' );
	ofwc_it_check( isset( $classes['ofwc_of-it-mail_customer'] ) && 'Order Flow: OF Mail test (customer)' === $classes['ofwc_of-it-mail_customer']->get_title(), 'email title carries the status label' );

	$order = ofwc_it_create_order();
	$order->set_billing_email( 'of-it@example.test' );
	$order->set_billing_first_name( 'OF Mailer' );
	$order->set_status( 'of-it-mail' );
	$order->save();

	$captured = array();
	$capture  = static function ( $mail ) use ( &$captured ): void {
		$captured[] = $mail;
	};
	add_action( 'wp_mail_succeeded', $capture );
	do_action( 'ofwc_status_entered', $order, 'of-it-mail', 'processing', 'Packed with care' );
	remove_action( 'wp_mail_succeeded', $capture );

	$subject  = 'OF Mail test: order ' . $order->get_order_number() . ' is OF Mail test';
	$matching = array_values(
		array_filter(
			$captured,
			static function ( $mail ) use ( $subject ): bool {
				return is_array( $mail ) && ( $mail['subject'] ?? '' ) === $subject;
			}
		)
	);
	ofwc_it_check( 2 === count( $matching ), 'two mails with the replaced subject were sent (' . count( $matching ) . ')' );

	$recipients = array();
	$bodies     = '';
	foreach ( $matching as $mail ) {
		$to         = $mail['to'] ?? array();
		$recipients = array_merge( $recipients, (array) $to );
		$bodies    .= (string) ( $mail['message'] ?? '' );
	}
	ofwc_it_check( in_array( 'of-it@example.test', $recipients, true ), 'customer mail went to the billing address' );
	ofwc_it_check( in_array( (string) get_option( 'admin_email' ), $recipients, true ), 'admin mail went to the admin recipient' );
	ofwc_it_check( str_contains( $bodies, 'Packed with care' ) && str_contains( $bodies, 'Hello OF Mailer' ), 'mail body carries the note and the customer name' );
	ofwc_it_check( str_contains( $bodies, 'OF heading OF Mail test' ), 'mail uses the heading' );

	// Switching the admin flag off sends only the customer mail.
	$status                = $repo->get( 'of-it-mail' );
	$status['email_admin'] = false;
	$repo->save( (array) $status, 'of-it-mail' );
	$captured = array();
	add_action( 'wp_mail_succeeded', $capture );
	do_action( 'ofwc_status_entered', $order, 'of-it-mail', 'processing', '' );
	remove_action( 'wp_mail_succeeded', $capture );
	ofwc_it_check( 1 === count( $captured ), 'only the customer mail is sent when the admin flag is off (' . count( $captured ) . ')' );

	ofwc_it_delete_test_orders();
	$repo->delete( 'of-it-mail' );
	ofwc_it_check( null === $repo->get( 'of-it-mail' ), 'mail test status removed' );
}

/**
 * Phase 2C: PDF rendering, document availability and the email attachment filter.
 */
function ofwc_it_phase2_pdf(): void {
	$repo = new Repository();
	( new Registrar( $repo ) )->register_post_statuses();
	if ( null !== $repo->get( 'of-it-pdf' ) ) {
		$repo->delete( 'of-it-pdf', 'on-hold' );
	}
	ofwc_it_delete_test_orders();

	$saved = $repo->save(
		array(
			'slug'            => 'of-it-pdf',
			'label'           => 'OF PDF test',
			'pdf_enabled'     => true,
			'pdf_title'       => 'OF Approval sheet',
			'pdf_text'        => '<p>Please approve order {order_number}.</p>',
			'show_in_account' => true,
		)
	);
	ofwc_it_check( true === $saved, 'pdf test status saved' );
	( new Registrar( $repo ) )->register_post_statuses();

	$order = ofwc_it_create_order();
	$order->set_billing_first_name( 'OF Pdfcustomer' );
	$order->set_billing_last_name( 'Testsson' );
	$order->set_status( 'of-it-pdf' );
	$order->save();
	$order = wc_get_order( $order->get_id() );
	if ( ! $order instanceof WC_Order ) {
		ofwc_it_check( false, 'pdf test order reloads' );
		return;
	}

	$docs = new OrderFlowWC\Pdf\Documents( $repo );
	ofwc_it_check( array( 'of-it-pdf' ) === $docs->slugs( $order ), 'document is available for the order in the status' );
	ofwc_it_check( str_contains( OrderFlowWC\Pdf\Documents::url( $order, 'of-it-pdf' ), 'action=ofwc_pdf' ), 'download URL targets admin-post ofwc_pdf' );

	$generator = new OrderFlowWC\Pdf\Generator( $repo );
	$html      = $generator->render_html( $order, 'of-it-pdf' );
	ofwc_it_check( str_contains( $html, 'OF Approval sheet' ) && str_contains( $html, 'Please approve order ' . $order->get_order_number() ), 'html has title and placeholder text' );

	$pdf = $generator->render_pdf( $order, 'of-it-pdf' );
	ofwc_it_check( str_starts_with( $pdf, '%PDF' ), 'render_pdf returns a PDF' );
	$text = '';
	if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
		$text = (string) ( new \Smalot\PdfParser\Parser() )->parseContent( $pdf )->getText();
	}
	ofwc_it_check( str_contains( $text, (string) $order->get_order_number() ) && str_contains( $text, 'OF Pdfcustomer' ), 'pdf text has order number and billing name' );

	$paths = apply_filters( 'ofwc_email_attachments', array(), $order, 'of-it-pdf' );
	$file  = is_array( $paths ) && isset( $paths[0] ) ? (string) $paths[0] : '';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file.
	ofwc_it_check( '' !== $file && is_readable( $file ) && str_starts_with( (string) file_get_contents( $file ), '%PDF' ), 'attachment filter returns a readable PDF file' );
	ofwc_it_check( '' !== $file && file_exists( dirname( $file ) . '/index.php' ) && file_exists( dirname( $file ) . '/.htaccess' ), 'attachment dir is protected' );
	if ( '' !== $file ) {
		wp_delete_file( $file );
	}

	$none = apply_filters( 'ofwc_email_attachments', array(), $order, 'on-hold' );
	ofwc_it_check( array() === $none, 'no attachment for a status without a document' );

	ofwc_it_delete_test_orders();
	$repo->delete( 'of-it-pdf' );
	ofwc_it_check( null === $repo->get( 'of-it-pdf' ), 'pdf test status removed' );
}

/**
 * Phase 2A: transition rules by role, the service, history meta, order note and the entered action.
 */
function ofwc_it_phase2_transitions(): void {
	$repo           = new Repository();
	$rules          = new OrderFlowWC\Transitions\Rules( $repo );
	$service        = new OrderFlowWC\Transitions\Service( $rules );
	$previous_rules = get_option( Repository::TRANSITIONS_OPTION, array() );
	$user_id        = 0;

	try {
		foreach ( array(
			'of-it-a' => 'OF IT A',
			'of-it-b' => 'OF IT B',
		) as $slug => $label ) {
			if ( null !== $repo->get( $slug ) ) {
				$repo->delete( $slug, 'on-hold' );
			}
			$saved = $repo->save(
				array(
					'slug'  => $slug,
					'label' => $label,
				)
			);
			ofwc_it_check( true === $saved, "transitions: status {$slug} created" );
		}
		( new Registrar( $repo ) )->register_post_statuses();

		$existing = get_user_by( 'login', 'of-it-manager' );
		if ( $existing instanceof WP_User ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $existing->ID );
		}
		$created = wp_insert_user(
			array(
				'user_login' => 'of-it-manager',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'of-it-manager@example.invalid',
				'role'       => 'shop_manager',
			)
		);
		$user_id = is_int( $created ) ? $created : 0;
		ofwc_it_check( $user_id > 0, 'transitions: temporary shop manager created' );

		$rules->save_all(
			array(
				array(
					'from'  => 'of-it-a',
					'to'    => 'of-it-b',
					'roles' => array( 'shop_manager' ),
				),
			)
		);
		ofwc_it_check( 1 === count( $rules->all() ), 'transitions: rule saved' );
		ofwc_it_check( $rules->is_allowed( 'of-it-a', 'of-it-b', $user_id ), 'transitions: manager may move a to b' );
		ofwc_it_check( ! $rules->is_allowed( 'of-it-b', 'of-it-a', $user_id ), 'transitions: manager may not move b to a' );
		ofwc_it_check( $rules->is_allowed( 'pending', 'completed', $user_id ), 'transitions: core to core is unrestricted' );
		ofwc_it_check( in_array( 'of-it-b', $rules->allowed_targets( 'of-it-a', $user_id ), true ), 'transitions: b is an allowed target from a' );

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		ofwc_it_check( array() !== $admins && $rules->is_allowed( 'of-it-b', 'of-it-a', $admins[0]->ID ), 'transitions: administrator may do everything' );

		// Service as the manager.
		$entered  = array();
		$listener = static function ( $order, $to, $from, $comment ) use ( &$entered ): void {
			$entered[] = array( $to, $from, $comment );
		};
		add_action( 'ofwc_status_entered', $listener, 10, 4 );

		$order = ofwc_it_create_order();
		$order->set_status( 'of-it-a' );
		$order->save();
		$entered = array();

		$denied = $service->transition( wc_get_order( $order->get_id() ), 'of-it-a', '', $user_id );
		ofwc_it_check( is_wp_error( $denied ), 'transitions: same-status change is refused' );

		$rules->save_all(
			array(
				array(
					'from'            => 'of-it-a',
					'to'              => 'of-it-b',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => true,
				),
			)
		);
		$missing = $service->transition( wc_get_order( $order->get_id() ), 'of-it-b', '  ', $user_id );
		ofwc_it_check( is_wp_error( $missing ) && 'ofwc_comment_required' === $missing->get_error_code(), 'transitions: missing comment is refused' );

		$done = $service->transition( wc_get_order( $order->get_id() ), 'of-it-b', 'Proof approved', $user_id );
		ofwc_it_check( true === $done, 'transitions: manager moves a to b with a comment' );

		$reloaded = wc_get_order( $order->get_id() );
		ofwc_it_check( $reloaded instanceof WC_Order && 'of-it-b' === $reloaded->get_status(), 'transitions: order is in b' );
		$history = $reloaded instanceof WC_Order ? OrderFlowWC\History\History::get( $reloaded ) : array();
		$last    = array() !== $history ? end( $history ) : array(
			'from'    => '',
			'to'      => '',
			'user_id' => 0,
			'comment' => '',
		);
		ofwc_it_check(
			array() !== $history && 'of-it-a' === $last['from'] && 'of-it-b' === $last['to'] && $user_id === (int) $last['user_id'] && 'Proof approved' === $last['comment'],
			'transitions: history entry recorded'
		);
		$notes = $reloaded instanceof WC_Order ? wc_get_order_notes( array( 'order_id' => $reloaded->get_id() ) ) : array();
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, 'Order Flow:' ) && str_contains( $note->content, 'Proof approved' ) && ! $note->customer_note ) {
				$found = true;
			}
		}
		ofwc_it_check( $found, 'transitions: private order note written' );
		ofwc_it_check( array( array( 'of-it-b', 'of-it-a', 'Proof approved' ) ) === $entered, 'transitions: ofwc_status_entered fired once with the comment' );
		remove_action( 'ofwc_status_entered', $listener, 10 );

		ofwc_it_check( OrderFlowWC\Transitions\Rules::ANY === '*', 'transitions: wildcard constant' );
	} finally {
		if ( $user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
		ofwc_it_delete_test_orders();
		foreach ( array( 'of-it-a', 'of-it-b' ) as $slug ) {
			if ( null !== $repo->get( $slug ) ) {
				$repo->delete( $slug, 'on-hold' );
			}
		}
		update_option( Repository::TRANSITIONS_OPTION, $previous_rules, true );
		Repository::flush_cache();
	}
	ofwc_it_check( null === $repo->get( 'of-it-a' ) && null === $repo->get( 'of-it-b' ), 'transitions: temporary statuses removed' );
	ofwc_it_check( false === get_user_by( 'login', 'of-it-manager' ), 'transitions: temporary user removed' );
}

/**
 * Phase 3A: export, delete, import round trip, re-import without changes, and the deactivation migration.
 */
function ofwc_it_phase3_config(): void {
	$repo     = new Repository();
	$rules    = new OrderFlowWC\Transitions\Rules( $repo );
	$orders   = new OrderFlowWC\Migration\Orders( $repo );
	$prev_st  = get_option( Repository::OPTION, array() );
	$prev_tr  = get_option( Repository::TRANSITIONS_OPTION, array() );
	$prev_set = get_option( 'ofwc_settings', array() );

	try {
		ofwc_it_delete_test_orders();
		foreach ( array( 'of-cfg-a', 'of-cfg-b' ) as $slug ) {
			if ( null !== $repo->get( $slug ) ) {
				$repo->delete( $slug, 'on-hold' );
			}
		}
		$repo->save(
			array(
				'slug'  => 'of-cfg-a',
				'label' => 'OF Config A',
				'color' => '#aa3300',
				'paid'  => true,
			)
		);
		$repo->save(
			array(
				'slug'          => 'of-cfg-b',
				'label'         => 'OF Config B',
				'email_subject' => 'OF subject {status}',
				'pdf_enabled'   => true,
			)
		);
		$rules->save_all(
			array(
				array(
					'from'            => 'of-cfg-a',
					'to'              => 'of-cfg-b',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => true,
				),
				array(
					'from'  => '*',
					'to'    => 'of-cfg-a',
					'roles' => array( 'administrator' ),
				),
			)
		);

		$exporter = new OrderFlowWC\Config\Exporter( $repo, $rules );
		$json     = $exporter->to_json();
		$before   = $exporter->build();
		ofwc_it_check( 'order-flow-for-woocommerce' === $before['format'] && 1 === $before['version'], 'config: export carries the format marker' );
		ofwc_it_check( ! str_contains( $json, 'logo_id' ), 'config: export has no logo id' );

		// Delete our two statuses (transitions referencing them go too), then import.
		$repo->delete( 'of-cfg-a' );
		$repo->delete( 'of-cfg-b' );
		ofwc_it_check( null === $repo->get( 'of-cfg-a' ) && null === $repo->get( 'of-cfg-b' ), 'config: statuses deleted before import' );

		$importer = new OrderFlowWC\Config\Importer( $repo, $rules );
		$result   = $importer->import_json( $json, OrderFlowWC\Config\Importer::MODE_MERGE );
		ofwc_it_check( $result instanceof OrderFlowWC\Config\ImportResult && in_array( 'of-cfg-a', $result->added, true ) && in_array( 'of-cfg-b', $result->added, true ), 'config: merge import adds both statuses' );
		$after = $exporter->build();
		ofwc_it_check( $before['statuses'] === $after['statuses'], 'config: statuses identical after export, import, export' );
		ofwc_it_check( $before['transitions'] === $after['transitions'], 'config: transitions identical after export, import, export' );

		$again = $importer->import_json( $json, OrderFlowWC\Config\Importer::MODE_REPLACE );
		ofwc_it_check( $again instanceof OrderFlowWC\Config\ImportResult && ! $again->changed() && array() === $again->added && array() === $again->updated, 'config: re-import changes nothing' );
		$final = $exporter->build();
		ofwc_it_check( $before['statuses'] === $final['statuses'] && $before['transitions'] === $final['transitions'], 'config: still identical after re-import' );

		$bad = $importer->import_json( '{"format":"nope","version":1}', OrderFlowWC\Config\Importer::MODE_MERGE );
		ofwc_it_check( is_wp_error( $bad ), 'config: foreign file is rejected' );

		( new Registrar( $repo ) )->register_post_statuses();

		// Replace keeps a status that holds an order.
		$order = ofwc_it_create_order();
		$order->set_status( 'of-cfg-b' );
		$order->save();
		$doc             = $exporter->build();
		$doc['statuses'] = array_values(
			array_filter(
				$doc['statuses'],
				static function ( array $s ): bool {
					return 'of-cfg-b' !== $s['slug'];
				}
			)
		);
		$kept            = $importer->import( $doc, OrderFlowWC\Config\Importer::MODE_REPLACE );
		ofwc_it_check( $kept instanceof OrderFlowWC\Config\ImportResult && isset( $kept->kept['of-cfg-b'] ) && null !== $repo->get( 'of-cfg-b' ), 'config: replace keeps a status that holds orders' );

		// Migration: park in On hold, then restore.
		$counts = $orders->counts();
		if ( array( 'of-cfg-b' ) === array_keys( $counts ) ) {
			ofwc_it_check( OrderFlowWC\Migration\Decisions::needs_screen( $counts ), 'migration: deactivation screen is needed' );
			$moved = $orders->move_to_on_hold();
			$o     = wc_get_order( $order->get_id() );
			ofwc_it_check( 1 === $moved && $o instanceof WC_Order && 'on-hold' === $o->get_status(), 'migration: order moved to On hold' );
			ofwc_it_check( $o instanceof WC_Order && 'of-cfg-b' === $o->get_meta( OrderFlowWC\Migration\Orders::META ), 'migration: previous status remembered' );
			ofwc_it_check( array() === $orders->counts(), 'migration: no order left in a custom status' );

			$restored = $orders->restore();
			$o        = wc_get_order( $order->get_id() );
			ofwc_it_check( 1 === $restored && $o instanceof WC_Order && 'of-cfg-b' === $o->get_status(), 'migration: order restored to its status' );
			ofwc_it_check( $o instanceof WC_Order && '' === (string) $o->get_meta( OrderFlowWC\Migration\Orders::META ), 'migration: marker removed after restore' );

			// A status that vanished is not restored.
			$o->update_meta_data( OrderFlowWC\Migration\Orders::META, 'of-gone' );
			$o->set_status( 'on-hold' );
			$o->save();
			ofwc_it_check( 0 === $orders->restore(), 'migration: unknown status is left alone' );
		} else {
			ofwc_it_check( true, 'migration: skipped, other orders sit in custom statuses on this site' );
		}
	} finally {
		ofwc_it_delete_test_orders();
		update_option( Repository::OPTION, $prev_st, true );
		update_option( Repository::TRANSITIONS_OPTION, $prev_tr, true );
		update_option( 'ofwc_settings', $prev_set, true );
		Repository::flush_cache();
	}
	ofwc_it_check( null === $repo->get( 'of-cfg-a' ) && null === $repo->get( 'of-cfg-b' ), 'config: temporary statuses removed' );
}

try {
	ofwc_it_phase1_statuses();
	ofwc_it_phase2_transitions();
	ofwc_it_phase2_emails();
	ofwc_it_phase2_pdf();
	ofwc_it_phase3_config();
} finally {
	ofwc_it_cleanup( new Repository() );
}

if ( ofwc_it_failures() > 0 ) {
	WP_CLI::halt( 1 );
}
WP_CLI::log( 'All integration checks passed.' );
