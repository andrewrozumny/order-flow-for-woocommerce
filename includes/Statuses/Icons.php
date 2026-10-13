<?php
/**
 * The icons a status may use.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashicons offered for a status. The list of names is plain data; the labels are translated only where a person
 * picks an icon, never when a stored status is read.
 */
final class Icons {

	/**
	 * Dashicons class names, in the order the form shows them.
	 *
	 * @var string[]
	 */
	private const NAMES = array(
		'dashicons-marker',
		'dashicons-flag',
		'dashicons-clock',
		'dashicons-calendar-alt',
		'dashicons-yes',
		'dashicons-yes-alt',
		'dashicons-no',
		'dashicons-no-alt',
		'dashicons-warning',
		'dashicons-info',
		'dashicons-editor-help',
		'dashicons-star-filled',
		'dashicons-heart',
		'dashicons-lock',
		'dashicons-unlock',
		'dashicons-update',
		'dashicons-backup',
		'dashicons-hammer',
		'dashicons-admin-tools',
		'dashicons-admin-generic',
		'dashicons-art',
		'dashicons-media-document',
		'dashicons-clipboard',
		'dashicons-media-text',
		'dashicons-email-alt',
		'dashicons-phone',
		'dashicons-cart',
		'dashicons-products',
		'dashicons-archive',
		'dashicons-building',
		'dashicons-location',
		'dashicons-airplane',
		'dashicons-money-alt',
		'dashicons-tickets-alt',
		'dashicons-thumbs-up',
		'dashicons-thumbs-down',
		'dashicons-visibility',
		'dashicons-search',
		'dashicons-controls-pause',
	);

	/**
	 * Whether a class name is one of the icons.
	 *
	 * @param string $icon Dashicons class.
	 */
	public static function exists( string $icon ): bool {
		return in_array( $icon, self::NAMES, true );
	}

	/**
	 * Icons with their translated labels.
	 *
	 * @return array<string, string> Dashicons class => label.
	 */
	public static function all(): array {
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
}
