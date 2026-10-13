<?php
/**
 * Small database helpers for the uninstall code.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two helpers shared by the uninstall steps: run a prepared statement, and check that a table exists.
 */
final class Db {

	/**
	 * Run a prepared statement.
	 *
	 * @param string|null $query Output of $wpdb->prepare().
	 */
	public static function execute( ?string $query ): void {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		if ( null !== $query ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The statement comes from $wpdb->prepare() at the call site.
			$wpdb->query( $query );
		}
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Full table name.
	 */
	public static function table_exists( string $table ): bool {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check on uninstall.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
