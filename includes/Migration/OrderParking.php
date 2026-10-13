<?php
/**
 * Orders out of the custom statuses on uninstall.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Migration;

use OrderFlowWC\Statuses\Slug;
use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orders still in a custom status go to On hold with their old status kept in the order meta and a note on the
 * order, so they stay visible in WooCommerce. This is done with set-based SQL for both order storages (no
 * WooCommerce hooks fire, so stock and emails are untouched), then the order caches are cleared.
 */
final class OrderParking {

	/**
	 * Slugs of the configured statuses.
	 *
	 * @return string[]
	 */
	private static function status_slugs(): array {
		$stored = get_option( 'ofwc_statuses', array() );
		$slugs  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $row ) {
			$slug = strtolower( trim( Value::string( is_array( $row ) ? ( $row['slug'] ?? $key ) : $key ) ) );
			if ( '' !== $slug && 1 === preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Move the orders of every configured status to On hold.
	 */
	public static function run(): void {
		$slugs = self::status_slugs();
		if ( array() === $slugs ) {
			return;
		}
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$hpos_enabled = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
		$sync_enabled = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' );
		$hpos_table   = $wpdb->prefix . 'wc_orders';
		$has_hpos     = Db::table_exists( $hpos_table ) && Db::table_exists( $wpdb->prefix . 'wc_orders_meta' );

		foreach ( $slugs as $slug ) {
			$status = Slug::prefixed( $slug );
			if ( $has_hpos && ( $hpos_enabled || $sync_enabled ) ) {
				self::park_in_hpos( $status, $slug, $hpos_enabled );
			}
			if ( ! $hpos_enabled || $sync_enabled ) {
				self::park_in_posts( $status, $slug, ! $hpos_enabled );
			}
		}
		self::flush_order_caches();
	}

	/**
	 * Park the orders of one status in the HPOS tables.
	 *
	 * @param string $status Status with the wc- prefix.
	 * @param string $slug   Status slug.
	 * @param bool   $notes  Whether to add the order notes (only once per order, from the authoritative storage).
	 */
	private static function park_in_hpos( string $status, string $slug, bool $notes ): void {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$orders = $wpdb->prefix . 'wc_orders';
		$meta   = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Set-based update of order tables on uninstall; tables are built from the WordPress prefix and passed as identifiers.
		Db::execute(
			$wpdb->prepare(
				'INSERT INTO %i (order_id, meta_key, meta_value) SELECT o.id, %s, %s FROM %i o WHERE o.status = %s AND o.type = %s AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.order_id = o.id AND m.meta_key = %s)',
				$meta,
				Orders::META,
				$slug,
				$orders,
				$status,
				'shop_order',
				$meta,
				Orders::META
			)
		);
		if ( $notes ) {
			self::insert_notes( $orders, 'id', 'status', 'type', 'shop_order', $status, $slug );
		}
		Db::execute(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, date_updated_gmt = %s WHERE status = %s AND type = %s',
				$orders,
				Slug::prefixed( 'on-hold' ),
				current_time( 'mysql', true ),
				$status,
				'shop_order'
			)
		);
		// phpcs:enable
	}

	/**
	 * Park the orders of one status in the posts tables.
	 *
	 * @param string $status Status with the wc- prefix.
	 * @param string $slug   Status slug.
	 * @param bool   $notes  Whether to add the order notes (only once per order, from the authoritative storage).
	 */
	private static function park_in_posts( string $status, string $slug, bool $notes ): void {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$types = array( 'shop_order', 'shop_order_placehold' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Set-based update of order posts on uninstall; table names come from $wpdb.
		Db::execute(
			$wpdb->prepare(
				'INSERT INTO %i (post_id, meta_key, meta_value) SELECT p.ID, %s, %s FROM %i p WHERE p.post_status = %s AND p.post_type IN (%s, %s) AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s)',
				$wpdb->postmeta,
				Orders::META,
				$slug,
				$wpdb->posts,
				$status,
				$types[0],
				$types[1],
				$wpdb->postmeta,
				Orders::META
			)
		);
		if ( $notes ) {
			self::insert_notes( $wpdb->posts, 'ID', 'post_status', 'post_type', 'shop_order', $status, $slug );
		}
		Db::execute(
			$wpdb->prepare(
				'UPDATE %i SET post_status = %s WHERE post_status = %s AND post_type IN (%s, %s)',
				$wpdb->posts,
				Slug::prefixed( 'on-hold' ),
				$status,
				$types[0],
				$types[1]
			)
		);
		// phpcs:enable
	}

	/**
	 * One private order note per order in the status.
	 *
	 * @param string $table       Orders table or posts table.
	 * @param string $id_column   Name of the ID column.
	 * @param string $status_col  Name of the status column.
	 * @param string $type_column Name of the type column.
	 * @param string $type        Order type value.
	 * @param string $status      Status with the wc- prefix.
	 * @param string $slug        Status slug.
	 */
	private static function insert_notes( string $table, string $id_column, string $status_col, string $type_column, string $type, string $status, string $slug ): void {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;
		$note = sprintf(
			'Order Flow: the plugin was removed. The order was moved from "%s" to On hold so that it stays visible in WooCommerce.',
			$slug
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Set-based insert of order notes on uninstall.
		Db::execute(
			$wpdb->prepare(
				"INSERT INTO %i (comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_karma, comment_approved, comment_agent, comment_type, comment_parent, user_id) SELECT t.%i, 'WooCommerce', '', '', '', %s, %s, %s, 0, '1', 'WooCommerce', 'order_note', 0, 0 FROM %i t WHERE t.%i = %s AND t.%i = %s",
				$wpdb->comments,
				$id_column,
				current_time( 'mysql' ),
				current_time( 'mysql', true ),
				$note,
				$table,
				$status_col,
				$status,
				$type_column,
				$type
			)
		);
		// phpcs:enable
	}

	/**
	 * Drop the cached orders so WooCommerce reads the new statuses.
	 */
	private static function flush_order_caches(): void {
		if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
			wc_delete_shop_order_transients();
		}
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) && function_exists( 'wp_cache_flush_group' ) ) {
			foreach ( array( 'orders', 'order-items', 'order_notes', 'posts', 'post_meta', 'comment' ) as $group ) {
				wp_cache_flush_group( $group );
			}
			return;
		}
		wp_cache_flush();
	}
}
