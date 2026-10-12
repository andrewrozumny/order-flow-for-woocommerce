<?php
/**
 * Import / Export tab.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Config\Exporter;
use OrderFlowWC\Config\Importer;
use OrderFlowWC\Config\ImportResult;
use OrderFlowWC\Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves the configuration (statuses, transition rules, store details) between sites.
 * Both actions go through admin-post.php with a nonce and the Order Flow configuration capability (see Page::capability()).
 */
final class ImportExport implements TabInterface {

	public const ACTION_EXPORT = 'ofwc_export';
	public const ACTION_IMPORT = 'ofwc_import';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'ofwc_admin_tabs', array( $this, 'add_tab' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT, array( $this, 'handle_import' ) );
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
		return 'import-export';
	}

	/**
	 * Tab title.
	 */
	public function label(): string {
		return __( 'Import / Export', 'order-flow-for-woocommerce' );
	}

	/**
	 * Render the tab.
	 */
	public function render(): void {
		echo '<h2>' . esc_html__( 'Export', 'order-flow-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Download your statuses, transition rules and store details as a JSON file. Use it as a backup or to set up another store. The logo and orders are not included.', 'order-flow-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_EXPORT, '_ofwc_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT ) . '">';
		submit_button( __( 'Download export file', 'order-flow-for-woocommerce' ), 'secondary', 'ofwc_export_submit', false );
		echo '</form>';

		echo '<hr><h2>' . esc_html__( 'Import', 'order-flow-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload an Order Flow export file (JSON, up to 1 MB) or paste its content.', 'order-flow-for-woocommerce' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_IMPORT, '_ofwc_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_IMPORT ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ofwc-import-file">' . esc_html__( 'File', 'order-flow-for-woocommerce' ) . '</label></th><td><input type="file" id="ofwc-import-file" name="ofwc_file" accept=".json,application/json"></td></tr>';
		echo '<tr><th scope="row"><label for="ofwc-import-text">' . esc_html__( 'Or paste JSON', 'order-flow-for-woocommerce' ) . '</label></th><td><textarea id="ofwc-import-text" name="ofwc_json" rows="8" class="large-text code" spellcheck="false"></textarea></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Mode', 'order-flow-for-woocommerce' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="mode" value="' . esc_attr( Importer::MODE_MERGE ) . '" checked> <strong>' . esc_html__( 'Merge', 'order-flow-for-woocommerce' ) . '</strong></label><br><span class="description">' . esc_html__( 'Add new statuses and update existing ones with the same slug. Nothing is removed.', 'order-flow-for-woocommerce' ) . '</span><br>';
		echo '<label><input type="radio" name="mode" value="' . esc_attr( Importer::MODE_REPLACE ) . '"> <strong>' . esc_html__( 'Replace', 'order-flow-for-woocommerce' ) . '</strong></label><br><span class="description">' . esc_html__( 'Like merge, and statuses that are not in the file are removed, but only when no order is in them. Transition rules are replaced by the file\'s rules.', 'order-flow-for-woocommerce' ) . '</span>';
		echo '</fieldset></td></tr></tbody></table>';
		submit_button( __( 'Import', 'order-flow-for-woocommerce' ), 'primary', 'ofwc_import_submit', false );
		echo '</form>';
	}

	/**
	 * Send the export file.
	 */
	public function handle_export(): void {
		$this->authorize( self::ACTION_EXPORT );

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = Exporter::filename( is_string( $host ) ? $host : '', gmdate( 'Y-m-d' ) );
		$json = ( new Exporter() )->to_json();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	/**
	 * Import from the upload or the textarea.
	 */
	public function handle_import(): void {
		$this->authorize( self::ACTION_IMPORT );

		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : Importer::MODE_MERGE; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$json = $this->read_input();
		if ( is_wp_error( $json ) ) {
			Page::flash( 'error', $json->get_error_message() );
			Page::redirect( $this->id() );
			return;
		}

		$result = ( new Importer() )->import_json( $json, $mode );
		if ( is_wp_error( $result ) ) {
			Page::flash( 'error', $result->get_error_message() );
			Page::redirect( $this->id() );
			return;
		}

		Page::flash( $this->has_problems( $result ) ? 'error' : 'success', __( 'Import finished.', 'order-flow-for-woocommerce' ) . ' ' . $result->summary() );
		Page::redirect( $this->id() );
	}

	/**
	 * Whether the notice should look like a warning: nothing was imported at all.
	 *
	 * @param ImportResult $result Import outcome.
	 */
	private function has_problems( ImportResult $result ): bool {
		return array() !== $result->skipped && array() === $result->added && array() === $result->updated && array() === $result->unchanged;
	}

	/**
	 * Capability and nonce check.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( string $action ): void {
		Page::authorize();
		check_admin_referer( $action, '_ofwc_nonce' );
	}

	/**
	 * Read the JSON from the upload or the textarea.
	 *
	 * @return string|\WP_Error
	 */
	private function read_input() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$file  = isset( $_FILES['ofwc_file'] ) && is_array( $_FILES['ofwc_file'] ) ? $_FILES['ofwc_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the temporary path and size are used.
		$error = UPLOAD_ERR_NO_FILE;
		if ( isset( $file['error'] ) ) {
			$error = is_int( $file['error'] ) ? $file['error'] : UPLOAD_ERR_PARTIAL;
		}
		if ( UPLOAD_ERR_NO_FILE !== $error ) {
			if ( UPLOAD_ERR_OK !== $error ) {
				return new \WP_Error( 'ofwc_import_upload', __( 'The file could not be uploaded.', 'order-flow-for-woocommerce' ) );
			}
			$path = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
			if ( '' === $path || ! is_uploaded_file( $path ) ) {
				return new \WP_Error( 'ofwc_import_upload', __( 'The file could not be uploaded.', 'order-flow-for-woocommerce' ) );
			}
			if ( Value::int( $file['size'] ?? 0 ) > Importer::MAX_BYTES ) {
				return new \WP_Error( 'ofwc_import_size', __( 'The file is larger than 1 MB.', 'order-flow-for-woocommerce' ) );
			}
			$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an uploaded temporary file.
			return is_string( $content ) ? $content : new \WP_Error( 'ofwc_import_upload', __( 'The file could not be read.', 'order-flow-for-woocommerce' ) );
		}

		$text = isset( $_POST['ofwc_json'] ) && is_string( $_POST['ofwc_json'] ) ? trim( wp_unslash( $_POST['ofwc_json'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- JSON is parsed and every field sanitized by the importer.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $text ) {
			return new \WP_Error( 'ofwc_import_empty', __( 'Choose a file or paste the JSON first.', 'order-flow-for-woocommerce' ) );
		}
		return $text;
	}
}
