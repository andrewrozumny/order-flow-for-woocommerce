<?php
/**
 * Documents tab: logo and store details printed on PDF documents.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global settings for PDF documents, stored in the `ofwc_settings` option.
 */
final class DocumentsTab implements TabInterface {

	public const ACTION = 'ofwc_save_documents';
	public const OPTION = 'ofwc_settings';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'ofwc_admin_tabs', array( $this, 'add_tab' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
		add_action( 'ofwc_admin_enqueue', array( $this, 'enqueue' ) );
	}

	/**
	 * Add this tab to the page.
	 *
	 * @param mixed $tabs Tabs registered so far.
	 * @return mixed
	 */
	public function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		$tabs[ $this->id() ] = $this;
		return $tabs;
	}

	/**
	 * Tab slug.
	 */
	public function id(): string {
		return 'documents';
	}

	/**
	 * Tab title.
	 */
	public function label(): string {
		return __( 'Documents', 'order-flow-for-woocommerce' );
	}

	/**
	 * Load the media library and the picker script on this tab.
	 *
	 * @param mixed $tab Active tab slug.
	 */
	public function enqueue( $tab ): void {
		if ( $this->id() !== $tab ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'ofwc-documents', OFWC_URL . 'resources/documents.js', array( 'jquery' ), OFWC_VERSION, true );
		wp_localize_script(
			'ofwc-documents',
			'ofwcDocuments',
			array(
				'title'  => __( 'Choose a logo', 'order-flow-for-woocommerce' ),
				'button' => __( 'Use this image', 'order-flow-for-woocommerce' ),
			)
		);
	}

	/**
	 * Render the form.
	 */
	public function render(): void {
		$settings = get_option( self::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$logo_id  = Value::int( $settings['logo_id'] ?? 0 );
		$details  = Value::string( $settings['store_details'] ?? '' );
		$logo_src = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'medium' ) : false;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ofwc-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );

		echo '<p>' . esc_html__( 'These settings apply to every PDF document. Enable documents per status on the Statuses tab.', 'order-flow-for-woocommerce' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Logo', 'order-flow-for-woocommerce' ) . '</th><td>';
		printf( '<input type="hidden" name="logo_id" id="ofwc-logo-id" value="%s">', esc_attr( (string) $logo_id ) );
		printf(
			'<p><img id="ofwc-logo-preview" class="ofwc-logo-preview" src="%1$s" alt=""%2$s></p>',
			esc_url( is_string( $logo_src ) ? $logo_src : '' ),
			is_string( $logo_src ) ? '' : ' hidden'
		);
		echo '<button type="button" class="button" id="ofwc-logo-pick">' . esc_html__( 'Choose image', 'order-flow-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button-link" id="ofwc-logo-clear">' . esc_html__( 'Remove', 'order-flow-for-woocommerce' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'PNG, JPG or GIF up to 2 MB. When empty, the WooCommerce email header image is used if it is stored on this site.', 'order-flow-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ofwc-store-details">' . esc_html__( 'Store details', 'order-flow-for-woocommerce' ) . '</label></th><td>';
		printf( '<textarea name="store_details" id="ofwc-store-details" rows="6" class="large-text">%s</textarea>', esc_textarea( $details ) );
		echo '<p class="description">' . esc_html__( 'Printed in the document header: company name, address, VAT number, contact. Leave empty to use the site name and the WooCommerce store address.', 'order-flow-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Preview: open any order that has a document and use the Order Flow documents box, or download it from the order list. The document title and text come from the status settings.', 'order-flow-for-woocommerce' ) . '</p>';
		submit_button( __( 'Save settings', 'order-flow-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * Save the settings.
	 */
	public function handle_save(): void {
		Page::authorize();
		check_admin_referer( self::ACTION );

		$logo_id = isset( $_POST['logo_id'] ) && is_string( $_POST['logo_id'] ) ? absint( wp_unslash( $_POST['logo_id'] ) ) : 0;
		if ( $logo_id > 0 && ! wp_attachment_is_image( $logo_id ) ) {
			$logo_id = 0;
		}
		$details = isset( $_POST['store_details'] ) && is_string( $_POST['store_details'] ) ? wp_unslash( $_POST['store_details'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Length is checked, then the text is cleaned with wp_kses_post() below.
		if ( mb_strlen( $details ) > Repository::MAX_LONG_TEXT ) {
			Page::flash(
				'error',
				sprintf(
					/* translators: %s: maximum number of characters. */
					__( '"Store details" is too long. The maximum is %s characters.', 'order-flow-for-woocommerce' ),
					number_format_i18n( Repository::MAX_LONG_TEXT )
				)
			);
			Page::redirect( $this->id() );
			return;
		}
		$details = wp_kses_post( $details );

		$settings = get_option( self::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		update_option(
			self::OPTION,
			array_merge(
				$settings,
				array(
					'logo_id'       => $logo_id,
					'store_details' => $details,
				)
			)
		);

		Page::flash( 'success', __( 'Document settings saved.', 'order-flow-for-woocommerce' ) );
		Page::redirect( $this->id() );
	}
}
