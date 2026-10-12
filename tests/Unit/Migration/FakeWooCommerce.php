<?php
/**
 * WooCommerce stand-ins backed by FakeShop: a WC_Order double and the wc_* functions. Declares globals, so
 * it is only loaded by tests that run in a separate process.
 *
 * @package OrderFlowWC
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName, Squiz.Commenting, Universal.Files.SeparateFunctionsFromOO, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter


if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Order double backed by FakeShop.
	 */
	class WC_Order {

		private int $id;

		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_status(): string {
			return \OrderFlowWC\Tests\Unit\Migration\FakeShop::$orders[ $this->id ]['status'];
		}

		public function get_meta( string $key ) {
			return \OrderFlowWC\Tests\Unit\Migration\FakeShop::$orders[ $this->id ]['meta'][ $key ] ?? '';
		}

		public function update_meta_data( string $key, $value ): void {
			\OrderFlowWC\Tests\Unit\Migration\FakeShop::$orders[ $this->id ]['meta'][ $key ] = $value;
		}

		public function delete_meta_data( string $key ): void {
			unset( \OrderFlowWC\Tests\Unit\Migration\FakeShop::$orders[ $this->id ]['meta'][ $key ] );
		}

		public function add_order_note( string $note ): void {
			\OrderFlowWC\Tests\Unit\Migration\FakeShop::$orders[ $this->id ]['notes'][] = $note;
		}

		public function update_status( string $status, string $note = '' ): void {
			$shop = \OrderFlowWC\Tests\Unit\Migration\FakeShop::class;
			if ( $shop::$orders[ $this->id ]['fail'] ) {
				return;
			}
			$shop::$orders[ $this->id ]['status'] = $status;
			$shop::$log[]                         = $this->id . ':' . $status;
			if ( '' !== $note ) {
				$this->add_order_note( $note );
			}
			// A mail is "sent" unless something short-circuits wp_mail.
			if ( null === apply_filters( 'pre_wp_mail', null ) ) {
				++$shop::$orders[ $this->id ]['mails'];
			}
			do_action( 'woocommerce_order_status_' . $status, $this->id );
		}

		public function save(): int {
			return $this->id;
		}
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( array $args = array() ) {
		return \OrderFlowWC\Tests\Unit\Migration\FakeShop::query( $args );
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id = false ) {
		return is_int( $id ) && isset( \OrderFlowWC\Tests\Unit\Migration\FakeShop::$orders[ $id ] ) ? new WC_Order( $id ) : false;
	}
}

if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	function wc_get_order_statuses(): array {
		return \OrderFlowWC\Tests\Unit\Migration\FakeShop::$statuses;
	}
}
