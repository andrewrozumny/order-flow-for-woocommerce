<?php
/**
 * Emails module: one WooCommerce email per custom status and recipient type.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Emails;

use OrderFlowWC\History\Context;
use OrderFlowWC\Statuses\Repository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the status emails with WooCommerce and sends them when an order enters a custom status.
 *
 * The status label in the emails comes from the status configuration, not from the screen masking of the status
 * registrar: the shop admin gets the real label and the customer gets the label a customer may see.
 *
 * The comment of the change goes to the shop admin always. It goes to the customer only when the person ticked
 * "Send this comment to the customer" for that change.
 *
 * @phpstan-import-type Status from Repository
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
				$emails[ StatusEmail::email_id( $status['slug'], $type ) ] = $email;
			}
		}
		return $emails;
	}

	/**
	 * Send the emails of a status an order just entered.
	 *
	 * @param mixed  $order   Order.
	 * @param string $to      New status slug (no prefix).
	 * @param string $from    Previous status slug (no prefix).
	 * @param string $comment Comment entered with the change.
	 */
	public function send( $order, string $to = '', string $from = '', string $comment = '' ): void {
		unset( $from );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$status = $this->repository->get( $to );
		if ( null === $status || ( ! $status['email_customer'] && ! $status['email_admin'] ) ) {
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
			$email->trigger_status( $order, 'customer' === $type && ! Context::notify_customer() ? '' : $comment );
		}
	}
}
