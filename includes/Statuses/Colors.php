<?php
/**
 * Status colors in the admin order list.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Statuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives each custom status its color and a readable text color on the order list pills.
 */
final class Colors {

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Status storage.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Color the custom statuses in the admin order list.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}
		$css = $this->css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'woocommerce_admin_styles', $css );
		}
	}

	/**
	 * CSS for the admin order status pills.
	 */
	public function css(): string {
		$css = '';
		foreach ( $this->repository->all() as $status ) {
			$slug  = $status['slug'];
			$color = $status['color'];
			$css  .= sprintf(
				'.order-status.status-%1$s{background:%2$s;color:%3$s;}',
				$slug,
				$color,
				self::text_color( $color )
			);
		}
		return $css;
	}

	/**
	 * Readable text color (dark or white) for a background.
	 *
	 * @param string $hex Background as #rrggbb.
	 */
	public static function text_color( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 1 !== preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return '#1d2327';
		}
		$red       = (int) hexdec( substr( $hex, 0, 2 ) );
		$green     = (int) hexdec( substr( $hex, 2, 2 ) );
		$blue      = (int) hexdec( substr( $hex, 4, 2 ) );
		$luminance = ( 0.299 * $red + 0.587 * $green + 0.114 * $blue ) / 255;
		return $luminance > 0.6 ? '#1d2327' : '#ffffff';
	}
}
