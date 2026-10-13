<?php
/**
 * Custom order status storage, sanitizing and validation.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Statuses;

use OrderFlowWC\Migration\Deletions;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the custom statuses kept in the `ofwc_statuses` option.
 *
 * Every status is an array keyed by its slug (without the `wc-` prefix). See defaults()
 * for the full field list.
 *
 * @phpstan-type Status array{slug: string, label: string, color: string, icon: string, paid: bool, show_in_account: bool, reduce_stock: bool, email_customer: bool, email_admin: bool, email_subject: string, email_heading: string, email_body: string, pdf_enabled: bool, pdf_title: string, pdf_text: string, pdf_attach: bool, position: int}
 */
final class Repository {

	public const OPTION             = 'ofwc_statuses';
	public const TRANSITIONS_OPTION = 'ofwc_transitions';
	public const MAX_SLUG_LENGTH    = 17;
	public const BATCH_SIZE         = 50;
	public const MAX_SHORT_TEXT     = 2000;
	public const MAX_LONG_TEXT      = 100000;

	/**
	 * Text fields of a status and their upper bound in characters, so the autoloaded option stays small.
	 *
	 * @return array<string, int> Field => maximum length.
	 */
	public static function text_limits(): array {
		return array(
			'label'         => self::MAX_SHORT_TEXT,
			'email_subject' => self::MAX_SHORT_TEXT,
			'email_heading' => self::MAX_SHORT_TEXT,
			'pdf_title'     => self::MAX_SHORT_TEXT,
			'email_body'    => self::MAX_LONG_TEXT,
			'pdf_text'      => self::MAX_LONG_TEXT,
		);
	}

	/**
	 * Cut a text to a number of characters.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum number of characters.
	 */
	public static function cut( string $text, int $limit ): string {
		return mb_strlen( $text ) > $limit ? mb_substr( $text, 0, $limit ) : $text;
	}

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
	 * @var array<string, Status>|null
	 */
	private static ?array $cache = null;

	/**
	 * Drop the per-request cache.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * A status with every field at its default value.
	 *
	 * @return Status
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
	 * @return array<string, Status>
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
					$list[ $clean['slug'] ] = $clean;
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
	 * @return Status|null
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
	 * @param array<mixed, mixed> $raw Raw status data, any subset of the fields.
	 * @return Status
	 */
	public function sanitize( array $raw ): array {
		$d = $this->defaults();

		$slug = strtolower( trim( $this->to_string( $raw['slug'] ?? '' ) ) );
		$slug = Slug::strip( $slug );

		$color = strtolower( trim( $this->to_string( $raw['color'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
			$color = $d['color'];
		}

		$icon = $this->to_string( $raw['icon'] ?? '' );
		if ( ! Icons::exists( $icon ) ) {
			$icon = $d['icon'];
		}

		$position = $raw['position'] ?? null;

		$out = array(
			'slug'            => $slug,
			'label'           => self::cut( trim( sanitize_text_field( $this->bounded( $raw, 'label' ) ) ), self::MAX_SHORT_TEXT ),
			'color'           => $color,
			'icon'            => $icon,
			'paid'            => $this->to_bool( $raw['paid'] ?? $d['paid'] ),
			'show_in_account' => $this->to_bool( $raw['show_in_account'] ?? $d['show_in_account'] ),
			'reduce_stock'    => $this->to_bool( $raw['reduce_stock'] ?? $d['reduce_stock'] ),
			'email_customer'  => $this->to_bool( $raw['email_customer'] ?? $d['email_customer'] ),
			'email_admin'     => $this->to_bool( $raw['email_admin'] ?? $d['email_admin'] ),
			'email_subject'   => $this->text_or_default( $raw, 'email_subject', $d['email_subject'] ),
			'email_heading'   => $this->text_or_default( $raw, 'email_heading', $d['email_heading'] ),
			'email_body'      => $this->html_or_default( $raw, 'email_body', $d['email_body'] ),
			'pdf_enabled'     => $this->to_bool( $raw['pdf_enabled'] ?? $d['pdf_enabled'] ),
			'pdf_title'       => $this->text_or_default( $raw, 'pdf_title', $d['pdf_title'] ),
			'pdf_text'        => isset( $raw['pdf_text'] ) ? self::cut( wp_kses_post( $this->bounded( $raw, 'pdf_text' ) ), self::MAX_LONG_TEXT ) : $d['pdf_text'],
			'pdf_attach'      => $this->to_bool( $raw['pdf_attach'] ?? $d['pdf_attach'] ),
			'position'        => is_numeric( $position ) ? (int) $position : $d['position'],
		);

		return $out;
	}

	/**
	 * Validate a sanitized status.
	 *
	 * @param array<string, mixed> $status Output of sanitize().
	 * @phpstan-param Status $status
	 * @param string|null          $editing Slug being edited, or null when creating a new status.
	 * @return true|WP_Error
	 */
	public function validate( array $status, ?string $editing = null ) {
		$slug = $status['slug'];

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
		if ( '' === $status['label'] ) {
			return new WP_Error( 'ofwc_label_required', __( 'Enter a label for the status.', 'order-flow-for-woocommerce' ) );
		}

		$existing = $this->all();
		if ( null === $editing ) {
			if ( isset( $existing[ $slug ] ) ) {
				return new WP_Error( 'ofwc_slug_duplicate', __( 'A status with this slug already exists.', 'order-flow-for-woocommerce' ) );
			}
			if ( function_exists( 'wc_get_order_statuses' ) && array_key_exists( Slug::prefixed( $slug ), wc_get_order_statuses() ) ) {
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
	 * @param array<mixed, mixed> $status  Raw status data.
	 * @param string|null         $editing Slug being edited, or null to create a new status.
	 * @return true|WP_Error
	 */
	public function save( array $status, ?string $editing = null ) {
		$long = $this->check_lengths( $status );
		if ( is_wp_error( $long ) ) {
			return $long;
		}
		$clean = $this->sanitize( $status );
		$valid = $this->validate( $clean, $editing );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$all = $this->all();
		if ( null === $editing && ! isset( $status['position'] ) ) {
			$positions         = array_column( $all, 'position' );
			$clean['position'] = array() === $positions ? 1 : max( $positions ) + 1;
		}

		$all[ $clean['slug'] ] = $clean;
		$this->store( $all );
		return true;
	}

	/**
	 * Refuse raw input whose text fields are longer than the stored limits, naming the field.
	 *
	 * @param array<mixed, mixed> $raw Raw status data.
	 * @return true|WP_Error
	 */
	public function check_lengths( array $raw ) {
		$names = array(
			'label'         => __( 'Label', 'order-flow-for-woocommerce' ),
			'email_subject' => __( 'Subject', 'order-flow-for-woocommerce' ),
			'email_heading' => __( 'Heading', 'order-flow-for-woocommerce' ),
			'pdf_title'     => __( 'Document title', 'order-flow-for-woocommerce' ),
			'email_body'    => __( 'Body', 'order-flow-for-woocommerce' ),
			'pdf_text'      => __( 'Text on the document', 'order-flow-for-woocommerce' ),
		);
		foreach ( self::text_limits() as $field => $limit ) {
			if ( isset( $raw[ $field ] ) && mb_strlen( $this->to_string( $raw[ $field ] ) ) > $limit ) {
				return new WP_Error(
					'ofwc_text_too_long',
					sprintf(
						/* translators: 1: field name, 2: maximum number of characters. */
						__( '"%1$s" is too long. The maximum is %2$s characters.', 'order-flow-for-woocommerce' ),
						$names[ $field ],
						number_format_i18n( $limit )
					)
				);
			}
		}
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
				'status'   => Slug::prefixed( $slug ),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);
		return is_object( $result ) && isset( $result->total ) && is_numeric( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Delete a status. Orders in it are moved to the target status first, in batches.
	 *
	 * Without orders the status is removed at once. With orders it is marked "deleting" and stays registered; the first
	 * batch runs now (about twenty seconds at most), the rest in the background, and the status is removed after the
	 * last batch. In that case the result is a WP_Error with the code `ofwc_delete_pending`, which is not a failure.
	 * Moving orders sends no emails to customers unless $notify is true, and never changes stock.
	 *
	 * @param string $slug   Slug without the wc- prefix.
	 * @param string $target Target status slug (with or without the wc- prefix); required when orders exist.
	 * @param bool   $notify Whether customers get the emails of the target status.
	 * @return true|WP_Error
	 */
	public function delete( string $slug, string $target = '', bool $notify = false ) {
		$all = $this->all();
		if ( ! isset( $all[ $slug ] ) ) {
			return new WP_Error( 'ofwc_status_missing', __( 'This status does not exist.', 'order-flow-for-woocommerce' ) );
		}

		$deletions = new Deletions( $this );
		$count     = $this->count_orders( $slug );
		if ( $count > 0 ) {
			if ( ! $deletions->is_deleting( $slug ) ) {
				$target = Slug::strip( $target );
				if ( '' === $target ) {
					return new WP_Error( 'ofwc_target_required', __( 'Choose a status for the orders that are currently in this one.', 'order-flow-for-woocommerce' ) );
				}
				if ( $target === $slug || ! array_key_exists( Slug::prefixed( $target ), wc_get_order_statuses() ) || $deletions->is_deleting( $target ) ) {
					return new WP_Error( 'ofwc_target_invalid', __( 'The chosen target status is not valid.', 'order-flow-for-woocommerce' ) );
				}
				$deletions->start( $slug, $target, $notify );
			}
			// Queued before the work starts, so that a request that runs out of memory still leaves the next batch behind.
			$deletions->schedule( $slug );
			$result = $deletions->run( $slug );
			if ( null !== $result['error'] ) {
				return $result['error'];
			}
			if ( ! $result['done'] ) {
				return new WP_Error(
					'ofwc_delete_pending',
					sprintf(
						/* translators: %s: number of orders still to move. */
						_n( 'The status is being deleted. %s order is left to move; the rest happens in the background.', 'The status is being deleted. %s orders are left to move; the rest happens in the background.', $result['remaining'], 'order-flow-for-woocommerce' ),
						number_format_i18n( $result['remaining'] )
					)
				);
			}
			return true;
		}

		$this->remove( $slug );
		return true;
	}

	/**
	 * Whether a status is waiting for its orders to move before it is removed.
	 *
	 * @param string $slug Slug without the wc- prefix.
	 */
	public function is_deleting( string $slug ): bool {
		return ( new Deletions( $this ) )->is_deleting( $slug );
	}

	/**
	 * Remove a status from the configuration together with the transition rules that mention it.
	 * Orders are not touched: use delete() for that.
	 *
	 * @param string $slug Slug without the wc- prefix.
	 */
	public function remove( string $slug ): void {
		$all = $this->all();
		unset( $all[ $slug ] );
		$this->store( $all );
		$this->remove_transitions_for( $slug );
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
	 * @param array<string, Status> $all Statuses keyed by slug.
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
	 * @param array<mixed, mixed> $raw      Raw data.
	 * @param string              $key     Field key.
	 * @param string              $fallback Default value.
	 */
	private function text_or_default( array $raw, string $key, string $fallback ): string {
		$value = self::cut( trim( sanitize_text_field( $this->bounded( $raw, $key ) ) ), self::MAX_SHORT_TEXT );
		return '' === $value ? $fallback : $value;
	}

	/**
	 * Rich text field, falling back to the default when empty.
	 *
	 * @param array<mixed, mixed> $raw      Raw data.
	 * @param string              $key     Field key.
	 * @param string              $fallback Default value.
	 */
	private function html_or_default( array $raw, string $key, string $fallback ): string {
		$value = self::cut( trim( wp_kses_post( $this->bounded( $raw, $key ) ) ), self::MAX_LONG_TEXT );
		return '' === $value ? $fallback : $value;
	}

	/**
	 * A text field cut to its limit before any cleaning, so a huge value costs no time in the filters.
	 *
	 * @param array<mixed, mixed> $raw Raw data.
	 * @param string              $key Field key.
	 */
	private function bounded( array $raw, string $key ): string {
		return self::cut( $this->to_string( $raw[ $key ] ?? '' ), self::text_limits()[ $key ] ?? self::MAX_LONG_TEXT );
	}
}
