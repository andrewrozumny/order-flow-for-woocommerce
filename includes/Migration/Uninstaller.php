<?php
/**
 * Plugin removal: orders out of the custom statuses, then everything the plugin stored.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Transitions\Notices;
use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs from uninstall.php, without WooCommerce and without the rest of the plugin. Orders still in a custom
 * status go to On hold first (see OrderParking), then the options, scheduled jobs and folders of the plugin are
 * removed. Order history (`_ofwc_history`) is shop data and stays. On a multisite network every site is processed,
 * 100 at a time.
 */
final class Uninstaller {

	public const SITES_BATCH = 100;

	/**
	 * Option, transient and job names the plugin owns.
	 *
	 * @var string[]
	 */
	public const HOOKS = array( 'ofwc_cleanup_tmp', 'ofwc_restore_batch', 'ofwc_delete_status_batch', 'ofwc_network_flag_sites' );

	/**
	 * Clean the whole installation.
	 */
	public static function run(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$offset = 0;
			do {
				$ids = get_sites(
					array(
						'fields'  => 'ids',
						'number'  => self::SITES_BATCH,
						'offset'  => $offset,
						'orderby' => 'id',
						'order'   => 'ASC',
					)
				);
				foreach ( $ids as $site_id ) {
					switch_to_blog( Value::int( $site_id ) );
					self::clean_site();
					restore_current_blog();
				}
				$found   = count( $ids );
				$offset += $found;
			} while ( self::SITES_BATCH === $found );
			return;
		}
		self::clean_site();
	}

	/**
	 * Clean the current site.
	 */
	public static function clean_site(): void {
		OrderParking::run();
		self::delete_options();
		self::delete_jobs();
		self::delete_folders();
	}

	/**
	 * Delete every option and transient of the plugin.
	 */
	private static function delete_options(): void {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$patterns = array(
			$wpdb->esc_like( 'ofwc_' ) . '%',
			$wpdb->esc_like( '_transient_ofwc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ofwc_' ) . '%',
			$wpdb->esc_like( 'woocommerce_ofwc_' ) . '%' . $wpdb->esc_like( '_settings' ),
		);
		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing the plugin's own options by prefix on uninstall.
			Db::execute( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $pattern ) );
		}
		// The notice queue of every user (user meta, so the option patterns above do not reach it).
		delete_metadata( 'user', 0, Notices::META_KEY, '', true );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Remove scheduled work: WP-Cron events and Action Scheduler actions of the plugin.
	 */
	private static function delete_jobs(): void {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		foreach ( self::HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		if ( ! Db::table_exists( $actions ) ) {
			return;
		}
		$marks = implode( ', ', array_fill( 0, count( self::HOOKS ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Removing the plugin's own Action Scheduler rows on uninstall; the placeholder list is built from a constant.
		if ( Db::table_exists( $logs ) ) {
			Db::execute(
				$wpdb->prepare(
					"DELETE FROM %i WHERE action_id IN (SELECT action_id FROM %i WHERE hook IN ($marks))",
					array_merge( array( $logs, $actions ), self::HOOKS )
				)
			);
		}
		Db::execute( $wpdb->prepare( "DELETE FROM %i WHERE hook IN ($marks)", array_merge( array( $actions ), self::HOOKS ) ) );
		// phpcs:enable
	}

	/**
	 * Remove the temporary PDF folders and the font cache.
	 */
	private static function delete_folders(): void {
		$uploads = wp_upload_dir( null, false );
		$bases   = array();
		if ( '' !== $uploads['basedir'] ) {
			$bases[] = untrailingslashit( $uploads['basedir'] ) . '/ofwc-tmp';
			$bases[] = untrailingslashit( $uploads['basedir'] ) . '/ofwc-fonts';
		}
		$tmp = get_temp_dir();
		if ( '' !== $tmp ) {
			$bases[] = untrailingslashit( $tmp ) . '/ofwc-tmp';
		}
		foreach ( $bases as $dir ) {
			self::remove_tree( $dir );
		}
	}

	/**
	 * Delete a folder with everything in it. Symbolic links are removed, never followed.
	 *
	 * @param string $dir Folder.
	 */
	private static function remove_tree( string $dir ): void {
		if ( is_link( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( false === $items ? array() : $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_tree( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own folder on uninstall.
		rmdir( $dir );
	}
}
