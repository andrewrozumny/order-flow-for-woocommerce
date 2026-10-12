<?php
/**
 * Background jobs of the order migrations.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the job callbacks that Action Scheduler (or WP-Cron) calls: restoring parked orders and
 * moving the orders of a status that is being deleted.
 */
final class Module {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( Orders::RESTORE_HOOK, array( new Orders(), 'run_restore_job' ) );
		( new Deletions() )->register();
		Lifecycle::register();
	}
}
