<?php
/**
 * Removes plugin options on uninstall. Orders and their meta are left untouched.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ofwc_statuses' );
delete_option( 'ofwc_transitions' );
delete_option( 'ofwc_settings' );
