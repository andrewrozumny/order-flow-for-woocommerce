<?php
/**
 * Custom order status storage, sanitizing and validation.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Statuses;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the custom statuses kept in the `ofwc_statuses` option.
 *
 * Every status is an array keyed by its slug (without the `wc-` prefix). See defaults()
 * for the full field list.
 */
final class Repository {

	public const OPTION             = 'ofwc_statuses';
	public const TRANSITIONS_OPTION = 'ofwc_transitions';
	public const MAX_SLUG_LENGTH    = 17;
	public const BATCH_SIZE         = 50;

	/**
	 * Slugs that belong to WooCommerce core and can never be used.
	 *
	 * @var string[]
	 */
	public const CORE_SLUGS = array(
		'pending',
		'processing',
		'on-hold',
		'completed',
		'cancelled',
		'refunded',
		'failed',
		'checkout-draft',
		'draft',
	);

	/**
	 * Per-request cache of all().
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Drop the per-request cache.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Dashicons a status may use.
	 *
	 * @return array<string, string> Dashicons class => label.
	 */
	public static function icons(): array {
		return array(
			'dashicons-marker'         => __( 'Dot', 'order-flow-for-woocommerce' ),
			'dashicons-flag'           => __( 'Flag', 'order-flow-for-woocommerce' ),
			'dashicons-clock'          => __( 'Clock', 'order-flow-for-woocommerce' ),
			'dashicons-calendar-alt'   => __( 'Calendar', 'order-flow-for-woocommerce' ),
			'dashicons-yes'            => __( 'Check', 'order-flow-for-woocommerce' ),
			'dashicons-yes-alt'        => __( 'Check circle', 'order-flow-for-woocommerce' ),
			'dashicons-no'             => __( 'Cross', 'order-flow-for-woocommerce' ),
			'dashicons-no-alt'         => __( 'Cross circle', 'order-flow-for-woocommerce' ),
			'dashicons-warning'        => __( 'Warning', 'order-flow-for-woocommerce' ),
			'dashicons-info'           => __( 'Info', 'order-flow-for-woocommerce' ),
			'dashicons-editor-help'    => __( 'Question', 'order-flow-for-woocommerce' ),
			'dashicons-star-filled'    => __( 'Star', 'order-flow-for-woocommerce' ),
			'dashicons-heart'          => __( 'Heart', 'order-flow-for-woocommerce' ),
			'dashicons-lock'           => __( 'Lock', 'order-flow-for-woocommerce' ),
			'dashicons-unlock'         => __( 'Unlock', 'order-flow-for-woocommerce' ),
			'dashicons-update'         => __( 'Refresh', 'order-flow-for-woocommerce' ),
			'dashicons-backup'         => __( 'Backup', 'order-flow-for-woocommerce' ),
			'dashicons-hammer'         => __( 'Hammer', 'order-flow-for-woocommerce' ),
			'dashicons-admin-tools'    => __( 'Tools', 'order-flow-for-woocommerce' ),
			'dashicons-admin-generic'  => __( 'Gear', 'order-flow-for-woocommerce' ),
			'dashicons-art'            => __( 'Palette', 'order-flow-for-woocommerce' ),
			'dashicons-media-document' => __( 'Document', 'order-flow-for-woocommerce' ),
			'dashicons-clipboard'      => __( 'Clipboard', 'order-flow-for-woocommerce' ),
			'dashicons-media-text'     => __( 'Text file', 'order-flow-for-woocommerce' ),
			'dashicons-email-alt'      => __( 'Email', 'order-flow-for-woocommerce' ),
			'dashicons-phone'          => __( 'Phone', 'order-flow-for-woocommerce' ),
			'dashicons-cart'           => __( 'Cart', 'order-flow-for-woocommerce' ),
			'dashicons-products'       => __( 'Product', 'order-flow-for-woocommerce' ),
			'dashicons-archive'        => __( 'Archive', 'order-flow-for-woocommerce' ),
			'dashicons-building'       => __( 'Building', 'order-flow-for-woocommerce' ),
			'dashicons-location'       => __( 'Location', 'order-flow-for-woocommerce' ),
			'dashicons-airplane'       => __( 'Airplane', 'order-flow-for-woocommerce' ),
			'dashicons-money-alt'      => __( 'Money', 'order-flow-for-woocommerce' ),
			'dashicons-tickets-alt'    => __( 'Ticket', 'order-flow-for-woocommerce' ),
			'dashicons-thumbs-up'      => __( 'Thumbs up', 'order-flow-for-woocommerce' ),
			'dashicons-thumbs-down'    => __( 'Thumbs down', 'order-flow-for-woocommerce' ),
			'dashicons-visibility'     => __( 'Eye', 'order-flow-for-woocommerce' ),
			'dashicons-search'         => __( 'Search', 'order-flow-for-woocommerce' ),
			'dashicons-controls-pause' => __( 'Pause', 'order-flow-for-woocommerce' ),
		);
	}

	/**
	 * A status with every field at its default value.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'slug'            => '',
			'label'           => '',
			'color'           => '#777777',
			'icon'            => 'dashicons-marker',
			'paid'            => false,
			'show_in_account' => true,
			'reduce_stock'    => false,
			'email_customer'  => false,
			'email_admin'     => false,
			'email_subject'   => __( 'Your {site_title} order {order_number} is now {status}', 'order-flow-for-woocommerce' ),
			'email_heading'   => __( 'Order {status}', 'order-flow-for-woocommerce' ),
			'email_body'      => __( '<p>Hi {customer_name},</p><p>Your order {order_number} is now {status}.</p><p>{note}</p>', 'order-flow-for-woocommerce' ),
			'pdf_enabled'     => false,
			'pdf_title'       => '{status}',
			'pdf_text'        => '',
			'pdf_attach'      => false,
			'position'        => 0,
		);
	}

	/**
	 * Every status, sorted by position then slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		$list   = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = $this->sanitize( $row );
				if ( '' !== $clean['slug'] ) {
					$list[ (string) $clean['slug'] ] = $clean;
				}
			}
		}

		uasort(
			$list,
			static function ( array $a, array $b ): int {
				return array( $a['position'], $a['slug'] ) <=> array( $b['position'], $b['slug'] );
			}
		);

		self::$cache = $list;
		return $list;
	}

	/**
	 * One status or null.
	 *
	 * @param string $slug Slug without the wc- prefix.
	 * @return array<string, mixed>|null
	 */
	public function get( string $slug ): ?array {
		$all = $this->all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Build a slug from a label.
	 *
	 * @param string $label Human label.
	 */
	public function slug_from_label( string $label ): string {
		$slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $label ) );
		$slug = trim( (string) $slug, '-' );
		$slug = substr( $slug, 0, self::MAX_SLUG_LENGTH );
		return rtrim( $slug, '-' );
	}

	/**
	 * Coerce any input into a complete, well-typed status. Never fails; validate() judges the result.
	 *
	 * @param array<string, mixed> $raw Raw status data, any subset of the fields.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		$d = $this->defaults();

		$slug = strtolower( trim( $this->to_string( $raw['slug'] ?? '' ) ) );
		if ( str_starts_with( $slug, 'wc-' ) ) {
			$slug = substr( $slug, 3 );
		}

		$color = strtolower( trim( $this->to_string( $raw['color'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
			$color = (string) $d['color'];
		}

		$icon = $this->to_string( $raw['icon'] ?? '' );
		if ( ! array_key_exists( $icon, self::icons() ) ) {
			$icon = (string) $d['icon'];
		}

		$out = array(
			'slug'            => $slug,
			'label'           => trim( sanitize_text_field( $this->to_string( $raw['label'] ?? '' ) ) ),
			'color'           => $color,
			'icon'            => $icon,
			'paid'            => $this->to_bool( $raw['paid'] ?? $d['paid'] ),
			'show_in_account' => $this->to_bool( $raw['show_in_account'] ?? $d['show_in_account'] ),
			'reduce_stock'    => $this->to_bool( $raw['reduce_stock'] ?? $d['reduce_stock'] ),
			'email_customer'  => $this->to_bool( $raw['email_customer'] ?? $d['email_customer'] ),
			'email_admin'     => $this->to_bool( $raw['email_admin'] ?? $d['email_admin'] ),
			'email_subject'   => $this->text_or_default( $raw, 'email_subject', (string) $d['email_subject'] ),
			'email_heading'   => $this->text_or_default( $raw, 'email_heading', (string) $d['email_heading'] ),
			'email_body'      => $this->html_or_default( $raw, 'email_body', (string) $d['email_body'] ),
			'pdf_enabled'     => $this->to_bool( $raw['pdf_enabled'] ?? $d['pdf_enabled'] ),
			'pdf_title'       => $this->text_or_default( $raw, 'pdf_title', (string) $d['pdf_title'] ),
			'pdf_text'        => isset( $raw['pdf_text'] ) ? wp_kses_post( $this->to_string( $raw['pdf_text'] ) ) : (string) $d['pdf_text'],
			'pdf_attach'      => $this->to_bool( $raw['pdf_attach'] ?? $d['pdf_attach'] ),
			'position'        => (int) ( is_numeric( $raw['position'] ?? null ) ? $raw['position'] : $d['position'] ),
		);

		return $out;
	}

	/**
	 * Validate a sanitized status.
	 *
	 * @param array<string, mixed> $status  Output of sanitize().
	 * @param string|null          $editing Slug being edited, or null when creating a new status.
	 * @return true|WP_Error
	 */
	public function validate( array $status, ?string $editing = null ) {
		$slug = (string) ( $status['slug'] ?? '' );

		if ( '' === $slug ) {
			return new WP_Error( 'ofwc_slug_required', __( 'Enter a slug for the status.', 'order-flow-for-woocommerce' ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug ) ) {
			return new WP_Error( 'ofwc_slug_format', __( 'The slug may only contain lowercase letters, numbers and hyphens, and cannot start or end with a hyphen.', 'order-flow-for-woocommerce' ) );
		}
		if ( strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			return new WP_Error(
				'ofwc_slug_length',
				sprintf(
					/* translators: %d: maximum number of characters in a status slug. */
					__( 'The slug can be at most %d characters long.', 'order-flow-for-woocommerce' ),
					self::MAX_SLUG_LENGTH
				)
			);
		}
		if ( in_array( $slug, self::CORE_SLUGS, true ) ) {
			return new WP_Error( 'ofwc_slug_core', __( 'This slug is used by a WooCommerce core status. Choose another one.', 'order-flow-for-woocommerce' ) );
		}
		if ( '' === (string) ( $status['label'] ?? '' ) ) {
			return new WP_Error( 'ofwc_label_required', __( 'Enter a label for the status.', 'order-flow-for-woocommerce' ) );
		}

		$existing = $this->all();
		if ( null === $editing ) {
			if ( isset( $existing[ $slug ] ) ) {
				return new WP_Error( 'ofwc_slug_duplicate', __( 'A status with this slug already exists.', 'order-flow-for-woocommerce' ) );
			}
			if ( function_exists( 'wc_get_order_statuses' ) && array_key_exists( 'wc-' . $slug, wc_get_order_statuses() ) ) {
				return new WP_Error( 'ofwc_slug_taken', __( 'Another plugin already registers an order status with this slug.', 'order-flow-for-woocommerce' ) );
			}
		} else {
			if ( $editing !== $slug ) {
				return new WP_Error( 'ofwc_slug_immutable', __( 'The slug of an existing status cannot be changed because orders reference it.', 'order-flow-for-woocommerce' ) );
			}
			if ( ! isset( $existing[ $editing ] ) ) {
				return new WP_Error( 'ofwc_status_missing', __( 'This status does not exist.', 'order-flow-for-woocommerce' ) );
			}
		}

		return true;
	}

	/**
	 * Sanitize, validate and store a status.
	 *
	 * @param array<string, mixed> $status  Raw status data.
	 * @param string|null          $editing Slug being edited, or null to create a new status.
	 * @return true|WP_Error
	 */
	public function save( array $status, ?string $editing = null ) {
		$clean = $this->sanitize( $status );
		$valid = $this->validate( $clean, $editing );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$all = $this->all();
		if ( null === $editing && ! isset( $status['position'] ) ) {
			$positions         = array_map( 'intval', array_column( $all, 'position' ) );
			$clean['position'] = array() === $positions ? 1 : max( $positions ) + 1;
		}

		$all[ (string) $clean['slug'] ] = $clean;
		$this->store( $all );
		return true;
	}

	/**
	 * Number of orders currently in the status.
	 *
	 * @param string $slug Slug without the wc- prefix.
	 */
	public function count_orders( string $slug ): int {
		$result = wc_get_orders(
			array(
				'status'   => 'wc-' . $slug,
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);
		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Delete a status. Orders in it are moved to the target status first.
	 *
	 * @param string $slug   Slug without the wc- prefix.
	 * @param string $target Target status slug (with or without the wc- prefix); required when orders exist.
	 * @return true|WP_Error
	 */
	public function delete( string $slug, string $target = '' ) {
		$all = $this->all();
		if ( ! isset( $all[ $slug ] ) ) {
			return new WP_Error( 'ofwc_status_missing', __( 'This status does not exist.', 'order-flow-for-woocommerce' ) );
		}

		$count = $this->count_orders( $slug );
		if ( $count > 0 ) {
			$target = str_starts_with( $target, 'wc-' ) ? substr( $target, 3 ) : $target;
			if ( '' === $target ) {
				return new WP_Error( 'ofwc_target_required', __( 'Choose a status for the orders that are currently in this one.', 'order-flow-for-woocommerce' ) );
			}
			if ( $target === $slug || ! array_key_exists( 'wc-' . $target, wc_get_order_statuses() ) ) {
				return new WP_Error( 'ofwc_target_invalid', __( 'The chosen target status is not valid.', 'order-flow-for-woocommerce' ) );
			}
			$moved = $this->move_orders( $slug, $target );
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
		}

		unset( $all[ $slug ] );
		$this->store( $all );
		$this->remove_transitions_for( $slug );
		return true;
	}

	/**
	 * Move every order from one status to another in batches.
	 *
	 * @param string $from Source slug.
	 * @param string $to   Target slug.
	 * @return true|WP_Error
	 */
	private function move_orders( string $from, string $to ) {
		$previous = null;
		do {
			$ids = $this->order_ids( 'wc-' . $from, self::BATCH_SIZE );
			if ( array() === $ids ) {
				return true;
			}
			if ( $ids === $previous ) {
				return new WP_Error( 'ofwc_move_failed', __( 'Some orders could not be moved to the target status. The status was not deleted.', 'order-flow-for-woocommerce' ) );
			}
			$previous = $ids;

			foreach ( $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof \WC_Order ) {
					$order->update_status( $to, __( 'Order Flow: the previous status was deleted.', 'order-flow-for-woocommerce' ) );
				}
			}
		} while ( true );
	}

	/**
	 * IDs of orders in a status.
	 *
	 * @param string $status Status with the wc- prefix.
	 * @param int    $limit  Maximum number of IDs.
	 * @return int[]
	 */
	private function order_ids( string $status, int $limit ): array {
		$found = wc_get_orders(
			array(
				'status' => $status,
				'limit'  => $limit,
				'return' => 'ids',
			)
		);
		$ids   = array();
		foreach ( is_array( $found ) ? $found : array() as $item ) {
			if ( $item instanceof \WC_Order ) {
				$ids[] = $item->get_id();
			} elseif ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			}
		}
		return $ids;
	}

	/**
	 * Drop transition rules that mention a removed status.
	 *
	 * @param string $slug Removed slug.
	 */
	private function remove_transitions_for( string $slug ): void {
		$rules = get_option( self::TRANSITIONS_OPTION, array() );
		if ( ! is_array( $rules ) || array() === $rules ) {
			return;
		}
		$kept = array_values(
			array_filter(
				$rules,
				static function ( $rule ) use ( $slug ): bool {
					return ! is_array( $rule ) || ( ( $rule['from'] ?? '' ) !== $slug && ( $rule['to'] ?? '' ) !== $slug );
				}
			)
		);
		if ( count( $kept ) !== count( $rules ) ) {
			update_option( self::TRANSITIONS_OPTION, $kept, true );
		}
	}

	/**
	 * Persist the full set.
	 *
	 * @param array<string, array<string, mixed>> $all Statuses keyed by slug.
	 */
	private function store( array $all ): void {
		update_option( self::OPTION, $all, true );
		self::flush_cache();
	}

	/**
	 * Scalar to string, anything else to empty.
	 *
	 * @param mixed $value Value.
	 */
	private function to_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Loose boolean.
	 *
	 * @param mixed $value Value.
	 */
	private function to_bool( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Plain text field, falling back to the default when empty.
	 *
	 * @param array<string, mixed> $raw     Raw data.
	 * @param string               $key     Field key.
	 * @param string               $fallback Default value.
	 */
	private function text_or_default( array $raw, string $key, string $fallback ): string {
		$value = trim( sanitize_text_field( $this->to_string( $raw[ $key ] ?? '' ) ) );
		return '' === $value ? $fallback : $value;
	}

	/**
	 * Rich text field, falling back to the default when empty.
	 *
	 * @param array<string, mixed> $raw     Raw data.
	 * @param string               $key     Field key.
	 * @param string               $fallback Default value.
	 */
	private function html_or_default( array $raw, string $key, string $fallback ): string {
		$value = trim( wp_kses_post( $this->to_string( $raw[ $key ] ?? '' ) ) );
		return '' === $value ? $fallback : $value;
	}
}
