<?php
/**
 * Runs when the plugin is deleted: orders in custom statuses go to On hold, then the plugin's data is removed.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/autoload.php';

\OrderFlowWC\Migration\Uninstaller::run();
