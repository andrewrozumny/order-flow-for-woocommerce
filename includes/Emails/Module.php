<?php
/**
 * Emails module: one WooCommerce email per custom status and recipient type.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Emails;

use OrderFlowWC\Statuses\Repository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the status emails with WooCommerce and sends them when an order enters a custom status.
 *
 * The status label in the emails always comes from the status configuration, so it is
 * never affected by the front-end label masking done by the status registrar.
 */
final class Module {

	/**
	 * Status repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status repository.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
		add_action( 'ofwc_status_entered', array( $this, 'send' ), 10, 4 );
	}

	/**
	 * Add the status emails to the WooCommerce email list.
	 *
	 * @param mixed $emails Registered emails.
	 * @return mixed
	 */
	public function register_emails( $emails ) {
		if ( ! is_array( $emails ) ) {
			return $emails;
		}
		foreach ( $this->repository->all() as $status ) {
			foreach ( array( 'customer', 'admin' ) as $type ) {
				$email = new StatusEmail( $status, $type );
				$emails[ StatusEmail::email_id( (string) $status['slug'], $type ) ] = $email;
			}
		}
		return $emails;
	}

	/**
	 * Send the emails of a status an order just entered.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $to      New status slug (no prefix).
	 * @param string   $from    Previous status slug (no prefix).
	 * @param string   $comment Comment entered with the change.
	 */
	public function send( $order, $to = '', $from = '', $comment = '' ): void {
		unset( $from );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$to     = (string) $to;
		$status = $this->repository->get( $to );
		if ( null === $status || ( empty( $status['email_customer'] ) && empty( $status['email_admin'] ) ) ) {
			return;
		}

		// WooCommerce loads its email classes lazily; this makes sure they exist.
		$registered = WC()->mailer()->get_emails();

		foreach ( array( 'customer', 'admin' ) as $type ) {
			$email = $registered[ StatusEmail::email_id( $to, $type ) ] ?? null;
			if ( ! $email instanceof StatusEmail ) {
				// The status was created after WooCommerce built its list.
				$email = new StatusEmail( $status, $type );
			}
			$email->trigger_status( $order, (string) $comment );
		}
	}
}
